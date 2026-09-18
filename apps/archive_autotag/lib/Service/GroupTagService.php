<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use OCP\SystemTag\TagAlreadyExistsException;
use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use Psr\Log\LoggerInterface;

class GroupTagService {
    public function __construct(
        private IDBConnection $db,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private TagOwnershipService $tagOwnershipService,
        private FileOwnershipService $fileOwnershipService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Check whether a user is an authorized Group Admin for a given group.
     * System admins do not get implicit bypass: they must be assigned subadmin of that group.
     */
    public function isUserAdminOfGroup(string $userId, string $groupId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('gid')
           ->from('group_admin')
           ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->ilike('gid', $qb->createNamedParameter($groupId)));
        $res = $qb->executeQuery()->fetchAssociative();
        return !empty($res);
    }

    /**
     * Get all groups for which a user is Group Admin.
     */
    public function getUserAdminGroups(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('gid')
           ->from('group_admin')
           ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(fn($r) => (string)$r['gid'], $rows);
    }

    /**
     * Create a new group-specific tag.
     * Stored in oc_systemtag as "[<groupId>] <tagName>" with userVisible=true, userAssignable=true.
     */
    public function createGroupTag(string $actorUid, string $groupId, string $tagName): array {
        if (!$this->isUserAdminOfGroup($actorUid, $groupId)) {
            $this->logAuditEvent($actorUid, $groupId, 'create_tag', 0, $tagName, null, null, null, 'failure', 'User is not a group admin of this group.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not an authorized Group Admin for group '{$groupId}'.");
        }

        $trimmed = trim($tagName);
        if ($trimmed === '' || mb_strlen($trimmed) > 100) {
            throw new \InvalidArgumentException('Invalid tag name. Must be non-empty and max 100 characters.');
        }

        // Standardized system tag name with group prefix
        $fullName = "[{$groupId}] {$trimmed}";

        try {
            // Check if tag already exists in systemtag
            $tag = null;
            try {
                // Find tag by exact full name
                $allTags = $this->tagManager->getAllTags(null);
                foreach ($allTags as $t) {
                    if ($t->getName() === $fullName) {
                        $tag = $t;
                        break;
                    }
                }
            } catch (\Throwable $t) {
            }

            if ($tag === null) {
                $tag = $this->tagManager->createTag($fullName, true, true);
            }

            $tagId = (int)$tag->getId();

            // Register tag ownership and group isolation
            $this->tagOwnershipService->setTagOwner($tagId, $actorUid);
            $this->tagOwnershipService->assignTagToGroup($tagId, $groupId);

            $this->logAuditEvent($actorUid, $groupId, 'create_tag', $tagId, $fullName, null, null, null, 'success', "Tag created for group {$groupId}");

            return [
                'status' => 'success',
                'tag_id' => $tagId,
                'name' => $fullName,
                'clean_name' => $trimmed,
                'group_id' => $groupId,
            ];
        } catch (TagAlreadyExistsException $e) {
            throw new \DomainException("Tag '{$trimmed}' already exists for group '{$groupId}'.");
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Failed to create group tag '{$fullName}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete a group-specific tag.
     */
    public function deleteGroupTag(string $actorUid, string $groupId, int $tagId): array {
        if (!$this->isUserAdminOfGroup($actorUid, $groupId)) {
            $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, '', null, null, null, 'failure', 'User is not a group admin of this group.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not an authorized Group Admin for group '{$groupId}'.");
        }

        // Verify tag belongs to group
        $assignedGroups = $this->tagOwnershipService->getTagGroups($tagId);
        if (!in_array($groupId, $assignedGroups, true)) {
            // Check case-insensitively
            $match = false;
            foreach ($assignedGroups as $ag) {
                if (strcasecmp($ag, $groupId) === 0) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, '', null, null, null, 'failure', "Tag #{$tagId} does not belong to group '{$groupId}'.");
                throw new SecurityPermissionException("Forbidden: Tag #{$tagId} does not belong to group '{$groupId}'.");
            }
        }

        $tagName = '';
        try {
            $tagObj = $this->tagManager->getTag($tagId);
            $tagName = $tagObj->getName();
            $this->tagManager->deleteTags([$tagId]);
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Tag #{$tagId} delete error: " . $t->getMessage());
        }

        $this->tagOwnershipService->deleteTagOwner($tagId);

        $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, $tagName, null, null, null, 'success', "Tag #{$tagId} ('{$tagName}') deleted by {$actorUid}");

        return [
            'status' => 'success',
            'tag_id' => $tagId,
            'message' => 'Tag deleted successfully.',
        ];
    }

    /**
     * List all tags belonging to a group.
     */
    public function listGroupTags(string $groupId, ?string $actorUid = null): array {
        if ($actorUid !== null && !$this->isUserAdminOfGroup($actorUid, $groupId)) {
            // Also check if user is at least a member of the group
            $user = \OC::$server->getUserManager()->get($actorUid);
            $userGroups = $user !== null ? \OC::$server->getGroupManager()->getUserGroupIds($user) : [];
            $memberMatch = false;
            foreach ($userGroups as $ug) {
                if (strcasecmp($ug, $groupId) === 0) {
                    $memberMatch = true;
                    break;
                }
            }
            if (!$memberMatch) {
                throw new SecurityPermissionException("Forbidden: User '{$actorUid}' cannot access tags for group '{$groupId}'.");
            }
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id', 't.name')
           ->from('systemtag', 't')
           ->innerJoin('t', 'archive_tag_groups', 'g', $qb->expr()->eq('t.id', 'g.tag_id'))
           ->where($qb->expr()->ilike('g.group_id', $qb->createNamedParameter($groupId)))
           ->orderBy('t.name', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        // Get file counts for each tag
        $fileCounts = [];
        if (!empty($rows)) {
            $tagIds = array_map(fn($r) => (int)$r['id'], $rows);
            $cQb = $this->db->getQueryBuilder();
            $cQb->select('systemtagid', $cQb->createFunction('COUNT(DISTINCT objectid) as cnt'))
                ->from('systemtag_object_mapping')
                ->where($cQb->expr()->in('systemtagid', $cQb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($cQb->expr()->eq('objecttype', $cQb->createNamedParameter('files')))
                ->groupBy('systemtagid');
            $cRes = $cQb->executeQuery();
            while ($cRow = $cRes->fetchAssociative()) {
                $fileCounts[(int)$cRow['systemtagid']] = (int)$cRow['cnt'];
            }
        }

        $tags = [];
        foreach ($rows as $r) {
            $tId = (int)$r['id'];
            $fullName = (string)$r['name'];
            $cleanName = $this->extractCleanTagName($fullName, $groupId);
            $tags[] = [
                'id' => $tId,
                'name' => $fullName,
                'clean_name' => $cleanName,
                'file_count' => $fileCounts[$tId] ?? 0,
            ];
        }

        return $tags;
    }

    /**
     * Extract clean display name by stripping [<groupId>] prefix.
     */
    public function extractCleanTagName(string $fullName, string $groupId): string {
        $prefix = "[{$groupId}] ";
        if (str_starts_with($fullName, $prefix)) {
            return substr($fullName, strlen($prefix));
        }
        if (preg_match('/^\[[^\]]+\]\s*(.+)$/u', $fullName, $m)) {
            return $m[1];
        }
        return $fullName;
    }

    /**
     * Assign a group tag to a file or folder.
     */
    public function assignTagToTarget(string $actorUid, string $groupId, int $tagId, int $fileId): array {
        if (!$this->isUserAdminOfGroup($actorUid, $groupId)) {
            $this->logAuditEvent($actorUid, $groupId, 'assign_tag', $tagId, '', 'file', $fileId, null, 'failure', 'User is not a group admin of this group.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not an authorized Group Admin for group '{$groupId}'.");
        }

        // Validate tag belongs to group
        $assignedGroups = $this->tagOwnershipService->getTagGroups($tagId);
        $tagMatch = false;
        foreach ($assignedGroups as $ag) {
            if (strcasecmp($ag, $groupId) === 0) {
                $tagMatch = true;
                break;
            }
        }
        if (!$tagMatch) {
            $this->logAuditEvent($actorUid, $groupId, 'assign_tag', $tagId, '', 'file', $fileId, null, 'failure', "Tag #{$tagId} is not assigned to group '{$groupId}'.");
            throw new SecurityPermissionException("Forbidden: Tag #{$tagId} does not belong to group '{$groupId}'.");
        }

        // Validate target file/folder belongs to group
        $targetInfo = $this->resolveFileInGroupScope($fileId, $groupId);
        if (!$targetInfo['in_scope']) {
            $this->logAuditEvent($actorUid, $groupId, 'assign_tag', $tagId, '', 'file', $fileId, $targetInfo['path'], 'failure', "Target file #{$fileId} does not belong to group '{$groupId}'.");
            throw new SecurityPermissionException("Forbidden: Target file/folder #{$fileId} is not in group '{$groupId}' scope.");
        }

        $tagName = '';
        try {
            $tagObj = $this->tagManager->getTag($tagId);
            $tagName = $tagObj->getName();
        } catch (\Throwable $t) {}

        // Assign tag
        $this->tagMapper->assignTags((string)$fileId, 'files', [(string)$tagId]);

        $this->logAuditEvent($actorUid, $groupId, 'assign_tag', $tagId, $tagName, $targetInfo['type'], $fileId, $targetInfo['path'], 'success', "Tag '{$tagName}' assigned to target.");

        return [
            'status' => 'success',
            'tag_id' => $tagId,
            'tag_name' => $tagName,
            'clean_name' => $this->extractCleanTagName($tagName, $groupId),
            'file_id' => $fileId,
            'target_path' => $targetInfo['path'],
        ];
    }

    /**
     * Remove a group tag from a file or folder.
     */
    public function removeTagFromTarget(string $actorUid, string $groupId, int $tagId, int $fileId): array {
        if (!$this->isUserAdminOfGroup($actorUid, $groupId)) {
            $this->logAuditEvent($actorUid, $groupId, 'remove_tag', $tagId, '', 'file', $fileId, null, 'failure', 'User is not a group admin of this group.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not an authorized Group Admin for group '{$groupId}'.");
        }

        // Validate tag belongs to group
        $assignedGroups = $this->tagOwnershipService->getTagGroups($tagId);
        $tagMatch = false;
        foreach ($assignedGroups as $ag) {
            if (strcasecmp($ag, $groupId) === 0) {
                $tagMatch = true;
                break;
            }
        }
        if (!$tagMatch) {
            $this->logAuditEvent($actorUid, $groupId, 'remove_tag', $tagId, '', 'file', $fileId, null, 'failure', "Tag #{$tagId} is not assigned to group '{$groupId}'.");
            throw new SecurityPermissionException("Forbidden: Tag #{$tagId} does not belong to group '{$groupId}'.");
        }

        // Validate target file/folder belongs to group
        $targetInfo = $this->resolveFileInGroupScope($fileId, $groupId);
        if (!$targetInfo['in_scope']) {
            $this->logAuditEvent($actorUid, $groupId, 'remove_tag', $tagId, '', 'file', $fileId, $targetInfo['path'], 'failure', "Target file #{$fileId} does not belong to group '{$groupId}'.");
            throw new SecurityPermissionException("Forbidden: Target file/folder #{$fileId} is not in group '{$groupId}' scope.");
        }

        $tagName = '';
        try {
            $tagObj = $this->tagManager->getTag($tagId);
            $tagName = $tagObj->getName();
        } catch (\Throwable $t) {}

        // Remove tag
        $this->tagMapper->unassignTags((string)$fileId, 'files', [(string)$tagId]);

        $this->logAuditEvent($actorUid, $groupId, 'remove_tag', $tagId, $tagName, $targetInfo['type'], $fileId, $targetInfo['path'], 'success', "Tag '{$tagName}' unassigned from target.");

        return [
            'status' => 'success',
            'tag_id' => $tagId,
            'tag_name' => $tagName,
            'file_id' => $fileId,
        ];
    }

    /**
     * Resolve target file/folder and verify whether it belongs to group scope.
     */
    public function resolveFileInGroupScope(int $fileId, string $groupId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'path', 'mimetype')
           ->from('filecache')
           ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            return ['in_scope' => false, 'type' => 'unknown', 'path' => null];
        }

        $path = (string)$row['path'];
        $type = ((int)$row['mimetype'] === 2) ? 'folder' : 'file';

        // 1. Path check: /Enterprise_Archive/<groupId>/ or files/Enterprise_Archive/<groupId>/
        $cleanPath = ltrim($path, '/');
        $lowerClean = strtolower($cleanPath);
        $grpLower = strtolower($groupId);

        $prefix1 = "files/enterprise_archive/{$grpLower}/";
        $prefix2 = "enterprise_archive/{$grpLower}/";

        if (str_starts_with($lowerClean, $prefix1) ||
            str_starts_with($lowerClean, $prefix2) ||
            $lowerClean === rtrim($prefix1, '/') ||
            $lowerClean === rtrim($prefix2, '/')) {
            return ['in_scope' => true, 'type' => $type, 'path' => $path];
        }

        // 2. Explicit group grant check in archive_file_grants
        $ancestorIds = $this->fileOwnershipService->getAncestorFolderIds($fileId);
        $checkIds = array_merge([$fileId], $ancestorIds);

        $gQb = $this->db->getQueryBuilder();
        $gQb->select('id')
            ->from('archive_file_grants')
            ->where($gQb->expr()->in('file_id', $gQb->createNamedParameter($checkIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($gQb->expr()->eq('grantee_type', $gQb->createNamedParameter('group')))
            ->andWhere($gQb->expr()->ilike('grantee_id', $gQb->createNamedParameter($groupId)));
        if ($gQb->executeQuery()->fetchAssociative()) {
            return ['in_scope' => true, 'type' => $type, 'path' => $path];
        }

        // 3. Share check in oc_share with group
        $sQb = $this->db->getQueryBuilder();
        $sQb->select('id')
            ->from('share')
            ->where($sQb->expr()->in('item_source', $sQb->createNamedParameter(array_map('strval', $checkIds), IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($sQb->expr()->eq('share_type', $sQb->createNamedParameter(1)))
            ->andWhere($sQb->expr()->ilike('share_with', $sQb->createNamedParameter($groupId)));
        if ($sQb->executeQuery()->fetchAssociative()) {
            return ['in_scope' => true, 'type' => $type, 'path' => $path];
        }

        return ['in_scope' => false, 'type' => $type, 'path' => $path];
    }

    /**
     * Structured audit logger for all group tag operations.
     */
    public function logAuditEvent(
        string $actorUid,
        string $groupId,
        string $action,
        int $tagId,
        string $tagName,
        ?string $targetType,
        ?int $targetId,
        ?string $targetPath,
        string $result,
        string $details = ''
    ): void {
        try {
            $now = time();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('archive_tag_audit')
               ->values([
                   'actor_uid' => $qb->createNamedParameter($actorUid),
                   'group_id' => $qb->createNamedParameter($groupId),
                   'action' => $qb->createNamedParameter($action),
                   'tag_id' => $qb->createNamedParameter($tagId),
                   'tag_name' => $qb->createNamedParameter($tagName),
                   'target_type' => $qb->createNamedParameter($targetType),
                   'target_id' => $qb->createNamedParameter($targetId),
                   'target_path' => $qb->createNamedParameter($targetPath),
                   'result' => $qb->createNamedParameter($result),
                   'details' => $qb->createNamedParameter($details),
                   'created_at' => $qb->createNamedParameter($now),
               ]);
            $qb->executeStatement();

            $this->logger->info("archive_autotag [TagAudit]: {$action} by {$actorUid} in group {$groupId} on tag '{$tagName}' - Result: {$result}");
        } catch (\Throwable $t) {
            $this->logger->error("archive_autotag: Failed to log tag audit event: " . $t->getMessage());
        }
    }
}
