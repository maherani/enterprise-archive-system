<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\File;
use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

class AutoTagService {
    private ISystemTagManager $tagManager;
    private ISystemTagObjectMapper $tagMapper;
    private IUserManager $userManager;
    private LoggerInterface $logger;

    public function __construct(
        ISystemTagManager $tagManager,
        ISystemTagObjectMapper $tagMapper,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        $this->tagManager = $tagManager;
        $this->tagMapper = $tagMapper;
        $this->userManager = $userManager;
        $this->logger = $logger;
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

        $allTags = $this->tagManager->getAllTags();
        foreach ($allTags as $tag) {
            if (strcasecmp($tag->getName(), $name) === 0) {
                return $tag;
            }
        }

        // Create restricted tag: userVisible=true, userAssignable=false
        $tag = $this->tagManager->createTag($name, true, false);
        $this->logger->info("archive_autotag: Created restricted system tag '{$name}' (ID: {$tag->getId()})");
        return $tag;
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
    public function getAncestorFolderNames(Node $node): array {
        $ancestors = [];
        $current = $node->getParent();

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
        return array_values(array_unique(array_reverse($ancestors)));
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
            if (empty($ancestorNames)) {
                return;
            }

            $tagIdsToAssign = [];
            foreach ($ancestorNames as $tagName) {
                $tag = $this->getOrCreateRestrictedTag($tagName);
                $tagIdsToAssign[] = (string)$tag->getId();
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
     * Propagate folder rename to all descendant files and subfolders:
     * Removes the old folder name tag and assigns the new folder name tag.
     */
    public function handleFolderRenamed(Folder $targetFolder, string $oldName, string $newName): void {
        try {
            if ($oldName === $newName || trim($oldName) === '' || trim($newName) === '') {
                return;
            }

            $oldTag = $this->findTagByName($oldName);
            $newTag = $this->getOrCreateRestrictedTag($newName);

            $oldTagId = $oldTag ? (string)$oldTag->getId() : null;
            $newTagId = (string)$newTag->getId();

            $this->logger->info("archive_autotag: Propagating rename from '{$oldName}' to '{$newName}' inside folder '{$targetFolder->getName()}'");
            $this->propagateRenameRecursive($targetFolder, $oldTagId, $newTagId);
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
                    // Ignore if tag wasn't previously mapped
                }
            }

            // 2. Assign new tag
            try {
                $this->tagMapper->assignTags($objectId, 'files', [$newTagId]);
            } catch (\Throwable $t) {
                // Ignore if already mapped
            }

            // 3. Recurse into subfolders
            if ($node instanceof Folder) {
                $this->propagateRenameRecursive($node, $oldTagId, $newTagId);
            }
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
