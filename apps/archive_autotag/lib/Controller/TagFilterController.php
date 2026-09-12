<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

class TagFilterController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IDBConnection $db,
        private IRootFolder $rootFolder,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all system tags visible to the user with file usage counts.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listVisibleTags(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $tags = $this->tagManager->getAllTags(true);

            // Fetch file count per tag
            $qb = $this->db->getQueryBuilder();
            $qb->select('systemtagid', $qb->createFunction('COUNT(DISTINCT objectid) as file_count'))
               ->from('systemtag_object_mapping')
               ->where($qb->expr()->eq('objecttype', $qb->createNamedParameter('files')))
               ->groupBy('systemtagid');
            $res = $qb->executeQuery();
            $counts = [];
            while ($row = $res->fetchAssociative()) {
                $counts[(int)$row['systemtagid']] = (int)$row['file_count'];
            }

            $tagList = [];
            foreach ($tags as $tag) {
                $tagId = (int)$tag->getId();
                $tagList[] = [
                    'id' => $tagId,
                    'name' => $tag->getName(),
                    'userAssignable' => $tag->isUserAssignable(),
                    'userVisible' => $tag->isUserVisible(),
                    'count' => $counts[$tagId] ?? 0,
                ];
            }

            // Order by count descending, then name ascending
            usort($tagList, function ($a, $b) {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }
                return strcmp($a['name'], $b['name']);
            });

            return new DataResponse([
                'status' => 'success',
                'tags' => $tagList,
                'total' => count($tagList),
            ]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Perform strict multi-tag intersection filtering with ACL enforcement.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function filterByTags(?string $tags = null, ?string $tag_ids = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $rawInput = trim((string)(
            $tags
            ?? $tag_ids
            ?? $this->request->getParam('tags', null)
            ?? $this->request->getParam('tag_ids', null)
            ?? ($_GET['tags'] ?? null)
            ?? ($_GET['tag_ids'] ?? '')
        ));

        if ($rawInput === '') {
            return new DataResponse([
                'status' => 'success',
                'files' => [],
                'total' => 0,
                'selected_tags' => [],
            ]);
        }

        // Parse requested tag tokens
        $tagTokens = array_filter(array_map('trim', explode(',', $rawInput)), fn($t) => $t !== '');
        if (empty($tagTokens)) {
            return new DataResponse([
                'status' => 'success',
                'files' => [],
                'total' => 0,
                'selected_tags' => [],
            ]);
        }

        // Map names and IDs to tag objects
        $allTags = $this->tagManager->getAllTags(true);
        $tagMapByName = [];
        $tagMapById = [];
        foreach ($allTags as $t) {
            $tagMapByName[mb_strtolower($t->getName(), 'UTF-8')] = $t;
            $tagMapById[(int)$t->getId()] = $t;
        }

        $resolvedTagIds = [];
        $selectedTagDetails = [];

        foreach ($tagTokens as $token) {
            $matchedTag = null;
            if (is_numeric($token) && isset($tagMapById[(int)$token])) {
                $matchedTag = $tagMapById[(int)$token];
            } else {
                $lower = mb_strtolower($token, 'UTF-8');
                if (isset($tagMapByName[$lower])) {
                    $matchedTag = $tagMapByName[$lower];
                }
            }

            if ($matchedTag === null) {
                // Requested tag does not exist -> intersection is empty
                return new DataResponse([
                    'status' => 'success',
                    'files' => [],
                    'total' => 0,
                    'selected_tags' => [],
                ]);
            }

            $id = (int)$matchedTag->getId();
            if (!in_array($id, $resolvedTagIds, true)) {
                $resolvedTagIds[] = $id;
                $selectedTagDetails[] = [
                    'id' => $id,
                    'name' => $matchedTag->getName(),
                ];
            }
        }

        // Intersect query using HAVING COUNT(DISTINCT systemtagid) = N
        $qb = $this->db->getQueryBuilder();
        $qb->select('objectid')
           ->from('systemtag_object_mapping')
           ->where($qb->expr()->eq('objecttype', $qb->createNamedParameter('files')))
           ->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($resolvedTagIds, IQueryBuilder::PARAM_INT_ARRAY)))
           ->groupBy('objectid')
           ->having($qb->expr()->eq($qb->createFunction('COUNT(DISTINCT systemtagid)'), $qb->createNamedParameter(count($resolvedTagIds), IQueryBuilder::PARAM_INT)));

        $res = $qb->executeQuery();
        $candidateFileIds = [];
        while ($row = $res->fetchAssociative()) {
            $candidateFileIds[] = (int)$row['objectid'];
        }

        if (empty($candidateFileIds)) {
            return new DataResponse([
                'status' => 'success',
                'files' => [],
                'total' => 0,
                'selected_tags' => $selectedTagDetails,
            ]);
        }

        // Fetch user folder and enforce ACL check
        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $userFolderPath = $userFolder->getPath();

        // Get all tag IDs for candidate files in bulk
        $tagMappings = $this->tagMapper->getTagIdsForObjects($candidateFileIds, 'files');

        $filesResult = [];
        foreach ($candidateFileIds as $fileId) {
            $nodes = $userFolder->getById($fileId);
            if (empty($nodes)) {
                // User does not have read access to this file
                continue;
            }

            /** @var \OCP\Files\Node $node */
            $node = $nodes[0];
            $fullPath = $node->getPath();

            $relPath = $fullPath;
            if (str_starts_with($fullPath, $userFolderPath)) {
                $relPath = ltrim(substr($fullPath, strlen($userFolderPath)), '/');
            }

            $parentDir = dirname($relPath);
            if ($parentDir === '.') {
                $parentDir = '';
            }

            // Build tags list for this file
            $fileTagIds = $tagMappings[$fileId] ?? [];
            $fileTags = [];
            foreach ($fileTagIds as $tid) {
                $tidInt = (int)$tid;
                if (isset($tagMapById[$tidInt])) {
                    $fileTags[] = [
                        'id' => $tidInt,
                        'name' => $tagMapById[$tidInt]->getName(),
                    ];
                }
            }

            $isDir = $node->getType() === FileInfo::TYPE_FOLDER;

            $filesResult[] = [
                'id' => $fileId,
                'name' => $node->getName(),
                'path' => $relPath,
                'parent_dir' => $parentDir,
                'size' => $node->getSize(),
                'human_size' => $this->formatBytes($node->getSize()),
                'mimetype' => $node->getMimetype(),
                'mtime' => $node->getMTime(),
                'type' => $isDir ? 'folder' : 'file',
                'is_dir' => $isDir,
                'tags' => $fileTags,
                'web_url' => '/apps/files/?dir=' . urlencode('/' . $parentDir) . '&scrollto=' . urlencode($node->getName()),
                'download_url' => '/remote.php/webdav/' . str_replace('%2F', '/', rawurlencode($relPath)),
            ];
        }

        return new DataResponse([
            'status' => 'success',
            'files' => $filesResult,
            'total' => count($filesResult),
            'selected_tags' => $selectedTagDetails,
        ]);
    }

    private function formatBytes(int $bytes): string {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . ($units[$i] ?? 'B');
    }
}
