<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class GroupShareService {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly IRootFolder $rootFolder,
        private readonly IShareManager $shareManager,
        private readonly FileOwnershipService $fileOwnershipService,
        private readonly ReliableAuditService $reliableAuditService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if user is super admin.
     */
    public function isSystemAdmin(string $userId): bool {
        if ($userId === 'admin') {
            return true;
        }
        $user = $this->userManager->get($userId);
        if ($user !== null && $this->groupManager->isAdmin($userId)) {
            return true;
        }
        return false;
    }

    /**
     * Dynamically retrieve all available user groups for sharing.
     */
    public function getShareableGroups(string $actorUid): array {
        if (!$this->isSystemAdmin($actorUid)) {
            throw new SecurityPermissionException("Forbidden: Only system administrators can access group sharing catalog.");
        }

        $groups = $this->groupManager->search('');
        $result = [];
        foreach ($groups as $group) {
            if ($group instanceof IGroup) {
                $gid = $group->getGID();
                $displayName = method_exists($group, 'getDisplayName') ? $group->getDisplayName() : $gid;
                if (empty($displayName)) {
                    $displayName = $gid;
                }
                
                $lowerGid = strtolower(trim($gid));
                
                // Filter: Must NOT be 'admin'. Empty groups are allowed.
                $userCount = $group->count('');
                if ($lowerGid !== 'admin') {
                    $result[] = [
                        'id' => $gid,
                        'name' => $displayName,
                        'display_name' => $displayName,
                        'user_count' => $userCount,
                    ];
                }
            }
        }

        usort($result, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $result;
    }

    /**
     * Retrieve all active group shares on a given file or folder.
     */
    public function getResourceShares(int $fileId, string $actorUid): array {
        if (!$this->isSystemAdmin($actorUid)) {
            throw new SecurityPermissionException("Forbidden: Only system administrators can inspect resource shares.");
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'item_source', 'item_type', 'share_with', 'permissions', 'stime', 'file_target')
           ->from('share')
           ->where($qb->expr()->eq('item_source', $qb->createNamedParameter((string)$fileId)))
           ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(IShare::TYPE_GROUP)))
           ->orderBy('id', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $shares = [];
        foreach ($rows as $row) {
            $perms = (int)$row['permissions'];
            $groupId = (string)$row['share_with'];
            $groupObj = $this->groupManager->get($groupId);
            $displayName = $groupId;
            if ($groupObj) {
                $displayName = method_exists($groupObj, 'getDisplayName') ? $groupObj->getDisplayName() : $groupId;
                if (empty($displayName)) {
                    $displayName = $groupId;
                }
            }

            $shares[] = [
                'id' => (int)$row['id'],
                'file_id' => (int)$row['item_source'],
                'item_type' => (string)$row['item_type'],
                'group_id' => $groupId, // Keep raw GID for API operations
                'group_name' => $displayName,
                'group_display_name' => $displayName, // UI display name
                'permissions' => $perms,
                'permissions_details' => [
                    'read' => ($perms & 1) !== 0,
                    'update' => ($perms & 2) !== 0,
                    'create' => ($perms & 4) !== 0,
                    'delete' => ($perms & 8) !== 0,
                    'share' => ($perms & 16) !== 0,
                ],
                'created_at' => (int)$row['stime'],
                'file_target' => (string)($row['file_target'] ?? ''),
            ];
        }

        return $shares;
    }

    /**
     * Create or update a Group Share for a file or folder.
     * Uses native Nextcloud Share Manager (share_type = 1) and synchronizes with archive_file_grants.
     */
    public function createOrUpdateGroupShare(
        int $fileId,
        string $groupId,
        int $permissions,
        string $actorUid,
        string $requestId = '',
        string $correlationId = '',
        string $clientIp = ''
    ): array {
        if (!$this->isSystemAdmin($actorUid)) {
            throw new SecurityPermissionException("Forbidden: Only system administrators can create or modify group shares.");
        }

        $targetGroup = trim($groupId);
        if ($targetGroup === '' || !$this->groupManager->groupExists($targetGroup)) {
            throw new \InvalidArgumentException("Invalid target group '{$targetGroup}'. Group does not exist.");
        }

        // In Nextcloud, uploading a file with content requires UPDATE (2) permission. 
        // If the user requests CREATE (4), we automatically add UPDATE (2) to prevent "Could not create path" errors during upload.
        if (($permissions & 4) !== 0) {
            $permissions |= 2;
        }

        if ($permissions < 1 || $permissions > 31) {
            throw new \InvalidArgumentException("Invalid permissions bitmask '{$permissions}'. Must be between 1 and 31.");
        }

        // Locate node in root folder
        $nodes = $this->rootFolder->getById($fileId);
        if (empty($nodes)) {
            throw new \InvalidArgumentException("Resource with ID {$fileId} not found in storage.");
        }

        /** @var Node $node */
        $node = $nodes[0];
        $nodePath = $node->getPath();

        $reqId = $requestId !== '' ? $requestId : ('req_grp_share_' . bin2hex(random_bytes(6)));

        // Check if an existing share with this group already exists (Deterministic update per Section 14)
        $sQb = $this->db->getQueryBuilder();
        $sQb->select('id', 'permissions')
            ->from('share')
            ->where($sQb->expr()->eq('item_source', $sQb->createNamedParameter((string)$fileId)))
            ->andWhere($sQb->expr()->eq('share_type', $sQb->createNamedParameter(IShare::TYPE_GROUP)))
            ->andWhere($sQb->expr()->eq('share_with', $sQb->createNamedParameter($targetGroup)));
        $existing = $sQb->executeQuery()->fetchAssociative();

        $action = 'GROUP_SHARE_CREATED';
        $prevPerms = null;
        $shareId = 0;

        if ($existing) {
            $shareId = (int)$existing['id'];
            $prevPerms = (int)$existing['permissions'];
            $action = 'GROUP_SHARE_UPDATED';

            try {
                $share = $this->shareManager->getShareById((string)$shareId);
                $share->setPermissions($permissions);
                $this->shareManager->updateShare($share);
            } catch (\Throwable $t) {
                // Direct DB update fallback if share manager instance wrapper has caching nuance
                $upQb = $this->db->getQueryBuilder();
                $upQb->update('share')
                     ->set('permissions', $upQb->createNamedParameter($permissions))
                     ->where($upQb->expr()->eq('id', $upQb->createNamedParameter($shareId)));
                $upQb->executeStatement();
            }
        } else {
            try {
                $share = $this->shareManager->newShare();
                $share->setNode($node);
                $share->setShareType(IShare::TYPE_GROUP);
                $share->setSharedBy($actorUid);
                $share->setSharedWith($targetGroup);
                $share->setPermissions($permissions);
                $createdShare = $this->shareManager->createShare($share);
                $shareId = (int)$createdShare->getId();
            } catch (\Throwable $t) {
                $this->logger->error("GroupShareService: Failed to create share via manager: " . $t->getMessage());
                throw new \RuntimeException("Failed to create native Nextcloud group share: " . $t->getMessage(), 0, $t);
            }
        }

        // Synchronize with archive_file_grants (Archive MAC layer)
        $this->fileOwnershipService->grantAccess(
            $fileId,
            $targetGroup,
            true, // isGroup = true
            $actorUid,
            $permissions,
            $reqId,
            $correlationId,
            $clientIp
        );

        // Record audit event in ReliableAuditService
        try {
            $this->reliableAuditService->recordRequired('archive_permission_audit', [
                'request_id' => $reqId,
                'correlation_id' => $correlationId,
                'actor_uid' => $actorUid,
                'file_id' => $fileId,
                'grantee_type' => 'group',
                'grantee_id' => $targetGroup,
                'action' => $action,
                'permissions' => $permissions,
                'prev_permissions' => $prevPerms,
                'result' => 'success',
                'client_ip' => $clientIp,
                'created_at' => time(),
            ]);
        } catch (\Throwable $at) {
            $this->logger->warning("GroupShareService: Failed to record audit: " . $at->getMessage());
        }

        return [
            'status' => 'success',
            'action' => $action,
            'share_id' => $shareId,
            'file_id' => $fileId,
            'group_id' => $targetGroup,
            'permissions' => $permissions,
            'node_path' => $nodePath,
        ];
    }

    /**
     * Remove a Group Share.
     * Strictly removes the share relationship and grant without deleting the underlying physical resource.
     */
    public function removeGroupShare(
        int $shareId,
        string $actorUid,
        int $resourceId = 0,
        string $groupId = '',
        string $requestId = '',
        string $correlationId = '',
        string $clientIp = ''
    ): array {
        if (!$this->isSystemAdmin($actorUid)) {
            throw new SecurityPermissionException("Forbidden: Only system administrators can remove group shares.");
        }

        if ($shareId <= 0 && $resourceId > 0 && $groupId !== '') {
            $qbFind = $this->db->getQueryBuilder();
            $qbFind->select('id')
                ->from('share')
                ->where($qbFind->expr()->eq('item_source', $qbFind->createNamedParameter((string)$resourceId)))
                ->andWhere($qbFind->expr()->eq('share_type', $qbFind->createNamedParameter(1)))
                ->andWhere($qbFind->expr()->eq('share_with', $qbFind->createNamedParameter($groupId)));
            $found = $qbFind->executeQuery()->fetchAssociative();
            if ($found) {
                $shareId = (int)$found['id'];
            }
        }


        // Fetch share row from oc_share
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'item_source', 'share_with', 'permissions')
           ->from('share')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($shareId)));
        $shareRow = $qb->executeQuery()->fetchAssociative();

        if (!$shareRow) {
            throw new \InvalidArgumentException("Share with ID {$shareId} not found.");
        }

        $fileId = (int)$shareRow['item_source'];
        $groupId = (string)$shareRow['share_with'];
        $prevPerms = (int)$shareRow['permissions'];
        $reqId = $requestId !== '' ? $requestId : ('req_del_share_' . bin2hex(random_bytes(6)));

        // 1. Delete native Nextcloud share via IManager
        try {
            $share = $this->shareManager->getShareById((string)$shareId);
            $this->shareManager->deleteShare($share);
        } catch (\Throwable $t) {
            // Direct cleanup if already missing from manager cache
            $delQb = $this->db->getQueryBuilder();
            $delQb->delete('share')
                  ->where($delQb->expr()->eq('id', $delQb->createNamedParameter($shareId)));
            $delQb->executeStatement();
        }

        // 2. Synchronize removal in archive_file_grants
        $this->fileOwnershipService->purgeGrant(
            $fileId,
            $groupId,
            true, // isGroup = true
            $actorUid,
            $reqId,
            $correlationId,
            $clientIp
        );

        // 3. Record audit event
        try {
            $this->reliableAuditService->recordRequired('archive_permission_audit', [
                'request_id' => $reqId,
                'correlation_id' => $correlationId,
                'actor_uid' => $actorUid,
                'file_id' => $fileId,
                'grantee_type' => 'group',
                'grantee_id' => $groupId,
                'action' => 'GROUP_SHARE_REMOVED',
                'permissions' => 0,
                'prev_permissions' => $prevPerms,
                'result' => 'success',
                'client_ip' => $clientIp,
                'created_at' => time(),
            ]);
        } catch (\Throwable $at) {
            $this->logger->warning("GroupShareService: Failed to record audit: " . $at->getMessage());
        }

        return [
            'status' => 'success',
            'share_id' => $shareId,
            'file_id' => $fileId,
            'group_id' => $groupId,
            'message' => 'Group share removed successfully. Underlying resource preserved.',
        ];
    }
}
