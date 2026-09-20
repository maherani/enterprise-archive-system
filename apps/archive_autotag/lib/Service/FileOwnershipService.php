<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCA\ArchiveAutoTag\Security\Permission\CentralPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\IPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\PermissionOperation;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class FileOwnershipService {
    public function __construct(
        private IDBConnection $db,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private ?IUserManager $userManager = null,
        private ?IPermissionResolver $permissionResolver = null,
    ) {
    }

    /**
     * Concurrency-safe atomic registration of file creator/owner
     */
    public function setFileOwner(int $fileId, string $ownerUid): void {
        $now = time();
        try {
            // Attempt atomic insert first
            $insQb = $this->db->getQueryBuilder();
            $insQb->insert('archive_file_ownership')
                  ->values([
                      'file_id' => $insQb->createNamedParameter($fileId),
                      'owner_uid' => $insQb->createNamedParameter($ownerUid),
                      'created_at' => $insQb->createNamedParameter($now),
                  ]);
            $insQb->executeStatement();
        } catch (\Throwable $t) {
            // On unique constraint violation or existing record, update
            try {
                $upQb = $this->db->getQueryBuilder();
                $upQb->update('archive_file_ownership')
                     ->set('owner_uid', $upQb->createNamedParameter($ownerUid))
                     ->where($upQb->expr()->eq('file_id', $upQb->createNamedParameter($fileId)));
                $upQb->executeStatement();
            } catch (\Throwable $upErr) {
                $this->logger->error("FileOwnershipService: Failed to update owner for file {$fileId}: " . $upErr->getMessage());
            }
        }
        $this->logger->info("archive_autotag: File ID {$fileId} registered with owner: {$ownerUid}");
    }

    public function getFileOwner(int $fileId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_uid')
           ->from('archive_file_ownership')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (string)$row['owner_uid'] : null;
    }

    /**
     * Optimized single-batch ancestor folder lookup
     */
    public function getAncestorFolderIds(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('storage', 'path')
           ->from('filecache')
           ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
        $row = $qb->executeQuery()->fetchAssociative();
        if (!$row || empty($row['path'])) {
            return [];
        }

        $storage = (int)$row['storage'];
        $parts = explode('/', trim((string)$row['path'], '/'));
        array_pop($parts); // Remove file name

        if (empty($parts)) {
            return [];
        }

        $ancestorPaths = [];
        while (!empty($parts)) {
            $ancestorPaths[] = implode('/', $parts);
            array_pop($parts);
        }

        $aqb = $this->db->getQueryBuilder();
        $aqb->select('fileid')
            ->from('filecache')
            ->where($aqb->expr()->eq('storage', $aqb->createNamedParameter($storage)))
            ->andWhere($aqb->expr()->in('path', $aqb->createNamedParameter($ancestorPaths, IQueryBuilder::PARAM_STR_ARRAY)));
        $res = $aqb->executeQuery();
        $ancestorIds = [];
        while ($aRow = $res->fetchAssociative()) {
            $ancestorIds[] = (int)$aRow['fileid'];
        }
        return $ancestorIds;
    }

    /**
     * Determine effective user access.
     * Delegates 100% to CentralPermissionResolver as the Single Source of Truth.
     */
    public function canUserAccessFile(int $fileId, ?string $userId = null): bool {
        if ($fileId <= 0) {
            return false;
        }

        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // Deny unauthenticated access (Strict Fail-Close)
                return false;
            }
            $userId = $user->getUID();
        }

        // Primary: Injected or Container-resolved CentralPermissionResolver
        if ($this->permissionResolver !== null) {
            return $this->permissionResolver->can($userId, $fileId, PermissionOperation::READ);
        }

        try {
            $resolver = \OC::$server->get(CentralPermissionResolver::class);
            if ($resolver instanceof IPermissionResolver) {
                $this->permissionResolver = $resolver;
                return $resolver->can($userId, $fileId, PermissionOperation::READ);
            }
        } catch (\Throwable $t) {
            $this->logger->debug("FileOwnershipService: CentralPermissionResolver not available via container, using bootstrap fallback: " . $t->getMessage());
        }

        // Strict Bootstrap Fallback: Identical precedence pipeline
        return $this->evaluateBootstrapFallback($userId, $fileId);
    }

    /**
     * Bootstrap fallback executing strict precedence when CentralPermissionResolver is not yet in container
     */
    private function evaluateBootstrapFallback(string $userId, int $fileId): bool {
        // Rule 1: Admin Superuser Bypass
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'storage', 'path', 'mimetype', 'parent')
           ->from('filecache')
           ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId)));
        $fc = $qb->executeQuery()->fetchAssociative();
        if (!$fc) {
            return false; // Fail-closed
        }

        $filePath = (string)$fc['path'];
        $storageId = (int)$fc['storage'];
        $userGroups = $this->getUserGroups($userId);
        $ancestorIds = $this->getAncestorFolderIds($fileId);

        // Detect department from path
        $deptGroup = null;
        $cleanPath = trim(str_replace('\\', '/', $filePath), '/');
        $parts = explode('/', $cleanPath);
        foreach ($parts as $idx => $segment) {
            if (strcasecmp($segment, 'Enterprise_Archive') === 0 && isset($parts[$idx + 1])) {
                $deptGroup = $parts[$idx + 1];
                break;
            }
        }
        if ($deptGroup === null) {
            foreach ($parts as $segment) {
                if ($segment === 'files' || $segment === '' || $segment === '.') continue;
                if ($this->groupManager->groupExists($segment)) {
                    $deptGroup = $segment;
                    break;
                }
                break;
            }
        }

        // Rule 2: MAC Layer - Explicit Grants & Revocations
        $eligibleGrantIds = array_merge([$fileId], $ancestorIds);
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

        $gQb = $this->db->getQueryBuilder();
        $gQb->select('id', 'file_id', 'permissions')
            ->from('archive_file_grants')
            ->where($gQb->expr()->in('file_id', $gQb->createNamedParameter($eligibleGrantIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($gQb->expr()->orX(...$orConditions))
            ->orderBy('id', 'DESC');
        $grant = $gQb->executeQuery()->fetchAssociative();

        if ($grant) {
            $mask = (int)$grant['permissions'];
            if ($mask === 0) {
                return false; // Explicit Revocation / Deny trumps all!
            }
            return ($mask & PermissionOperation::READ) !== 0;
        }

        // Rule 3: File Ownership
        $owner = $this->getFileOwner($fileId);
        if ($owner === null && $storageId > 0) {
            $sqb = $this->db->getQueryBuilder();
            $sqb->select('id')
                ->from('storages')
                ->where($sqb->expr()->eq('numeric_id', $sqb->createNamedParameter($storageId)));
            $sRow = $sqb->executeQuery()->fetchAssociative();
            if ($sRow && str_starts_with((string)$sRow['id'], 'home::')) {
                $owner = substr((string)$sRow['id'], strlen('home::'));
                $this->setFileOwner($fileId, $owner);
            }
        }

        if ($owner !== null && $owner === $userId) {
            // Hierarchy constraint trumps individual ownership!
            if ($deptGroup !== null && !in_array($deptGroup, $userGroups, true)) {
                return false;
            }
            return true;
        }

        // Rule 4: Native Share
        $eligibleShareSourceIds = array_map('strval', array_merge([$fileId], $ancestorIds));
        $qbShare = $this->db->getQueryBuilder();
        $shareOrConditions = [
            $qbShare->expr()->andX(
                $qbShare->expr()->in('share_type', $qbShare->createNamedParameter([0, 2], IQueryBuilder::PARAM_INT_ARRAY)),
                $qbShare->expr()->eq('share_with', $qbShare->createNamedParameter($userId))
            )
        ];
        if (!empty($userGroups)) {
            $shareOrConditions[] = $qbShare->expr()->andX(
                $qbShare->expr()->eq('share_type', $qbShare->createNamedParameter(1)),
                $qbShare->expr()->in('share_with', $qbShare->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }

        $qbShare->select('id', 'permissions')
                ->from('share')
                ->where($qbShare->expr()->in('item_source', $qbShare->createNamedParameter($eligibleShareSourceIds, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qbShare->expr()->orX(...$shareOrConditions));
        $share = $qbShare->executeQuery()->fetchAssociative();
        if ($share) {
            return ((int)$share['permissions'] & 1) !== 0;
        }

        // Rule 5: Department Scope
        if ($deptGroup !== null && in_array($deptGroup, $userGroups, true)) {
            return true;
        }

        // Fail-closed default
        return false;
    }

    private function getUserGroups(string $userId): array {
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null && $currentUser->getUID() === $userId) {
            return $this->groupManager->getUserGroupIds($currentUser);
        }
        $userManager = $this->userManager ?? \OC::$server->getUserManager();
        if ($userManager !== null) {
            $uObj = $userManager->get($userId);
            if ($uObj !== null) {
                return $this->groupManager->getUserGroupIds($uObj);
            }
        }
        return [];
    }

    /**
     * Concurrency-safe atomic grant management
     */
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
            try {
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
            } catch (\Throwable $t) {
                $upQb = $this->db->getQueryBuilder();
                $upQb->update('archive_file_grants')
                     ->set('permissions', $upQb->createNamedParameter($permissions))
                     ->set('granted_by', $upQb->createNamedParameter($grantedBy))
                     ->where($upQb->expr()->eq('file_id', $upQb->createNamedParameter($fileId)))
                     ->andWhere($upQb->expr()->eq('grantee_type', $upQb->createNamedParameter($type)))
                     ->andWhere($upQb->expr()->eq('grantee_id', $upQb->createNamedParameter($granteeId)));
                $upQb->executeStatement();
            }
        }
        $this->logger->info("archive_autotag: Granted file ID {$fileId} access to {$type} '{$granteeId}' by '{$grantedBy}' (mask: {$permissions})");
    }

    /**
     * Revoke access.
     * If $explicitDeny is true, records permissions=0 so even owners/group members are blocked.
     * If $explicitDeny is false, completely purges the grant row.
     */
    public function revokeAccess(int $fileId, string $granteeId, bool $isGroup = false, bool $explicitDeny = true): void {
        $type = $isGroup ? 'group' : 'user';
        if ($explicitDeny) {
            $this->grantAccess($fileId, $granteeId, $isGroup, 'admin', 0);
            $this->logger->info("archive_autotag: Explicit revocation (permissions=0) recorded on file ID {$fileId} for {$type} '{$granteeId}'");
        } else {
            $this->purgeGrant($fileId, $granteeId, $isGroup);
        }
    }

    /**
     * Completely remove a grant row from archive_file_grants
     */
    public function purgeGrant(int $fileId, string $granteeId, bool $isGroup = false): void {
        $type = $isGroup ? 'group' : 'user';
        $qb = $this->db->getQueryBuilder();
        $qb->delete('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter($type)))
           ->andWhere($qb->expr()->eq('grantee_id', $qb->createNamedParameter($granteeId)));
        $qb->executeStatement();
        $this->logger->info("archive_autotag: Purged grant record on file ID {$fileId} for {$type} '{$granteeId}'");
    }

    public function getGrants(int $fileId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)));
        return $qb->executeQuery()->fetchAllAssociative();
    }
}
