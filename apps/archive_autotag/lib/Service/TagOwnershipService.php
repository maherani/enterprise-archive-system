<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TagOwnershipService {
    public function __construct(
        private IDBConnection $db,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    public function setTagOwner(int $tagId, string $ownerUid): void {
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
                 ->where($upQb->expr()->eq('tag_id', $upQb->createNamedParameter($tagId)));
            $upQb->executeStatement();
        } else {
            $insQb = $this->db->getQueryBuilder();
            $insQb->insert('archive_tag_ownership')
                  ->values([
                      'tag_id' => $insQb->createNamedParameter($tagId),
                      'owner_uid' => $insQb->createNamedParameter($ownerUid),
                      'created_at' => $insQb->createNamedParameter($now),
                  ]);
            $insQb->executeStatement();
        }
        $this->logger->info("archive_autotag: Tag ID {$tagId} registered with owner: {$ownerUid}");
    }

    public function getTagOwner(int $tagId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid')
           ->from('archive_tag_ownership')
           ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (string)$row['owner_uid'] : null;
    }

    public function deleteTagOwner(int $tagId): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('archive_tag_ownership')
               ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId)));
            $qb->executeStatement();
        } catch (\Throwable $t) {
        }
    }

    public function canUserSeeTag(int $tagId, ?string $userId = null): bool {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return true;
            }
            $userId = $user->getUID();
        }

        // 1. Admin sees ALL tags
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        // 2. Lookup owner
        $owner = $this->getTagOwner($tagId);
        // If untracked tag, default to 'system' so hierarchical parent tags stay visible
        if ($owner === null || $owner === 'system' || $owner === 'admin') {
            return true;
        }

        // 3. User can see their own tags
        if ($owner === $userId) {
            return true;
        }

        // Cannot see other user's private tags
        return false;
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
        }

        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('systemtag');
            $rows = $qb->executeQuery()->fetchAllAssociative();
            return array_map(fn($r) => (int)$r['id'], $rows);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id')
           ->from('systemtag', 't')
           ->leftJoin('t', 'archive_tag_ownership', 'o', $qb->expr()->eq('t.id', 'o.tag_id'))
           ->where(
               $qb->expr()->orX(
                   $qb->expr()->isNull('o.owner_uid'),
                   $qb->expr()->in('o.owner_uid', $qb->createNamedParameter(['system', 'admin', $userId], IQueryBuilder::PARAM_STR_ARRAY))
               )
           );
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map(fn($r) => (int)$r['id'], $rows);
    }
}
