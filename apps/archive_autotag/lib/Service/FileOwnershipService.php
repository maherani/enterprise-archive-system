<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class FileOwnershipService {
    public function __construct(
        private IDBConnection $db,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    public function setFileOwner(int $fileId, string $ownerUid): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('archive_file_ownership')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $existing = $qb->executeQuery()->fetchAssociative();

        if ($existing) {
            $upQb = $this->db->getQueryBuilder();
            $upQb->update('archive_file_ownership')
                 ->set('owner_uid', $upQb->createNamedParameter($ownerUid))
                 ->where($upQb->expr()->eq('file_id', $upQb->createNamedParameter($fileId)));
            $upQb->executeStatement();
        } else {
            $insQb = $this->db->getQueryBuilder();
            $insQb->insert('archive_file_ownership')
                  ->values([
                      'file_id' => $insQb->createNamedParameter($fileId),
                      'owner_uid' => $insQb->createNamedParameter($ownerUid),
                      'created_at' => $insQb->createNamedParameter($now),
                  ]);
            $insQb->executeStatement();
        }
        $this->logger->info("archive_autotag: Assigned file ID {$fileId} owner: {$ownerUid}");
    }

    public function getFileOwner(int $fileId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid')
           ->from('archive_file_ownership')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (string)$row['owner_uid'] : null;
    }

    public function canUserAccessFile(int $fileId, ?string $userId = null): bool {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return true;
            }
            $userId = $user->getUID();
        }

        // 1. System administrators have full access to all files
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        // 2. Lookup file owner
        $owner = $this->getFileOwner($fileId);
        if ($owner === null) {
            // Check storage of file to see if it belongs to personal user home
            $qb = $this->db->getQueryBuilder();
            $qb->select('storage', 'path')
               ->from('filecache')
               ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
            $fc = $qb->executeQuery()->fetchAssociative();
            if ($fc) {
                $sqb = $this->db->getQueryBuilder();
                $sqb->select('id')
                    ->from('storages')
                    ->where($sqb->expr()->eq('numeric_id', $sqb->createNamedParameter((int)$fc['storage'])));
                $sRow = $sqb->executeQuery()->fetchAssociative();
                if ($sRow && str_starts_with((string)$sRow['id'], 'home::')) {
                    $owner = substr((string)$sRow['id'], strlen('home::'));
                    $this->setFileOwner($fileId, $owner);
                }
            }
        }

        if ($owner !== null && $owner === $userId) {
            return true;
        }

        // 3. Check explicit admin grants in archive_file_grants
        $currentUser = $this->userSession->getUser();
        $userGroups = $currentUser !== null ? $this->groupManager->getUserGroupIds($currentUser) : [];
        $qb = $this->db->getQueryBuilder();
        $orConditions = [
            $qb->expr()->andX(
                $qb->expr()->eq('grantee_type', $qb->createNamedParameter('user')),
                $qb->expr()->eq('grantee_id', $qb->createNamedParameter($userId))
            )
        ];
        if (!empty($userGroups)) {
            $orConditions[] = $qb->expr()->andX(
                $qb->expr()->eq('grantee_type', $qb->createNamedParameter('group')),
                $qb->expr()->in('grantee_id', $qb->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }

        $qb->select('id')
           ->from('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->orX(...$orConditions));
        $grant = $qb->executeQuery()->fetchAssociative();
        if ($grant) {
            return true;
        }

        // 4. Check native Nextcloud shares where admin shared this specific file
        $qbShare = $this->db->getQueryBuilder();
        $shareOrConditions = [
            $qbShare->expr()->andX(
                $qbShare->expr()->eq('share_type', $qbShare->createNamedParameter(0)),
                $qbShare->expr()->eq('share_with', $qbShare->createNamedParameter($userId))
            )
        ];
        if (!empty($userGroups)) {
            $shareOrConditions[] = $qbShare->expr()->andX(
                $qbShare->expr()->eq('share_type', $qbShare->createNamedParameter(1)),
                $qbShare->expr()->in('share_with', $qbShare->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }

        $qbShare->select('id')
                ->from('share')
                ->where($qbShare->expr()->eq('item_source', $qbShare->createNamedParameter($fileId)))
                ->andWhere($qbShare->expr()->eq('uid_owner', $qbShare->createNamedParameter('admin')))
                ->andWhere($qbShare->expr()->orX(...$shareOrConditions));
        $share = $qbShare->executeQuery()->fetchAssociative();
        if ($share) {
            return true;
        }

        return false;
    }

    public function grantAccess(int $fileId, string $granteeId, bool $isGroup = false, string $grantedBy = 'admin', int $permissions = 31): void {
        $now = time();
        $type = $isGroup ? 'group' : 'user';

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter($type)))
           ->andWhere($qb->expr()->eq('grantee_id', $qb->createNamedParameter($granteeId)));
        $existing = $qb->executeQuery()->fetchAssociative();

        if ($existing) {
            $upQb = $this->db->getQueryBuilder();
            $upQb->update('archive_file_grants')
                 ->set('permissions', $upQb->createNamedParameter($permissions))
                 ->set('granted_by', $upQb->createNamedParameter($grantedBy))
                 ->where($upQb->expr()->eq('id', $upQb->createNamedParameter((int)$existing['id'])));
            $upQb->executeStatement();
        } else {
            $insQb = $this->db->getQueryBuilder();
            $insQb->insert('archive_file_grants')
                  ->values([
                      'file_id' => $insQb->createNamedParameter($fileId),
                      'grantee_type' => $insQb->createNamedParameter($type),
                      'grantee_id' => $insQb->createNamedParameter($granteeId),
                      'granted_by' => $insQb->createNamedParameter($grantedBy),
                      'permissions' => $insQb->createNamedParameter($permissions),
                      'created_at' => $insQb->createNamedParameter($now),
                  ]);
            $insQb->executeStatement();
        }
        $this->logger->info("archive_autotag: Granted file ID {$fileId} access to {$type} '{$granteeId}' by '{$grantedBy}'");
    }

    public function revokeAccess(int $fileId, string $granteeId, bool $isGroup = false): void {
        $type = $isGroup ? 'group' : 'user';
        $qb = $this->db->getQueryBuilder();
        $qb->delete('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter($type)))
           ->andWhere($qb->expr()->eq('grantee_id', $qb->createNamedParameter($granteeId)));
        $qb->executeStatement();
        $this->logger->info("archive_autotag: Revoked file ID {$fileId} access from {$type} '{$granteeId}'");
    }

    public function getGrants(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        return $qb->executeQuery()->fetchAllAssociative();
    }
}