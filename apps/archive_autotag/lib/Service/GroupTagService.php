<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use OCP\SystemTag\TagAlreadyExistsException;
use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Exception\TagInUseException;
use OCA\ArchiveAutoTag\Exception\TagDeletionException;
use Psr\Log\LoggerInterface;

class GroupTagService {
    public function __construct(
        private IDBConnection $db,
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $tagMapper,
        private TagOwnershipService $tagOwnershipService,
        private FileOwnershipService $fileOwnershipService,
        private LoggerInterface $logger,
        private ?ReliableAuditService $reliableAuditService = null,
        private ?IGroupManager $groupManager = null,
        private ?AutoTagService $autoTagService = null,
    ) {}

    /**
     * Check whether a user is an authorized Group Admin for a given group.
     * System admins do not get implicit bypass: they must be assigned subadmin of that group.
     */
    /**
     * Check whether a user is a System Administrator.
     */
    public function isSystemAdmin(string $userId): bool {
        if ($userId === 'admin') {
            return true;
        }
        if ($this->groupManager !== null) {
            return $this->groupManager->isAdmin($userId);
        }
        try {
            return \OC::$server->getGroupManager()->isAdmin($userId);
        } catch (\Throwable $t) {
            return false;
        }
    }

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
            $this->tagOwnershipService->setTagOwner($tagId, $actorUid, 'ACTIVE');
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
     * Delete a group-specific tag atomically with strict consistency.
     *
     * @param string $actorUid The executing user ID
     * @param string $groupId The target group
     * @param int $tagId The tag ID to delete
     * @param bool $force If true, cascades detachment from files; if false and in use, aborts with TagInUseException
     * @return array
     * @throws SecurityPermissionException If user is unauthorized or tag belongs to another group
     * @throws TagNotFoundException If tag does not exist
     * @throws TagInUseException If tag is in use and $force is false
     * @throws TagDeletionException On low-level database or system failure
     */
    public function deleteGroupTag(string $actorUid, string $groupId, int $tagId, bool $force = false): array {
        // 1. Validate Group Admin membership
        if (!$this->isUserAdminOfGroup($actorUid, $groupId)) {
            $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, '', null, null, null, 'failure', 'User is not a group admin of this group.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not an authorized Group Admin for group '{$groupId}'.");
        }

        $tagName = '';
        $usageCount = 0;

        // 2. Begin atomic transaction
        $this->db->beginTransaction();

        try {
            // 3. Pessimistic row locking on tag ownership to serialize concurrent deletes
            $lockSql = "SELECT tag_id, owner_uid, status FROM oc_archive_tag_ownership WHERE tag_id = ? FOR UPDATE";
            $ownRow = $this->db->executeQuery($lockSql, [$tagId])->fetchAssociative();

            // 4. Verify tag exists in oc_systemtag
            $sQb = $this->db->getQueryBuilder();
            $sQb->select('id', 'name')
                ->from('systemtag')
                ->where($sQb->expr()->eq('id', $sQb->createNamedParameter($tagId)));
            $sysRow = $sQb->executeQuery()->fetchAssociative();

            if (!$ownRow && !$sysRow) {
                $this->db->rollBack();
                $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, '', null, null, null, 'failure', "Tag #{$tagId} not found.");
                throw new TagNotFoundException("Tag #{$tagId} not found.");
            }

            $tagName = $sysRow ? (string)$sysRow['name'] : '';

            // 5. Verify tag belongs to the requested group
            $assignedGroups = $this->tagOwnershipService->getTagGroups($tagId);
            $match = false;
            foreach ($assignedGroups as $ag) {
                if (strcasecmp($ag, $groupId) === 0) {
                    $match = true;
                    break;
                }
            }

            // Also check prefix in tagName: "[groupId] ..."
            if (!$match && $tagName !== '') {
                $expectedPrefix = "[{$groupId}] ";
                if (str_starts_with(strtolower($tagName), strtolower($expectedPrefix))) {
                    $match = true;
                }
            }

            if (!$match) {
                $this->db->rollBack();
                $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, $tagName, null, null, null, 'failure', "Tag #{$tagId} does not belong to group '{$groupId}'.");
                throw new SecurityPermissionException("Forbidden: Tag #{$tagId} does not belong to group '{$groupId}'.");
            }

