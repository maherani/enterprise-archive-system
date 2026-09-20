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
        private ?ReliableAuditService $reliableAuditService = null,
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
     * Delegated evaluation: delegates directly to CentralPermissionResolver
     * to eliminate duplicated, conflicting fallback logic.
     */
    public function canUserAccessFile($firstArg, $secondArg = null, int $requiredOperation = 1): bool {
        if (is_numeric($firstArg) && (is_string($secondArg) || $secondArg === null)) {
            $fileId = (int)$firstArg;
            $userId = $secondArg ?? ($this->userSession->getUser()?->getUID() ?? '');
        } else {
            $userId = (string)$firstArg;
            $fileId = (int)$secondArg;
        }

        if ($this->permissionResolver instanceof CentralPermissionResolver) {
            $decision = $this->permissionResolver->evaluateFile($userId, $fileId, $requiredOperation);
            return $decision->allowed;
        }

        // Fallback if resolver not yet initialized in container
        return $this->canUserAccessFileInternalFallback($userId, $fileId);
    }

    /**
     * Internal fallback only used if DI container has not wired CentralPermissionResolver
     */
    private function canUserAccessFileInternalFallback(string $userId, int $fileId): bool {
        // Superuser bypass
        $userManager = $this->userManager ?? \OC::$server->getUserManager();
        if ($userManager !== null) {
            $userObj = $userManager->get($userId);
            if ($userObj !== null && $this->groupManager->isAdmin($userId)) {
                return true;
            }
        }

        // Direct user grant
        $qb = $this->db->getQueryBuilder();
        $qb->select('permissions')
           ->from('archive_file_grants')
           ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
           ->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter('user')))
           ->andWhere($qb->expr()->eq('grantee_id', $qb->createNamedParameter($userId)));
        $row = $qb->executeQuery()->fetchAssociative();
        if ($row) {
            $p = (int)$row['permissions'];
            return ($p === 0) ? false : (($p & 1) !== 0);
        }

        // Group grant
        $userGroups = $this->getUserGroups($userId);
        if (!empty($userGroups)) {
            $gQb = $this->db->getQueryBuilder();
            $gQb->select('permissions')
                ->from('archive_file_grants')
                ->where($gQb->expr()->eq('file_id', $gQb->createNamedParameter($fileId)))
                ->andWhere($gQb->expr()->eq('grantee_type', $gQb->createNamedParameter('group')))
                ->andWhere($gQb->expr()->in('grantee_id', $gQb->createNamedParameter($userGroups, IQueryBuilder::PARAM_STR_ARRAY)));
            $gRows = $gQb->executeQuery()->fetchAllAssociative();
            foreach ($gRows as $gr) {
                $gp = (int)$gr['permissions'];
                if ($gp === 0) return false;
                if (($gp & 1) !== 0) return true;
            }
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
     * Concurrency-safe atomic grant management with transactional audit
     */
    public function grantAccess(
        int $fileId,
        string $granteeId,
        bool $isGroup = false,
        string $grantedBy = 'admin',
        int $permissions = 31,
        string $requestId = '',
        string $correlationId = '',
        string $clientIp = ''
    ): void {
        $now = time();
        $type = $isGroup ? 'group' : 'user';
        $reqId = $requestId !== '' ? $requestId : ('req_perm_' . bin2hex(random_bytes(6)));

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'permissions')
               ->from('archive_file_grants')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter($type)))
               ->andWhere($qb->expr()->eq('grantee_id', $qb->createNamedParameter($granteeId)));
            $existing = $qb->executeQuery()->fetchAssociative();
            $prevPerms = $existing ? (int)$existing['permissions'] : null;

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

            // Transactional Audit Logging (Audit-Required: fail-closed if write fails)
            if ($this->reliableAuditService !== null) {
                $this->reliableAuditService->recordRequired('archive_permission_audit', [
                    'request_id' => $reqId,
                    'correlation_id' => $correlationId,
                    'actor_uid' => $grantedBy,
                    'file_id' => $fileId,
                    'grantee_type' => $type,
                    'grantee_id' => $granteeId,
                    'action' => ($permissions === 0) ? 'revoke' : 'grant',
                    'permissions' => $permissions,
                    'prev_permissions' => $prevPerms,
                    'result' => 'success',
                    'client_ip' => $clientIp,
                    'created_at' => $now,
                ], $this->db);
            }

            $this->db->commit();
        } catch (\Throwable $t) {
            $this->db->rollBack();
            $this->logger->error("archive_autotag: Failed to grant access atomically: " . $t->getMessage());
            throw $t;
        }

        $this->logger->info("archive_autotag: Granted file ID {$fileId} access to {$type} '{$granteeId}' by '{$grantedBy}' (mask: {$permissions})");
    }

    /**
     * Revoke access.
     * If $explicitDeny is true, records permissions=0 so even owners/group members are blocked.
     * If $explicitDeny is false, completely purges the grant row.
     */
    public function revokeAccess(
        int $fileId,
        string $granteeId,
        bool $isGroup = false,
        bool $explicitDeny = true,
        string $revokedBy = 'admin',
        string $requestId = '',
        string $correlationId = '',
        string $clientIp = ''
    ): void {
        $type = $isGroup ? 'group' : 'user';
        if ($explicitDeny) {
            $this->grantAccess($fileId, $granteeId, $isGroup, $revokedBy, 0, $requestId, $correlationId, $clientIp);
            $this->logger->info("archive_autotag: Explicit revocation (permissions=0) recorded on file ID {$fileId} for {$type} '{$granteeId}'");
        } else {
            $this->purgeGrant($fileId, $granteeId, $isGroup, $revokedBy, $requestId, $correlationId, $clientIp);
        }
    }

    /**
     * Completely remove a grant row from archive_file_grants with transactional audit
     */
    public function purgeGrant(
        int $fileId,
        string $granteeId,
        bool $isGroup = false,
        string $purgedBy = 'admin',
        string $requestId = '',
        string $correlationId = '',
        string $clientIp = ''
    ): void {
        $type = $isGroup ? 'group' : 'user';
        $now = time();
        $reqId = $requestId !== '' ? $requestId : ('req_perm_' . bin2hex(random_bytes(6)));

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('permissions')
               ->from('archive_file_grants')
               ->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId)))
               ->andWhere($qb->expr()->eq('grantee_type', $qb->createNamedParameter($type)))
               ->andWhere($qb->expr()->eq('grantee_id', $qb->createNamedParameter($granteeId)));
            $existing = $qb->executeQuery()->fetchAssociative();
            $prevPerms = $existing ? (int)$existing['permissions'] : null;

            $delQb = $this->db->getQueryBuilder();
            $delQb->delete('archive_file_grants')
               ->where($delQb->expr()->eq('file_id', $delQb->createNamedParameter($fileId)))
               ->andWhere($delQb->expr()->eq('grantee_type', $delQb->createNamedParameter($type)))
               ->andWhere($delQb->expr()->eq('grantee_id', $delQb->createNamedParameter($granteeId)));
            $delQb->executeStatement();

            if ($this->reliableAuditService !== null && $existing) {
                $this->reliableAuditService->recordRequired('archive_permission_audit', [
                    'request_id' => $reqId,
                    'correlation_id' => $correlationId,
                    'actor_uid' => $purgedBy,
                    'file_id' => $fileId,
                    'grantee_type' => $type,
                    'grantee_id' => $granteeId,
                    'action' => 'purge',
                    'permissions' => 0,
                    'prev_permissions' => $prevPerms,
                    'result' => 'success',
                    'client_ip' => $clientIp,
                    'created_at' => $now,
                ], $this->db);
            }

            $this->db->commit();
        } catch (\Throwable $t) {
            $this->db->rollBack();
            $this->logger->error("archive_autotag: Failed to purge grant atomically: " . $t->getMessage());
            throw $t;
        }

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
