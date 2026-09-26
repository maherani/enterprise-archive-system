<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class AutoTagService {
    public function __construct(
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private IUserManager $userManager,
        private IDBConnection $db,
        private TagOwnershipService $tagOwnershipService,
        private LoggerInterface $logger,
        private ?IRootFolder $rootFolder = null,
    ) {
    }

    /**
     * Get or create a restricted system tag (userVisible = true, userAssignable = false).
     * Normal users can see and filter by this tag, but CANNOT remove or edit it.
     */
    public function getOrCreateRestrictedTag(string $name): ISystemTag {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty');
        }

        $cleanSearch = mb_strtolower($name, 'UTF-8');
        $allTags = $this->tagManager->getAllTags();
        foreach ($allTags as $tag) {
            $tagName = trim($tag->getName());
            if (mb_strtolower($tagName, 'UTF-8') === $cleanSearch || strcasecmp($tagName, $name) === 0) {
                return $tag;
            }
        }

        try {
            // Create restricted tag: userVisible=true, userAssignable=false
            $tag = $this->tagManager->createTag($name, true, false);
            $this->tagOwnershipService->setTagOwner((int)$tag->getId(), 'system');
            $this->logger->info("archive_autotag: Created restricted system tag '{$name}' (ID: {$tag->getId()})");
            return $tag;
        } catch (\OCP\SystemTag\TagAlreadyExistsException $e) {
            // Tag already exists under slightly different casing/normalization, retrieve and return it
            $allTags = $this->tagManager->getAllTags();
            foreach ($allTags as $t) {
                $tName = trim($t->getName());
                if (mb_strtolower($tName, 'UTF-8') === $cleanSearch || strcasecmp($tName, $name) === 0) {
                    return $t;
                }
            }
            // Fallback prefix or substring match
            foreach ($allTags as $t) {
                $tName = trim($t->getName());
                if (str_starts_with($name, $tName) || str_starts_with($tName, $name)) {
                    return $t;
                }
            }
            throw $e;
        }
    }

    /**
     * Find existing tag by name (case-insensitive).
     */
    public function findTagByName(string $name): ?ISystemTag {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $allTags = $this->tagManager->getAllTags();
        foreach ($allTags as $tag) {
            if (strcasecmp($tag->getName(), $name) === 0) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * Extract hierarchical ancestor folder names in top-down order.
     * Skips Nextcloud internal root folders ('files', 'cache', 'appdata_*') and usernames.
     *
     * Example: For /Enterprise_Archive/Finance/2026/Invoices/doc.pdf
     * Returns: ['Enterprise_Archive', 'Finance', '2026', 'Invoices']
     */
    /**
     * Retrieve any group names associated via Nextcloud group shares (share_type = 1)
     * on this node or its parent/ancestor folders.
     */
    public function getAssociatedGroupNames(Node $node): array {
        $checkIds = [(int)$node->getId()];
        $curr = $node->getParent();
        while ($curr !== null) {
            $name = $curr->getName();
            if ($name === '' || $name === 'files' || $curr->getParent() === null) {
                break;
            }
            $checkIds[] = (int)$curr->getId();
            $curr = $curr->getParent();
        }

        $groupNames = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('share_with')
               ->from('share')
               ->where($qb->expr()->in('item_source', $qb->createNamedParameter(array_map('strval', $checkIds), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
               ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(1))); // IShare::TYPE_GROUP
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $grp = trim((string)$row['share_with']);
                if ($grp !== '') {
                    $groupNames[] = $grp;
                }
            }
        } catch (\Throwable $t) {
            $this->logger->warning("AutoTagService: Failed to query group shares for node: " . $t->getMessage());
        }

        return array_values(array_unique($groupNames));
    }

    public function getAncestorFolderNames(Node $node): array {
        $canonicalNode = $node;
        try {
            $nodeId = (int)$node->getId();
            if ($nodeId > 0 && $this->rootFolder !== null) {
                $candidates = $this->rootFolder->getById($nodeId);
                foreach ($candidates as $cand) {
                    if (str_contains($cand->getPath(), 'Enterprise_Archive')) {
                        $canonicalNode = $cand;
                        break;
                    }
                }
                if ($canonicalNode === $node && !empty($candidates)) {
                    $canonicalNode = $candidates[0];
                }
            }
        } catch (\Throwable $t) {
            $canonicalNode = $node;
        }

        $ancestors = [];
        $current = $canonicalNode->getParent();

        while ($current !== null) {
            $name = $current->getName();

            // Nextcloud storage root for user files is named 'files' or empty string
            if ($name === '' || $name === 'files' || $current->getParent() === null) {
                break;
            }

            // Exclude system directories and usernames
            if (str_starts_with($name, 'appdata_') || $this->userManager->userExists($name)) {
                break;
            }

            $ancestors[] = $name;
            $current = $current->getParent();
        }

        // Return top-down hierarchy (e.g. Enterprise_Archive -> Finance -> 2026 -> Invoices)
        $result = array_values(array_unique(array_reverse($ancestors)));

        // If Enterprise_Archive was missed (e.g. recipient shared mount points where root was truncated),
        // query canonical path directly in filecache
        if (!in_array('Enterprise_Archive', $result, true)) {
            try {
                $nodeId = (int)$node->getId();
                if ($nodeId > 0) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('path')
                       ->from('filecache')
                       ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($nodeId)));
                    $row = $qb->executeQuery()->fetchAssociative();
                    if ($row && !empty($row['path'])) {
                        $segments = explode('/', trim((string)$row['path'], '/'));
                        // Remove filename (last segment if this is a file)
                        if ($node instanceof File || ($node instanceof Node && !$node instanceof Folder)) {
                            array_pop($segments);
                        }
                        $canonicalAncestors = [];
                        foreach ($segments as $seg) {
                            $seg = trim($seg);
                            if ($seg === '' || $seg === 'files' || str_starts_with($seg, 'appdata_') || $seg === 'cache' || $this->userManager->userExists($seg)) {
                                continue;
                            }
                            $canonicalAncestors[] = $seg;
                        }
                        if (!empty($canonicalAncestors) && in_array('Enterprise_Archive', $canonicalAncestors, true)) {
                            return array_values(array_unique($canonicalAncestors));
                        }
                    }
                }
            } catch (\Throwable $t) {
                // Keep existing result if query fails
            }
        }

        return $result;
    }

    /**
     * Automatically tag a file or folder with all ancestor folder names.
     */
    public function tagNodeHierarchy(Node $node): void {
        try {
            $nodeName = $node->getName();

            // Skip internal Nextcloud folders and cache
            if ($nodeName === '' || $nodeName === 'files' || str_starts_with($nodeName, 'appdata_') || $nodeName === 'cache') {
                return;
            }

            $ancestorNames = $this->getAncestorFolderNames($node);
            // Strict folder hierarchy tags: do not convert internal technical share GIDs (like 'SOC' or 'admin') into tags
            $allTagNames = array_values(array_unique($ancestorNames));
            if (empty($allTagNames)) {
                return;
            }

            $tagIdsToAssign = [];
            foreach ($allTagNames as $tagName) {
                try {
                    $tag = $this->getOrCreateRestrictedTag($tagName);
                    $tagIdsToAssign[] = (string)$tag->getId();
                } catch (\Throwable $t) {
                    $this->logger->warning("archive_autotag: Could not resolve tag '{$tagName}': " . $t->getMessage());
                }
            }

            $objectId = (string)$node->getId();
            $existing = $this->tagMapper->getTagIdsForObjects([$objectId], 'files');
            $existingTagIds = $existing[$objectId] ?? [];

            $newTagIds = array_diff($tagIdsToAssign, $existingTagIds);
            if (!empty($newTagIds)) {
                $this->tagMapper->assignTags($objectId, 'files', array_values($newTagIds));
                $this->logger->info("archive_autotag: Assigned ancestor tags [" . implode(', ', $ancestorNames) . "] to node '{$nodeName}' (ID: {$objectId})");
            }
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Error tagging node {$node->getId()}: " . $e->getMessage());
        }
    }

    /**
     * When a folder is created: ensure tag exists, tag hierarchy, and reconcile.
     */
    public function handleFolderCreated(Node $folder): void {
        try {
            $name = $folder->getName();
            if ($name === '' || $name === 'files' || str_starts_with($name, 'appdata_') || $name === 'cache') {
                return;
            }

            // Create restricted tag for this new folder
            $this->getOrCreateRestrictedTag($name);

            // Tag folder with ancestor hierarchy
            $this->tagNodeHierarchy($folder);

            // Reconcile all tags
            $this->reconcileAllTags();
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Error handling folder created: " . $e->getMessage());
        }
    }

    /**
     * Propagate folder rename to all descendant files and subfolders:
     * - In-place tag rename if old tag was uniquely used by this folder.
     * - Otherwise, replace old tag with new tag across descendants.
     * - Reconcile all tags and prune any obsolete tags.
     */
    public function handleFolderRenamed(Folder $targetFolder, string $oldName, string $newName): void {
        try {
            if ($oldName === $newName || trim($oldName) === '' || trim($newName) === '') {
                return;
            }

            $oldTag = $this->findTagByName($oldName);
            $newTag = $this->findTagByName($newName);

            // Check how many active folders in the filesystem still use $oldName
            $sqlCountOld = "
                SELECT COUNT(fileid)
                FROM oc_filecache
                WHERE mimetype = 2
                  AND path LIKE 'files/%'
                  AND path NOT LIKE 'files_trashbin%'
                  AND name = ?
            ";
            $countOldName = (int)$this->db->executeQuery($sqlCountOld, [$oldName])->fetchOne();

            // If no other folder has oldName and new tag does not exist yet: rename tag in-place!
            if ($countOldName === 0 && $oldTag !== null && $newTag === null) {
                $this->tagManager->updateTag((string)$oldTag->getId(), $newName, true, false, null);
                $this->logger->info("archive_autotag: In-place renamed tag ID {$oldTag->getId()} from '{$oldName}' to '{$newName}'");
                $newTagId = (string)$oldTag->getId();
                $oldTagId = null; // No unassign needed since tag itself was renamed
            } else {
                if ($newTag === null) {
                    $newTag = $this->getOrCreateRestrictedTag($newName);
                }
                $oldTagId = $oldTag ? (string)$oldTag->getId() : null;
                $newTagId = (string)$newTag->getId();
                $this->propagateRenameRecursive($targetFolder, $oldTagId, $newTagId);
            }

            // Always run full reconciliation after rename
            $this->reconcileAllTags();
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Error handling folder rename: " . $e->getMessage());
        }
    }

    /**
     * Recursively update tags across all descendant files and subfolders.
     */
    private function propagateRenameRecursive(Folder $folder, ?string $oldTagId, string $newTagId): void {
        $nodes = $folder->getDirectoryListing();
        foreach ($nodes as $node) {
            $objectId = (string)$node->getId();

            // 1. Unassign old tag if present
            if ($oldTagId !== null) {
                try {
                    $this->tagMapper->unassignTags($objectId, 'files', [$oldTagId]);
                } catch (\Throwable $t) {
                }
            }

            // 2. Assign new tag
            try {
                $this->tagMapper->assignTags($objectId, 'files', [$newTagId]);
            } catch (\Throwable $t) {
            }

            // 3. Recurse into subfolders
            if ($node instanceof Folder) {
                $this->propagateRenameRecursive($node, $oldTagId, $newTagId);
            }
        }
    }

    /**
     * When a folder is deleted: run full tag reconciliation and prune surplus tags.
     */
    public function handleFolderDeleted(Node $node): void {
        try {
            $this->logger->info("archive_autotag: Folder deleted: {$node->getName()}. Running tag reconciliation...");
            $this->reconcileAllTags();
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Error handling folder deleted: " . $e->getMessage());
        }
    }

    /**
     * Remove dead/stale mappings in oc_systemtag_object_mapping
     * (e.g. files in trashbin or files deleted from oc_filecache).
     */
    public function cleanupStaleMappings(): int {
        try {
            // Delete mappings for trashbin files
            $sqlTrash = "
                DELETE FROM oc_systemtag_object_mapping
                WHERE objecttype = 'files'
                  AND objectid IN (
                      SELECT fileid::text FROM oc_filecache WHERE path LIKE 'files_trashbin%'
                  )
            ";
            $trashCount = (int)$this->db->executeStatement($sqlTrash);

            // Delete mappings for files completely deleted from oc_filecache
            $sqlDeleted = "
                DELETE FROM oc_systemtag_object_mapping
                WHERE objecttype = 'files'
                  AND NOT EXISTS (
                      SELECT 1 FROM oc_filecache f WHERE f.fileid::text = oc_systemtag_object_mapping.objectid
                  )
            ";
            $delCount = (int)$this->db->executeStatement($sqlDeleted);

            $total = $trashCount + $delCount;
            if ($total > 0) {
                $this->logger->info("archive_autotag: Cleaned up {$total} stale tag mappings ({$trashCount} trashbin, {$delCount} deleted).");
            }
            return $total;
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Error cleaning stale mappings: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Scan active folders in oc_filecache and extract all valid category/folder names.
     */
    public function getActiveFolderCategoryNames(): array {
        try {
            $sql = "
                SELECT path, name
                FROM oc_filecache
                WHERE mimetype = 2
                  AND path LIKE 'files/%'
                  AND path NOT LIKE 'files_trashbin%'
                  AND path NOT LIKE 'files_versions%'
                  AND path NOT LIKE '%/cache/%'
                  AND path NOT LIKE '%/appdata_%'
            ";
            $rows = $this->db->executeQuery($sql)->fetchAllAssociative();
            $categoryNames = [];

            foreach ($rows as $row) {
                $path = trim((string)$row['path'], '/');
                $segments = explode('/', $path);
                foreach ($segments as $segment) {
                    $segment = trim($segment);
                    if ($segment === '' || $segment === 'files' || str_starts_with($segment, 'appdata_') || $segment === 'cache') {
                        continue;
                    }
                    if ($this->userManager->userExists($segment)) {
                        continue;
                    }
                    $categoryNames[mb_strtolower($segment)] = $segment;
                }
            }
            return $categoryNames;
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Error querying active folders: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Comprehensive Tag Review and Reconciliation Engine:
     * 1. Prunes dead/trash mappings from oc_systemtag_object_mapping.
     * 2. Detects all active archive folders and registers/ensures their system tags.
     * 3. Retags any untagged active files.
     * 4. Detects and deletes surplus/orphaned tags (tags with 0 active folders and 0 active files).
     */
    public function reconcileAllTags(): array {
        $report = [
            'stale_mappings_cleaned' => 0,
            'surplus_tags_deleted' => [],
            'active_folder_tags' => [],
            'tags_retained' => [],
        ];

        try {
            // Step 1: Clean stale mappings
            $report['stale_mappings_cleaned'] = $this->cleanupStaleMappings();

            // Step 2: Get active folder category names
            $activeFolderNames = $this->getActiveFolderCategoryNames();
            $report['active_folder_tags'] = array_values($activeFolderNames);

            // Step 3: Ensure restricted system tags exist for all active folders
            foreach ($activeFolderNames as $folderName) {
                try {
                    $tag = $this->getOrCreateRestrictedTag($folderName);
                    $this->tagOwnershipService->setTagOwner((int)$tag->getId(), 'system');
                } catch (\Throwable $t) {
                }
            }

            // Step 4: Evaluate all tags for surplus/orphan status
            $allTags = $this->tagManager->getAllTags();
            $tagsToDelete = [];

            $sqlCheckMappings = "
                SELECT COUNT(m.objectid)
                FROM oc_systemtag_object_mapping m
                JOIN oc_filecache f ON m.objectid = f.fileid::text
                WHERE m.systemtagid = ? AND m.objecttype = 'files'
                  AND f.path LIKE 'files/%'
                  AND f.path NOT LIKE 'files_trashbin%'
            ";

            foreach ($allTags as $tag) {
                $tagId = (int)$tag->getId();
                $tagName = $tag->getName();
                $lowerName = mb_strtolower(trim($tagName));

                $isFolder = isset($activeFolderNames[$lowerName]);

                // Count active file mappings in oc_filecache using raw SQL to prevent Doctrine casting error
                $activeMappingsCount = (int)$this->db->executeQuery($sqlCheckMappings, [$tagId])->fetchOne();

                // Protect group admin custom tags from being pruned
                $owner = $this->tagOwnershipService->getTagOwner($tagId);
                $isGroupAdminTag = !empty($this->tagOwnershipService->getTagGroups($tagId)) && $owner !== null && $owner !== 'system';

                if ($isFolder) {
                    $report['tags_retained'][] = [
                        'id' => $tagId,
                        'name' => $tagName,
                        'reason' => 'active_folder',
                        'active_mappings' => $activeMappingsCount,
                    ];
                } elseif ($isGroupAdminTag) {
                    $report['tags_retained'][] = [
                        'id' => $tagId,
                        'name' => $tagName,
                        'reason' => 'group_admin_tag',
                        'active_mappings' => $activeMappingsCount,
                    ];
                } elseif ($activeMappingsCount > 0) {
                    $report['tags_retained'][] = [
                        'id' => $tagId,
                        'name' => $tagName,
                        'reason' => 'active_file_mappings',
                        'active_mappings' => $activeMappingsCount,
                    ];
                } else {
                    // Surplus/Orphan: no active folder and 0 active files
                    $tagsToDelete[] = $tagId;
                    $report['surplus_tags_deleted'][] = [
                        'id' => $tagId,
                        'name' => $tagName,
                    ];
                }
            }

            // Step 5: Delete all surplus tags
            if (!empty($tagsToDelete)) {
                $this->tagManager->deleteTags($tagsToDelete);
                foreach ($tagsToDelete as $tid) {
                    $this->tagOwnershipService->deleteTagOwner($tid);
                }
                $this->logger->info("archive_autotag: Pruned " . count($tagsToDelete) . " surplus orphaned tags: " . json_encode($report['surplus_tags_deleted']));
            }

            return $report;
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Reconcile error: " . $e->getMessage());
            return $report;
        }
    }

    /**
     * Recursively scan a folder and retag all descendant files with their hierarchy.
     */
    public function retagAllRecursive(Folder $folder): int {
        $count = 0;
        $nodes = $folder->getDirectoryListing();
        foreach ($nodes as $node) {
            $this->tagNodeHierarchy($node);
            $count++;
            if ($node instanceof Folder) {
                $count += $this->retagAllRecursive($node);
            }
        }
        return $count;
    }
}