            // 5b. Strictly disallow deletion of system-generated or admin-created tags by Group Admin
            $ownerUid = $ownRow ? (string)($ownRow['owner_uid'] ?? '') : '';
            $expectedPrefix = "[{$groupId}] ";
            $hasGroupPrefix = str_starts_with(strtolower($tagName), strtolower($expectedPrefix));

            if ($ownerUid === 'system' || $ownerUid === 'admin' || !$hasGroupPrefix) {
                $this->db->rollBack();
                $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, $tagName, null, null, null, 'failure', "Tag #{$tagId} is a system or admin-created tag and cannot be deleted by group admin '{$actorUid}'.");
                throw new SecurityPermissionException("عملیات غیرمجاز: تگ‌های سیستمی و خودکار، و تگ‌های ایجادشده توسط مدیر ارشد سامانه، توسط ادمین گروه قابل حذف نمی‌باشند.");
            }

            // 6. Check if tag is in use (assigned to files)
            $usageCount = $this->getTagUsageCount($tagId);
            if ($usageCount > 0 && !$force) {
                $this->db->rollBack();
                $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, $tagName, null, null, null, 'aborted', "Tag is currently assigned to {$usageCount} files and force flag was not provided.");
                throw new TagInUseException("Tag '{$tagName}' (#{$tagId}) is currently in use across {$usageCount} file(s). Pass force=true to proceed.", $tagId, $usageCount);
            }

            // 7. Transition status to DELETING
            $uQb = $this->db->getQueryBuilder();
            $uQb->update('archive_tag_ownership')
                ->set('status', $uQb->createNamedParameter('DELETING'))
                ->where($uQb->expr()->eq('tag_id', $uQb->createNamedParameter($tagId)));
            $uQb->executeStatement();

            // 8. Delete file object mappings (cascading detachment)
            $dMapQb = $this->db->getQueryBuilder();
            $dMapQb->delete('systemtag_object_mapping')
                   ->where($dMapQb->expr()->eq('systemtagid', $dMapQb->createNamedParameter($tagId)));
            $dMapQb->executeStatement();

            // 9. Delete group restrictions in core systemtag_group if present
            try {
                $dGrpCoreQb = $this->db->getQueryBuilder();
                $dGrpCoreQb->delete('systemtag_group')
                           ->where($dGrpCoreQb->expr()->eq('systemtagid', $dGrpCoreQb->createNamedParameter($tagId)));
                $dGrpCoreQb->executeStatement();
            } catch (\Throwable $t) {
                // Table might not exist or be empty, continue
            }

            // 10. Delete Nextcloud system tag
            $dSysQb = $this->db->getQueryBuilder();
            $dSysQb->delete('systemtag')
                   ->where($dSysQb->expr()->eq('id', $dSysQb->createNamedParameter($tagId)));
            $dSysQb->executeStatement();

            // 11. Delete archive app metadata: groups and ownership
            $dTagGrpQb = $this->db->getQueryBuilder();
            $dTagGrpQb->delete('archive_tag_groups')
                      ->where($dTagGrpQb->expr()->eq('tag_id', $dTagGrpQb->createNamedParameter($tagId)));
            $dTagGrpQb->executeStatement();

            $dOwnQb = $this->db->getQueryBuilder();
            $dOwnQb->delete('archive_tag_ownership')
                   ->where($dOwnQb->expr()->eq('tag_id', $dOwnQb->createNamedParameter($tagId)));
            $dOwnQb->executeStatement();

            // 12. Audit inside transaction boundary (Fail-Closed: if audit write fails, entire delete rolls back)
            $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, $tagName, null, null, null, 'success', "Tag #{$tagId} ('{$tagName}') deleted by {$actorUid} (usage detached: {$usageCount})", '', '', '', $this->db);

            // 13. Commit transaction atomically
            $this->db->commit();

            return [
                'status' => 'success',
                'tag_id' => $tagId,
                'tag_name' => $tagName,
                'usage_detached' => $usageCount,
                'message' => 'Tag deleted successfully.',
            ];
        } catch (SecurityPermissionException | TagNotFoundException | TagInUseException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logAuditEvent($actorUid, $groupId, 'delete_tag', $tagId, $tagName, null, null, null, 'failure', "Deletion failed: " . $e->getMessage());
            $this->logger->error("archive_autotag: Failed to delete group tag #{$tagId}: " . $e->getMessage());
            throw new TagDeletionException("Failed to delete tag #{$tagId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the count of file object mappings for a specific tag.
     */
    public function getTagUsageCount(int $tagId): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as cnt'))
               ->from('systemtag_object_mapping')
               ->where($qb->expr()->eq('systemtagid', $qb->createNamedParameter($tagId)));
            $res = $qb->executeQuery()->fetchAssociative();
            return $res ? (int)$res['cnt'] : 0;
        } catch (\Throwable $t) {
            return 0;
        }
    }

    /**
     * Reconcile group tags within a group:
     * - Detect and restore orphaned system tags (in oc_systemtag matching [groupId] but missing from archive tables)
     * - Purge ghost metadata records (in archive_tag_groups / archive_tag_ownership for non-existent tags)
     * - Clean dangling systemtag_object_mapping records
     */
    public function reconcileGroupTags(string $actorUid, string $groupId): array {
        if ($actorUid !== 'admin' && !$this->isUserAdminOfGroup($actorUid, $groupId)) {
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not an authorized Group Admin for group '{$groupId}'.");
        }

        $orphansRestored = [];
        $ghostsPurged = [];

        // 1. Find Orphan System Tags: oc_systemtag named [groupId] ... but missing from archive_tag_groups
        $prefix = "[{$groupId}] %";
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('t.id', 't.name')
            ->from('systemtag', 't')
            ->leftJoin('t', 'archive_tag_groups', 'g', $oQb->expr()->andX(
                $oQb->expr()->eq('t.id', 'g.tag_id'),
                $oQb->expr()->ilike('g.group_id', $oQb->createNamedParameter($groupId))
            ))
            ->where($oQb->expr()->ilike('t.name', $oQb->createNamedParameter($prefix)))
            ->andWhere($oQb->expr()->isNull('g.id'));
        $orphans = $oQb->executeQuery()->fetchAllAssociative();

        foreach ($orphans as $orphan) {
            $tId = (int)$orphan['id'];
            $tName = (string)$orphan['name'];

            // Restore in archive_tag_groups
            $this->tagOwnershipService->assignTagToGroup($tId, $groupId);
            // Restore in archive_tag_ownership if missing
            $owner = $this->tagOwnershipService->getTagOwner($tId);
            if ($owner === null) {
                $this->tagOwnershipService->setTagOwner($tId, $actorUid, 'ACTIVE');
            }
            $orphansRestored[] = ['id' => $tId, 'name' => $tName];
        }

        // 2. Find Ghost Archive Records: archive_tag_groups for groupId pointing to non-existent tag
        $gQb = $this->db->getQueryBuilder();
        $gQb->select('g.id', 'g.tag_id')
            ->from('archive_tag_groups', 'g')
            ->leftJoin('g', 'systemtag', 't', $gQb->expr()->eq('g.tag_id', 't.id'))
            ->where($gQb->expr()->ilike('g.group_id', $gQb->createNamedParameter($groupId)))
            ->andWhere($gQb->expr()->isNull('t.id'));
        $ghosts = $gQb->executeQuery()->fetchAllAssociative();

        foreach ($ghosts as $ghost) {
            $gId = (int)$ghost['id'];
            $tId = (int)$ghost['tag_id'];

            // Delete ghost records
            $dG = $this->db->getQueryBuilder();
            $dG->delete('archive_tag_groups')
               ->where($dG->expr()->eq('id', $dG->createNamedParameter($gId)));
            $dG->executeStatement();

            $dO = $this->db->getQueryBuilder();
            $dO->delete('archive_tag_ownership')
               ->where($dO->expr()->eq('tag_id', $dO->createNamedParameter($tId)));
            $dO->executeStatement();

            $ghostsPurged[] = ['id' => $gId, 'tag_id' => $tId];
        }

        // 3. Find and prune dangling object mappings pointing to non-existent tags
        $danglingCleaned = 0;
        try {
            $dMapSql = "DELETE FROM oc_systemtag_object_mapping WHERE systemtagid NOT IN (SELECT id FROM oc_systemtag)";
            $danglingCleaned = $this->db->executeStatement($dMapSql);
        } catch (\Throwable $t) {
            // Ignore if subquery syntax varies
        }

        // 4. Audit
        $details = sprintf(
            "Reconciliation complete for group '%s': %d orphans restored, %d ghost records purged, %d dangling mappings pruned.",
            $groupId, count($orphansRestored), count($ghostsPurged), $danglingCleaned
        );
        $this->logAuditEvent($actorUid, $groupId, 'reconcile_tag', 0, '', null, null, null, 'success', $details);

        return [
            'status' => 'success',
            'group_id' => $groupId,
            'orphans_restored' => $orphansRestored,
            'ghosts_purged' => $ghostsPurged,
            'dangling_mappings_pruned' => $danglingCleaned,
            'message' => $details,
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
        $qb->select('t.id', 't.name', 'o.owner_uid')
           ->from('systemtag', 't')
           ->innerJoin('t', 'archive_tag_groups', 'g', $qb->expr()->eq('t.id', 'g.tag_id'))
           ->leftJoin('t', 'archive_tag_ownership', 'o', $qb->expr()->eq('t.id', 'o.tag_id'))
           ->where($qb->expr()->ilike('g.group_id', $qb->createNamedParameter($groupId)))
           ->andWhere($qb->expr()->like('t.name', $qb->createNamedParameter("[{$groupId}] %")))
           ->andWhere(
               $qb->expr()->orX(
                   $qb->expr()->isNull('o.owner_uid'),
                   $qb->expr()->notIn('o.owner_uid', $qb->createNamedParameter(['system', 'admin'], IQueryBuilder::PARAM_STR_ARRAY))
               )
           )
           ->orderBy('t.name', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        // Get file counts for each tag
        $fileCounts = [];
        $tagStatuses = [];
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

            // Fetch statuses
            $sQb = $this->db->getQueryBuilder();
            $sQb->select('tag_id', 'status')
                ->from('archive_tag_ownership')
                ->where($sQb->expr()->in('tag_id', $sQb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));
            $sRes = $sQb->executeQuery();
            while ($sRow = $sRes->fetchAssociative()) {
                $tagStatuses[(int)$sRow['tag_id']] = (string)($sRow['status'] ?? 'ACTIVE');
            }
        }

        $tags = [];
        foreach ($rows as $r) {
            $tId = (int)$r['id'];
            $fullName = (string)$r['name'];
            $cleanName = $this->extractCleanTagName($fullName, $groupId);
            $cnt = $fileCounts[$tId] ?? 0;
            $stat = $tagStatuses[$tId] ?? 'ACTIVE';

            $tags[] = [
                'id' => $tId,
                'name' => $fullName,
                'clean_name' => $cleanName,
                'file_count' => $cnt,
                'usage_count' => $cnt,
                'status' => $stat,
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
        string $details = '',
        string $requestId = '',
        string $correlationId = '',
        string $clientIp = '',
        ?IDBConnection $conn = null
    ): void {
        $auditData = [
            'request_id' => $requestId !== '' ? $requestId : ('req_tag_' . bin2hex(random_bytes(6))),
            'correlation_id' => $correlationId,
            'actor_uid' => $actorUid,
            'group_id' => $groupId,
            'action' => $action,
            'tag_id' => $tagId,
            'tag_name' => $tagName,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_path' => $targetPath,
            'result' => $result,
            'details' => $details,
            'client_ip' => $clientIp,
            'created_at' => time(),
        ];

        if ($this->reliableAuditService !== null) {
            if ($result === 'success') {
                // Audit-Required: Fail-closed if database write fails
                $this->reliableAuditService->recordRequired('archive_tag_audit', $auditData, $conn);
            } else {
                // Audit-Best-Effort: Fallback to emergency DLQ
                $this->reliableAuditService->recordBestEffort('archive_tag_audit', $auditData);
            }
        } else {
            try {
                $c = $conn ?? $this->db;
                $qb = $c->getQueryBuilder();
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
                       'created_at' => $qb->createNamedParameter(time()),
                       'request_id' => $qb->createNamedParameter($auditData['request_id']),
                       'correlation_id' => $qb->createNamedParameter($correlationId),
                       'client_ip' => $qb->createNamedParameter($clientIp),
                   ]);
                $qb->executeStatement();
            } catch (\Throwable $t) {
                $this->logger->error("archive_autotag: Failed to log tag audit event: " . $t->getMessage());
                if ($result === 'success') {
                    throw $t;
                }
            }
        }

        $this->logger->info("archive_autotag [TagAudit]: {$action} by {$actorUid} in group {$groupId} on tag '{$tagName}' - Result: {$result}");
    }

    /**
     * Create a new tag as System Administrator.
     * Supports either system-wide tag (scope = 'system' or empty groupId)
     * or group-scoped tag (scope = 'group' with groupId).
     */
    public function createAdminTag(string $actorUid, string $scope, ?string $groupId, string $tagName): array {
        if (!$this->isSystemAdmin($actorUid)) {
            $this->logAuditEvent($actorUid, 'system', 'create_tag', 0, $tagName, null, null, null, 'failure', 'User is not a System Administrator.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not a System Administrator.");
        }

        $trimmed = trim($tagName);
        if ($trimmed === '' || mb_strlen($trimmed) > 100) {
            throw new \InvalidArgumentException('Invalid tag name. Must be non-empty and max 100 characters.');
        }

        $isGroup = ($scope === 'group' && !empty($groupId) && strtolower($groupId) !== 'system');
        $effectiveGroupId = $isGroup ? trim((string)$groupId) : 'system';
        $fullName = $isGroup ? "[{$effectiveGroupId}] {$trimmed}" : $trimmed;

        try {
            // Check if tag already exists in systemtag
            $tag = null;
            try {
                $allTags = $this->tagManager->getAllTags(null);
                foreach ($allTags as $t) {
                    if (strcasecmp($t->getName(), $fullName) === 0) {
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

            // Register tag ownership
            $ownerUid = $isGroup ? $actorUid : 'system';
            $this->tagOwnershipService->setTagOwner($tagId, $ownerUid, 'ACTIVE');

            if ($isGroup) {
                $this->tagOwnershipService->assignTagToGroup($tagId, $effectiveGroupId);
            }

            $this->logAuditEvent(
                $actorUid,
                $effectiveGroupId,
                'create_tag',
                $tagId,
                $fullName,
                null,
                null,
                null,
                'success',
                "Tag created by System Administrator (scope: {$scope}, group: {$effectiveGroupId})"
            );

            return [
                'status' => 'success',
                'tag_id' => $tagId,
                'name' => $fullName,
                'clean_name' => $trimmed,
                'scope' => $isGroup ? 'group' : 'system',
                'group_id' => $isGroup ? $effectiveGroupId : null,
            ];
        } catch (TagAlreadyExistsException $e) {
            throw new \DomainException("Tag '{$fullName}' already exists.");
        } catch (\Throwable $e) {
            $this->logger->error("archive_autotag: Failed to create admin tag '{$fullName}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * List all tags across the system for System Administrator catalog.
     * Returns tag details, scope, assigned groups, usage count, status, and owner.
     */
    public function listAllTagsForAdmin(string $actorUid): array {
        if (!$this->isSystemAdmin($actorUid)) {
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not a System Administrator.");
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'visibility', 'editable')
           ->from('systemtag')
           ->orderBy('name', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        if (empty($rows)) {
            return [];
        }

        $tagIds = array_map(fn($r) => (int)$r['id'], $rows);

        // Fetch usage counts (from systemtag_object_mapping)
        $fileCounts = [];
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

        // Fetch ownership & statuses
        $tagOwnerships = [];
        $sQb = $this->db->getQueryBuilder();
        $sQb->select('tag_id', 'owner_uid', 'status')
            ->from('archive_tag_ownership')
            ->where($sQb->expr()->in('tag_id', $sQb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $sRes = $sQb->executeQuery();
        while ($sRow = $sRes->fetchAssociative()) {
            $tagOwnerships[(int)$sRow['tag_id']] = [
                'owner_uid' => (string)($sRow['owner_uid'] ?? 'system'),
                'status' => (string)($sRow['status'] ?? 'ACTIVE'),
            ];
        }

        // Fetch group associations
        $tagGroups = [];
        $gQb = $this->db->getQueryBuilder();
        $gQb->select('tag_id', 'group_id')
            ->from('archive_tag_groups')
            ->where($gQb->expr()->in('tag_id', $gQb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $gRes = $gQb->executeQuery();
        while ($gRow = $gRes->fetchAssociative()) {
            $tid = (int)$gRow['tag_id'];
            $tagGroups[$tid][] = (string)$gRow['group_id'];
        }

        $catalog = [];
        foreach ($rows as $r) {
            $tId = (int)$r['id'];
            $fullName = (string)$r['name'];
            $cnt = $fileCounts[$tId] ?? 0;
            $own = $tagOwnerships[$tId] ?? ['owner_uid' => 'system', 'status' => 'ACTIVE'];
            $grps = $tagGroups[$tId] ?? [];

            // Detect scope & clean name
            $cleanName = $fullName;
            $detectedGroup = !empty($grps) ? $grps[0] : null;

            if (preg_match('/^\[([^\]]+)\]\s*(.+)$/u', $fullName, $m)) {
                $cleanName = $m[2];
                if ($detectedGroup === null) {
                    $detectedGroup = $m[1];
                }
                $scope = 'group';
            } elseif (!empty($grps)) {
                $scope = 'group';
            } else {
                $scope = 'system';
            }

            $groupDisplayName = null;
            if ($detectedGroup !== null) {
                try {
                    $gObj = \OC::$server->getGroupManager()->get($detectedGroup);
                    if ($gObj && method_exists($gObj, 'getDisplayName')) {
                        $d = $gObj->getDisplayName();
                        if (!empty($d)) $groupDisplayName = $d;
                    }
                } catch (\Throwable $t) {}
                if ($groupDisplayName === null) $groupDisplayName = $detectedGroup;
            }

            $catalog[] = [
                'id' => $tId,
                'name' => $fullName,
                'clean_name' => $cleanName,
                'scope' => $scope,
                'group_id' => $detectedGroup,
                'group_name' => $groupDisplayName,
                'group_display_name' => $groupDisplayName,
                'groups' => $grps,
                'status' => $own['status'],
                'owner_uid' => $own['owner_uid'],
                'usage_count' => $cnt,
                'file_count' => $cnt,
                'user_visible' => (bool)$r['visibility'],
                'user_assignable' => (bool)$r['editable'],
            ];
        }

        return $catalog;
    }

    /**
     * Delete any tag as System Administrator.
     * Enforces usage check, force flag confirmation, pessimistic locking,
     * cascading detachment, and transactional audit.
     */
    public function deleteAdminTag(string $actorUid, int $tagId, bool $force = false): array {
        if (!$this->isSystemAdmin($actorUid)) {
            $this->logAuditEvent($actorUid, 'system', 'delete_tag', $tagId, '', null, null, null, 'failure', 'User is not a System Administrator.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not a System Administrator.");
        }

        $tagName = '';
        $usageCount = 0;

        $this->db->beginTransaction();

        try {
            // Pessimistic lock
            $lockSql = "SELECT tag_id, owner_uid, status FROM oc_archive_tag_ownership WHERE tag_id = ? FOR UPDATE";
            $ownRow = $this->db->executeQuery($lockSql, [$tagId])->fetchAssociative();

            // Verify in systemtag
            $sQb = $this->db->getQueryBuilder();
            $sQb->select('id', 'name')
                ->from('systemtag')
                ->where($sQb->expr()->eq('id', $sQb->createNamedParameter($tagId)));
            $sysRow = $sQb->executeQuery()->fetchAssociative();

            if (!$ownRow && !$sysRow) {
                $this->db->rollBack();
                $this->logAuditEvent($actorUid, 'system', 'delete_tag', $tagId, '', null, null, null, 'failure', "Tag #{$tagId} not found.");
                throw new TagNotFoundException("Tag #{$tagId} not found.");
            }

            $tagName = $sysRow ? (string)$sysRow['name'] : '';

            // Check usage count
            $usageCount = $this->getTagUsageCount($tagId);
            if ($usageCount > 0 && !$force) {
                $this->db->rollBack();
                $this->logAuditEvent($actorUid, 'system', 'delete_tag', $tagId, $tagName, null, null, null, 'aborted', "Tag is currently assigned to {$usageCount} resources and force flag was not provided.");
                throw new TagInUseException("Tag '{$tagName}' (#{$tagId}) is currently in use across {$usageCount} resource(s). Pass force=true to proceed.", $tagId, $usageCount);
            }

            // Transition status to DELETING
            $uQb = $this->db->getQueryBuilder();
            $uQb->update('archive_tag_ownership')
                ->set('status', $uQb->createNamedParameter('DELETING'))
                ->where($uQb->expr()->eq('tag_id', $uQb->createNamedParameter($tagId)));
            $uQb->executeStatement();

            // Cascading detachment from systemtag_object_mapping
            $dMapQb = $this->db->getQueryBuilder();
            $dMapQb->delete('systemtag_object_mapping')
                   ->where($dMapQb->expr()->eq('systemtagid', $dMapQb->createNamedParameter($tagId)));
            $dMapQb->executeStatement();

            // Delete from systemtag_group
            try {
                $dGrpCoreQb = $this->db->getQueryBuilder();
                $dGrpCoreQb->delete('systemtag_group')
                           ->where($dGrpCoreQb->expr()->eq('systemtagid', $dGrpCoreQb->createNamedParameter($tagId)));
                $dGrpCoreQb->executeStatement();
            } catch (\Throwable $t) {}

            // Delete from systemtag
            $dSysQb = $this->db->getQueryBuilder();
            $dSysQb->delete('systemtag')
                   ->where($dSysQb->expr()->eq('id', $dSysQb->createNamedParameter($tagId)));
            $dSysQb->executeStatement();

            // Delete from archive_tag_groups and archive_tag_ownership
            $dTagGrpQb = $this->db->getQueryBuilder();
            $dTagGrpQb->delete('archive_tag_groups')
                      ->where($dTagGrpQb->expr()->eq('tag_id', $dTagGrpQb->createNamedParameter($tagId)));
            $dTagGrpQb->executeStatement();

            $dOwnQb = $this->db->getQueryBuilder();
            $dOwnQb->delete('archive_tag_ownership')
                   ->where($dOwnQb->expr()->eq('tag_id', $dOwnQb->createNamedParameter($tagId)));
            $dOwnQb->executeStatement();

            // Transactional audit
            $this->logAuditEvent($actorUid, 'system', 'delete_tag', $tagId, $tagName, null, null, null, 'success', "Tag #{$tagId} ('{$tagName}') deleted by System Administrator (detached usage: {$usageCount})", '', '', '', $this->db);

            $this->db->commit();

            return [
                'status' => 'success',
                'tag_id' => $tagId,
                'tag_name' => $tagName,
                'usage_detached' => $usageCount,
                'message' => 'Tag deleted successfully by System Administrator.',
            ];
        } catch (SecurityPermissionException | TagNotFoundException | TagInUseException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logAuditEvent($actorUid, 'system', 'delete_tag', $tagId, $tagName, null, null, null, 'failure', "Admin deletion failed: " . $e->getMessage());
            $this->logger->error("archive_autotag: Admin failed to delete tag #{$tagId}: " . $e->getMessage());
            throw new TagDeletionException("Failed to delete tag #{$tagId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resolve target file or folder anywhere in the system.
     */
    public function resolveFileInSystemScope(int $fileId): array {
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
        return ['in_scope' => true, 'type' => $type, 'path' => $path];
    }

    /**
     * Assign any tag to any file or folder as System Administrator.
     */
    public function assignAdminTag(string $actorUid, int $tagId, int $fileId): array {
        if (!$this->isSystemAdmin($actorUid)) {
            $this->logAuditEvent($actorUid, 'system', 'assign_tag', $tagId, '', 'resource', $fileId, null, 'failure', 'User is not a System Administrator.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not a System Administrator.");
        }

        $targetInfo = $this->resolveFileInSystemScope($fileId);
        if (!$targetInfo['in_scope']) {
            $this->logAuditEvent($actorUid, 'system', 'assign_tag', $tagId, '', 'resource', $fileId, null, 'failure', "Target resource #{$fileId} not found.");
            throw new \InvalidArgumentException("Target resource #{$fileId} not found in file system.");
        }

        $tagName = '';
        try {
            $tags = $this->tagManager->getTagsByIds([(string)$tagId]);
            if (!empty($tags)) {
                $tagObj = reset($tags);
                $tagName = $tagObj->getName();
            }
        } catch (\Throwable $t) {}

        if ($tagName === '') {
            $sQb = $this->db->getQueryBuilder();
            $sQb->select('name')->from('systemtag')->where($sQb->expr()->eq('id', $sQb->createNamedParameter($tagId)));
            $row = $sQb->executeQuery()->fetchAssociative();
            if (!$row) {
                throw new TagNotFoundException("Tag #{$tagId} not found.");
            }
            $tagName = (string)$row['name'];
        }

        // Assign tag to resource
        $this->tagMapper->assignTags((string)$fileId, 'files', [(string)$tagId]);

        $this->logAuditEvent(
            $actorUid,
            'system',
            'assign_tag',
            $tagId,
            $tagName,
            $targetInfo['type'],
            $fileId,
            $targetInfo['path'],
            'success',
            "Tag '{$tagName}' assigned to {$targetInfo['type']} by System Administrator."
        );

        return [
            'status' => 'success',
            'tag_id' => $tagId,
            'tag_name' => $tagName,
            'file_id' => $fileId,
            'resource_type' => $targetInfo['type'],
            'target_path' => $targetInfo['path'],
        ];
    }

    /**
     * Remove any tag from any file or folder as System Administrator.
     */
    public function removeAdminTag(string $actorUid, int $tagId, int $fileId): array {
        if (!$this->isSystemAdmin($actorUid)) {
            $this->logAuditEvent($actorUid, 'system', 'remove_tag', $tagId, '', 'resource', $fileId, null, 'failure', 'User is not a System Administrator.');
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not a System Administrator.");
        }

        $targetInfo = $this->resolveFileInSystemScope($fileId);
        if (!$targetInfo['in_scope']) {
            $this->logAuditEvent($actorUid, 'system', 'remove_tag', $tagId, '', 'resource', $fileId, null, 'failure', "Target resource #{$fileId} not found.");
            throw new \InvalidArgumentException("Target resource #{$fileId} not found in file system.");
        }

        $tagName = '';
        try {
            $tags = $this->tagManager->getTagsByIds([(string)$tagId]);
            if (!empty($tags)) {
                $tagObj = reset($tags);
                $tagName = $tagObj->getName();
            }
        } catch (\Throwable $t) {}

        if ($tagName === '') {
            $sQb = $this->db->getQueryBuilder();
            $sQb->select('name')->from('systemtag')->where($sQb->expr()->eq('id', $sQb->createNamedParameter($tagId)));
            $row = $sQb->executeQuery()->fetchAssociative();
            if ($row) {
                $tagName = (string)$row['name'];
            }
        }

        // Unassign tag
        $this->tagMapper->unassignTags((string)$fileId, 'files', [(string)$tagId]);

        $this->logAuditEvent(
            $actorUid,
            'system',
            'remove_tag',
            $tagId,
            $tagName,
            $targetInfo['type'],
            $fileId,
            $targetInfo['path'],
            'success',
            "Tag '{$tagName}' removed from {$targetInfo['type']} by System Administrator."
        );

        return [
            'status' => 'success',
            'tag_id' => $tagId,
            'tag_name' => $tagName,
            'file_id' => $fileId,
            'resource_type' => $targetInfo['type'],
        ];
    }

    /**
     * Run global reconciliation for System Administrator.
     */
    public function reconcileAdminTags(string $actorUid): array {
        if (!$this->isSystemAdmin($actorUid)) {
            throw new SecurityPermissionException("Forbidden: User '{$actorUid}' is not a System Administrator.");
        }

        $autoTagReport = [];
        if ($this->autoTagService !== null) {
            $autoTagReport = $this->autoTagService->reconcileAllTags();
        } else {
            try {
                $svc = \OC::$server->get(AutoTagService::class);
                $autoTagReport = $svc->reconcileAllTags();
            } catch (\Throwable $t) {
                $this->logger->warning("GroupTagService::reconcileAdminTags: Failed to run AutoTagService: " . $t->getMessage());
            }
        }

        // Clean dangling mappings
        $danglingCleaned = 0;
        try {
            $dMapSql = "DELETE FROM oc_systemtag_object_mapping WHERE systemtagid NOT IN (SELECT id FROM oc_systemtag)";
            $danglingCleaned = $this->db->executeStatement($dMapSql);
        } catch (\Throwable $t) {}

        $details = sprintf(
            "Global tag reconciliation completed by %s. Dangling mappings pruned: %d.",
            $actorUid, $danglingCleaned
        );
        $this->logAuditEvent($actorUid, 'system', 'reconcile_tag', 0, '', null, null, null, 'success', $details);

        return [
            'status' => 'success',
            'auto_tag_report' => $autoTagReport,
            'dangling_mappings_pruned' => $danglingCleaned,
            'message' => $details,
        ];
    }
}
