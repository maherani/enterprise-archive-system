<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TagOwnershipService {
    public function __construct(
        private IDBConnection $db,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    public function setTagOwner(int $tagId, string $ownerUid, string $status = 'ACTIVE'): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('archive_tag_ownership')
           ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
        $existing = $qb->executeQuery()->fetchAssociative();

        if ($existing) {
            $upQb = $this->db->getQueryBuilder();
            $upQb->update('archive_tag_ownership')
                 ->set('owner_uid', $upQb->createNamedParameter($ownerUid))
                 ->set('status', $upQb->createNamedParameter($status))
                 ->where($upQb->expr()->eq('tag_id', $upQb->createNamedParameter($tagId)));
            $upQb->executeStatement();
        } else {
            $insQb = $this->db->getQueryBuilder();
            $insQb->insert('archive_tag_ownership')
                  ->values([
                      'tag_id' => $insQb->createNamedParameter($tagId),
                      'owner_uid' => $insQb->createNamedParameter($ownerUid),
                      'status' => $insQb->createNamedParameter($status),
                      'created_at' => $insQb->createNamedParameter($now),
                  ]);
            $insQb->executeStatement();
        }
        $this->logger->info("archive_autotag: Tag ID {$tagId} registered with owner: {$ownerUid} (status: {$status})");
    }

    public function getTagOwner(int $tagId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid')
           ->from('archive_tag_ownership')
           ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (string)$row['owner_uid'] : null;
    }

    public function getTagStatus(int $tagId): string {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('status')
               ->from('archive_tag_ownership')
               ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
            $row = $qb->executeQuery()->fetchAssociative();
            return $row ? (string)($row['status'] ?? 'ACTIVE') : 'ACTIVE';
        } catch (\Throwable $t) {
            return 'ACTIVE';
        }
    }

    public function setTagStatus(int $tagId, string $status): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update('archive_tag_ownership')
               ->set('status', $qb->createNamedParameter($status))
               ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
            $qb->executeStatement();
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Failed to set status for tag {$tagId}: " . $t->getMessage());
        }
    }

    public function assignTagToGroup(int $tagId, string $groupId): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('archive_tag_groups')
           ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)))
           ->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            $ins = $this->db->getQueryBuilder();
            $ins->insert('archive_tag_groups')
                ->values([
                    'tag_id' => $ins->createNamedParameter($tagId),
                    'group_id' => $ins->createNamedParameter($groupId),
                    'created_at' => $ins->createNamedParameter($now),
                ]);
            $ins->executeStatement();
            $this->logger->info("archive_autotag: Tag ID {$tagId} assigned to group '{$groupId}'");
        }
    }

    public function removeTagFromGroup(int $tagId, string $groupId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('archive_tag_groups')
           ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)))
           ->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
        $qb->executeStatement();
        $this->logger->info("archive_autotag: Tag ID {$tagId} removed from group '{$groupId}'");
    }

    public function getTagGroups(int $tagId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('group_id')
               ->from('archive_tag_groups')
               ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
            $rows = $qb->executeQuery()->fetchAllAssociative();
            return array_map(fn($r) => (string)$r['group_id'], $rows);
        } catch (\Throwable $t) {
            return [];
        }
    }

    public function deleteTagOwner(int $tagId): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_tag_ownership')
               ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
            $qb->executeStatement();

            $qb2 = $this->db->getQueryBuilder();
            $qb2->delete('archive_tag_groups')
                ->where($qb2->expr()->eq('tag_id', $qb2->createNamedParameter($tagId)));
            $qb2->executeStatement();
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Failed to delete owner/group records for tag {$tagId}: " . $t->getMessage());
        }
    }

    public function canUserSeeTag(int $tagId, ?string $userId = null): bool {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return true;
            }
            $userId = $user->getUID();
        } else {
            $user = $this->userManager->get($userId);
        }

        // 1. Admin sees ALL tags across all groups
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        // Exclude tags pending deletion or failed deletion for regular users
        $status = $this->getTagStatus($tagId);
        if ($status !== 'ACTIVE') {
            return false;
        }

        $userGroups = $user !== null ? $this->groupManager->getUserGroupIds($user) : [];

        // 2. Check group-level tag restrictions
        $tagGroups = $this->getTagGroups($tagId);
        if (!empty($tagGroups)) {
            // Strict Group Isolation: User MUST belong to at least one assigned group
            return !empty(array_intersect($userGroups, $tagGroups));
        }

        // 3. Fallback: Tag has no explicit group restriction in archive_tag_groups
        // Check if tag name matches any existing group in the system
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')->from('systemtag')->where($qb->expr()->eq('id', $qb->createNamedParameter($tagId)));
        $tagName = (string)($qb->executeQuery()->fetchOne() ?: '');

        if ($tagName !== '') {
            foreach ($this->groupManager->search('') as $grp) {
                $gid = $grp->getGID();
                if (strcasecmp($tagName, $gid) === 0) {
                    // Tag matches a department group name -> restrict to members of that group
                    return in_array($gid, $userGroups, true);
                }
            }
        }

        // 4. Check user ownership for private tags
        $owner = $this->getTagOwner($tagId);
        if ($owner !== null && $owner !== 'system' && $owner !== 'admin') {
            return $owner === $userId;
        }

        // 5. General unrestricted system tag (e.g. Enterprise_Archive)
        return true;
    }

    public function canUserManageTag(int $tagId, ?string $userId = null): bool {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return true;
            }
            $userId = $user->getUID();
        }

        // Admin can modify or delete ANY tag
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        // Regular users cannot modify system/admin tags
        $owner = $this->getTagOwner($tagId);
        if ($owner === null || $owner === 'system' || $owner === 'admin') {
            return false;
        }

        // Regular users can only modify/delete tags they created
        return $owner === $userId;
    }

    public function getVisibleTagIds(?string $userId = null): array {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('id')->from('systemtag');
                $rows = $qb->executeQuery()->fetchAllAssociative();
                return array_map(fn($r) => (int)$r['id'], $rows);
            }
            $userId = $user->getUID();
        } else {
            $user = $this->userManager->get($userId);
        }

        // Admin sees all tags
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('systemtag');
            $rows = $qb->executeQuery()->fetchAllAssociative();
            return array_map(fn($r) => (int)$r['id'], $rows);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('systemtag');
        $allTagIds = array_map(fn($r) => (int)$r['id'], $qb->executeQuery()->fetchAllAssociative());

        $visibleTagIds = [];
        foreach ($allTagIds as $tid) {
            if ($this->canUserSeeTag($tid, $userId)) {
                $visibleTagIds[] = $tid;
            }
        }

        return $visibleTagIds;
    }
}
