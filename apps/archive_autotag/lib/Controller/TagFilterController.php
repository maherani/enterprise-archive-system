<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Service\DocumentMetadataService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCA\ArchiveAutoTag\Service\TagOwnershipService;
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
        private TagOwnershipService $tagOwnershipService,
        private FileOwnershipService $fileOwnershipService,
        private DocumentMetadataService $documentMetadataService,
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
            $visibleTagIds = $this->tagOwnershipService->getVisibleTagIds($user->getUID());
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
                if (!in_array($tagId, $visibleTagIds, true)) {
                    continue;
                }
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
     * List all accessible archive files (initial portal load).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listAllArchiveFiles(): DataResponse {
        return $this->filterByTags('all');
    }

    /**
     * Get directory listing for a specific path for the custom enterprise archive table.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listFolderFiles(?string $dir = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();
        $rawDir = trim((string)(
            $dir
            ?? $this->request->getParam('dir', null)
            ?? ($_GET['dir'] ?? '')
        ));
        $cleanDir = trim($rawDir, '/');

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $userFolderPath = $userFolder->getPath();

            $targetNode = ($cleanDir === '' || $cleanDir === '.') ? $userFolder : $userFolder->get($cleanDir);

            if ($targetNode->getType() !== FileInfo::TYPE_FOLDER) {
                return new DataResponse(['status' => 'error', 'message' => 'Path is not a directory'], Http::STATUS_BAD_REQUEST);
            }

            /** @var \OCP\Files\Folder $targetNode */
            $listing = $targetNode->getDirectoryListing();
            $filesResult = [];

            foreach ($listing as $node) {
                $fileId = (int)$node->getId();
                if (!$this->fileOwnershipService->canUserAccessFile($fileId, $uid)) {
                    continue;
                }

                $fullPath = $node->getPath();
                $relPath = $fullPath;
                if (str_starts_with($fullPath, $userFolderPath)) {
                    $relPath = ltrim(substr($fullPath, strlen($userFolderPath)), '/');
                }

                $isDir = $node->getType() === FileInfo::TYPE_FOLDER;
                $parentDir = dirname($relPath);
                if ($parentDir === '.') {
                    $parentDir = '';
                }

                $targetDir = '/' . ltrim($isDir ? $relPath : $parentDir, '/');
                $targetDir = preg_replace('#/+#', '/', $targetDir);

                $encodedTargetDir = str_replace('%2F', '/', rawurlencode($targetDir));
                $folderUrl = '/index.php/apps/files/files?dir=' . $encodedTargetDir;
                $webUrl = $folderUrl;

                $filesResult[] = [
                    'id' => $fileId,
                    'name' => $node->getName(),
                    'path' => $relPath,
                    'parent_dir' => $parentDir,
                    'target_dir' => $targetDir,
                    'size' => $node->getSize(),
                    'human_size' => $this->formatBytes($node->getSize()),
                    'mimetype' => $node->getMimetype(),
                    'mtime' => $node->getMTime(),
                    'type' => $isDir ? 'folder' : 'file',
                    'is_dir' => $isDir,
                    'web_url' => $webUrl,
                    'folder_url' => $folderUrl,
                    'download_url' => '/remote.php/webdav/' . str_replace('%2F', '/', rawurlencode($relPath)),
                ];
            }

            // Bulk fetch metadata and tags for folder items
            $fileIds = array_map(fn($f) => (int)$f['id'], $filesResult);
            $metaMap = $this->documentMetadataService->getMetadataByFileIds($fileIds);

            $tagMappings = $this->tagMapper->getTagIdsForObjects($fileIds, 'files');
            $allTags = $this->tagManager->getAllTags(true);
            $visibleTagIds = $this->tagOwnershipService->getVisibleTagIds($uid);
            
            $tagMapById = [];
            foreach ($allTags as $t) {
                $tId = (int)$t->getId();
                if (in_array($tId, $visibleTagIds, true)) {
                    $tagMapById[$tId] = $t;
                }
            }

            foreach ($filesResult as &$item) {
                $fId = $item['id'];
                $item['metadata'] = $metaMap[$fId] ?? null;
                
                $fileTags = [];
                $fileTagIds = $tagMappings[$fId] ?? [];
                foreach ($fileTagIds as $tid) {
                    $tidInt = (int)$tid;
                    if (isset($tagMapById[$tidInt]) && $this->tagOwnershipService->canUserSeeTag($tidInt, $uid)) {
                        $fileTags[] = [
                            'id' => $tidInt,
                            'name' => $tagMapById[$tidInt]->getName(),
                        ];
                    }
                }
                $item['tags'] = $fileTags;
            }
            unset($item);

            // Sort: folders first, then files alphabetically
            usort($filesResult, function($a, $b) {
                if ($a['is_dir'] !== $b['is_dir']) {
                    return $a['is_dir'] ? -1 : 1;
                }
                return strnatcasecmp($a['name'], $b['name']);
            });

            return new DataResponse([
                'status' => 'success',
                'dir' => '/' . $cleanDir,
                'files' => $filesResult,
                'total' => count($filesResult),
            ]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Could not list folder: ' . $e->getMessage()
            ], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Perform strict multi-tag intersection filtering with ACL enforcement.
     * Supports ?tags=tag1,tag2 and ?q=searchTerm
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function filterByTags(?string $tags = null, ?string $tag_ids = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();
        $rawInput = trim((string)(
            $tags
            ?? $tag_ids
            ?? $this->request->getParam('tags', null)
            ?? $this->request->getParam('tag_ids', null)
            ?? ($_GET['tags'] ?? null)
            ?? ($_GET['tag_ids'] ?? '')
        ));

        $q = trim((string)($this->request->getParam('q', '') ?: ($_GET['q'] ?? '')));

        // Map names and IDs to tag objects
        $allTags = $this->tagManager->getAllTags(true);
        $visibleTagIds = $this->tagOwnershipService->getVisibleTagIds($uid);
        $tagMapByName = [];
        $tagMapById = [];
        foreach ($allTags as $t) {
            $tId = (int)$t->getId();
            if (!in_array($tId, $visibleTagIds, true)) {
                continue;
            }
            $tagMapByName[mb_strtolower($t->getName(), 'UTF-8')] = $t;
            $tagMapById[$tId] = $t;
        }

        $candidateFileIds = [];
        $selectedTagDetails = [];

        if ($rawInput === '' || $rawInput === 'all') {
            // Return all files that have at least one visible system tag
            if (!empty($visibleTagIds)) {
                $qb = $this->db->getQueryBuilder();
                $qb->selectDistinct('objectid')
                   ->from('systemtag_object_mapping')
                   ->where($qb->expr()->eq('objecttype', $qb->createNamedParameter('files')))
                   ->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($visibleTagIds, IQueryBuilder::PARAM_INT_ARRAY)))
                   ->setMaxResults(500);

                $res = $qb->executeQuery();
                while ($row = $res->fetchAssociative()) {
                    $candidateFileIds[] = (int)$row['objectid'];
                }
            }
        } else {
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

            $resolvedTagIds = [];
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
            while ($row = $res->fetchAssociative()) {
                $candidateFileIds[] = (int)$row['objectid'];
            }
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
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $userFolderPath = $userFolder->getPath();

        // Get all tag IDs and metadata for candidate files in bulk
        $tagMappings = $this->tagMapper->getTagIdsForObjects($candidateFileIds, 'files');
        $metadataMap = $this->documentMetadataService->getMetadataByFileIds($candidateFileIds);

        $filesResult = [];
        foreach ($candidateFileIds as $fileId) {
            // Strict file isolation: user can only access files they uploaded or admin granted
            if (!$this->fileOwnershipService->canUserAccessFile($fileId, $uid)) {
                continue;
            }

            $nodes = $userFolder->getById($fileId);
            if (empty($nodes)) {
                continue;
            }

            /** @var \OCP\Files\Node $node */
            $node = $nodes[0];
            $fullPath = $node->getPath();

            $relPath = $fullPath;
            if (str_starts_with($fullPath, $userFolderPath)) {
                $relPath = ltrim(substr($fullPath, strlen($userFolderPath)), '/');
            }

            $fileMeta = $metadataMap[$fileId] ?? null;
            $metaMatch = false;
            if ($fileMeta !== null) {
                $metaSubj = (string)($fileMeta['subject'] ?? '');
                $metaNum = (string)($fileMeta['document_number'] ?? '');
                $metaIss = (string)($fileMeta['issuer'] ?? '');
                $metaDesc = (string)($fileMeta['description'] ?? '');
                if (mb_stripos($metaSubj, $q) !== false || mb_stripos($metaNum, $q) !== false || mb_stripos($metaIss, $q) !== false || mb_stripos($metaDesc, $q) !== false) {
                    $metaMatch = true;
                }
            }

            // Optional keyword search filter across filename, path, and metadata
            if ($q !== '') {
                $nodeName = $node->getName();
                if (!$metaMatch && mb_stripos($nodeName, $q) === false && mb_stripos($relPath, $q) === false) {
                    continue;
                }
            }

            $parentDir = dirname($relPath);
            if ($parentDir === '.') {
                $parentDir = '';
            }

            // Build tags list for this file (only include tags visible to user)
            $fileTagIds = $tagMappings[$fileId] ?? [];
            $fileTags = [];
            foreach ($fileTagIds as $tid) {
                $tidInt = (int)$tid;
                if (isset($tagMapById[$tidInt]) && $this->tagOwnershipService->canUserSeeTag($tidInt, $uid)) {
                    $fileTags[] = [
                        'id' => $tidInt,
                        'name' => $tagMapById[$tidInt]->getName(),
                    ];
                }
            }

            $isDir = $node->getType() === FileInfo::TYPE_FOLDER;
            $targetDir = '/' . ltrim($isDir ? $relPath : $parentDir, '/');
            $targetDir = preg_replace('#/+#', '/', $targetDir);

            // Canonical Nextcloud Files directory navigation link
            $encodedTargetDir = str_replace('%2F', '/', rawurlencode($targetDir));
            $folderUrl = '/index.php/apps/files/files?dir=' . $encodedTargetDir;
            $webUrl = $folderUrl;

            $filesResult[] = [
                'id' => $fileId,
                'name' => $node->getName(),
                'path' => $relPath,
                'parent_dir' => $parentDir,
                'target_dir' => $targetDir,
                'size' => $node->getSize(),
                'human_size' => $this->formatBytes($node->getSize()),
                'mimetype' => $node->getMimetype(),
                'mtime' => $node->getMTime(),
                'type' => $isDir ? 'folder' : 'file',
                'is_dir' => $isDir,
                'tags' => $fileTags,
                'metadata' => $fileMeta,
                'web_url' => $webUrl,
                'folder_url' => $folderUrl,
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
