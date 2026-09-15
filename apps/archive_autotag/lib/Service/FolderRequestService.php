<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\TagOwnershipService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTagManager;

class FolderRequestService {
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    private IDBConnection $db;
    private IGroupManager $groupManager;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private IShareManager $shareManager;
    private AutoTagService $autoTagService;
    private TagOwnershipService $tagOwnershipService;
    private ISystemTagManager $tagManager;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        IGroupManager $groupManager,
        IUserManager $userManager,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IShareManager $shareManager,
        AutoTagService $autoTagService,
        TagOwnershipService $tagOwnershipService,
        ISystemTagManager $tagManager,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->shareManager = $shareManager;
        $this->autoTagService = $autoTagService;
        $this->tagOwnershipService = $tagOwnershipService;
        $this->tagManager = $tagManager;
        $this->logger = $logger;
    }

    /**
     * Check if user is system administrator (group 'admin' or admin user).
     */
    public function isSystemAdmin(?string $userId = null): bool {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return false;
            }
            $userId = $user->getUID();
        }

        return $userId === 'admin'
            || $this->groupManager->isAdmin($userId)
            || $this->groupManager->isInGroup($userId, 'admin');
    }

    /**
     * Get list of groups where given user is subadmin / group admin.
     */
    public function getSubadminGroups(string $userId): array {
        $gids = [];

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gid')
               ->from('group_admin')
               ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $gid = (string)$row['gid'];
                if (!in_array($gid, $gids, true)) {
                    $gids[] = $gid;
                }
            }
        } catch (\Throwable $t) {
            $this->logger->warning("FolderRequestService::getSubadminGroups DB error: " . $t->getMessage());
        }

// Subadmin groups retrieved directly from group_admin table

        return $gids;
    }

    /**
     * Check if user is an admin of at least one group.
     */
    public function isGroupAdmin(string $userId): bool {
        return !empty($this->getSubadminGroups($userId));
    }

    /**
     * Return complete user role profile for client state and permissions.
     */
    public function getUserRoleInfo(string $userId): array {
        $isAdmin = $this->isSystemAdmin($userId);
        $subadminGroups = $this->getSubadminGroups($userId);
        $user = $this->userManager->get($userId);
        $memberGroups = $user !== null ? $this->groupManager->getUserGroupIds($user) : [];

        return [
            'user_id' => $userId,
            'is_admin' => $isAdmin,
            'is_group_admin' => !empty($subadminGroups),
            'subadmin_groups' => $subadminGroups,
            'member_groups' => $memberGroups,
        ];
    }

    /**
     * Submit a new folder creation request.
     * Only group administrators can submit for their own group.
     */
    public function createRequest(
        string $folderName,
        string $targetPath,
        string $description,
        string $groupId,
        string $requesterUid
    ): array {
        if ($this->isSystemAdmin($requesterUid)) {
            throw new \InvalidArgumentException("System administrators create folders directly and do not need approval workflow.");
        }

        $subadminGroups = $this->getSubadminGroups($requesterUid);
        if (!in_array($groupId, $subadminGroups, true)) {
            throw new SecurityPermissionException("Access Denied: You are not an authorized administrator for group '{$groupId}'.");
        }

        $folderName = trim($folderName);
        if ($folderName === '') {
            throw new \InvalidArgumentException("Folder name cannot be empty.");
        }

        if (preg_match('/[\/\\\\<>:"|?*\\x00]/', $folderName) || $folderName === '.' || $folderName === '..') {
            throw new \InvalidArgumentException("Folder name contains invalid characters.");
        }

        if (mb_strlen($folderName) > 200) {
            throw new \InvalidArgumentException("Folder name exceeds maximum allowed length (200 characters).");
        }

        $targetPath = trim(str_replace('\\', '/', $targetPath), '/');
        if (str_contains($targetPath, '..')) {
            throw new \InvalidArgumentException("Invalid directory path traversal detected.");
        }

        $description = trim($description);
        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('archive_folder_requests')
           ->values([
               'folder_name' => $qb->createNamedParameter($folderName),
               'target_path' => $qb->createNamedParameter($targetPath),
               'description' => $qb->createNamedParameter($description),
               'group_id' => $qb->createNamedParameter($groupId),
               'requester_uid' => $qb->createNamedParameter($requesterUid),
               'status' => $qb->createNamedParameter(self::STATUS_PENDING),
               'created_at' => $qb->createNamedParameter($now),
               'updated_at' => $qb->createNamedParameter($now),
           ]);
        $qb->executeStatement();
        $id = (int)$this->db->lastInsertId('archive_folder_requests');

        $this->logger->info("archive_autotag: Folder request #{$id} created by '{$requesterUid}' for group '{$groupId}': '{$folderName}'");

        return $this->getRequest($id, $requesterUid);
    }

    /**
     * List requests filtered by permissions.
     */
    public function listRequests(
        string $currentUid,
        ?string $filterGroup = null,
        ?string $filterStatus = null
    ): array {
        $isAdmin = $this->isSystemAdmin($currentUid);
        $subadminGroups = $this->getSubadminGroups($currentUid);

        if (!$isAdmin && empty($subadminGroups)) {
            throw new SecurityPermissionException("Access Denied: Regular users are not permitted to access folder requests.");
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_folder_requests');

        if (!$isAdmin) {
            // Group admin: Strictly restricted to their own subadmin groups
            if ($filterGroup !== null && in_array($filterGroup, $subadminGroups, true)) {
                $qb->where($qb->expr()->eq('group_id', $qb->createNamedParameter($filterGroup)));
            } else {
                $qb->where($qb->expr()->in(
                    'group_id',
                    $qb->createNamedParameter($subadminGroups, IQueryBuilder::PARAM_STR_ARRAY)
                ));
            }
        } else {
            // System Admin: Can filter by any group
            if ($filterGroup !== null && $filterGroup !== '' && $filterGroup !== 'all') {
                $qb->where($qb->expr()->eq('group_id', $qb->createNamedParameter($filterGroup)));
            }
        }

        if ($filterStatus !== null && $filterStatus !== '' && $filterStatus !== 'all') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filterStatus)));
        }

        $qb->orderBy('id', 'DESC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map([$this, 'formatRequestRow'], $rows);
    }

    /**
     * Fetch a single request by ID with permission checks.
     */
    public function getRequest(int $id, string $currentUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_folder_requests')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        $row = $qb->executeQuery()->fetchAssociative();

        if (!$row) {
            throw new NotFoundException("Folder request #{$id} not found.");
        }

        if (!$this->isSystemAdmin($currentUid)) {
            $subadminGroups = $this->getSubadminGroups($currentUid);
            if (!in_array((string)$row['group_id'], $subadminGroups, true)) {
                throw new SecurityPermissionException("Access Denied: You do not have permission to view this folder request.");
            }
        }

        return $this->formatRequestRow($row);
    }

    /**
     * Approve folder creation request and atomically create folder, permissions, and group-bound tag.
     * Can ONLY be performed by System Admin.
     */
    public function approveRequest(int $id, string $adminUid): array {
        if (!$this->isSystemAdmin($adminUid)) {
            throw new SecurityPermissionException("Forbidden: Only System Administrators can approve folder creation requests.");
        }

        $request = $this->getRequest($id, $adminUid);
        if ($request['status'] !== self::STATUS_PENDING) {
            throw new \DomainException("Cannot approve request #{$id}: Current status is '{$request['status']}' (expected 'pending').");
        }

        $folderName = $request['folder_name'];
        $groupId = $request['group_id'];
        $targetPath = $request['target_path'];

        $createdFolder = null;
        $createdTag = null;
        $now = time();

        try {
            // 1. Resolve Master Admin User Folder
            $adminUser = $this->userManager->get('admin') ?? $this->userManager->get($adminUid);
            if ($adminUser === null) {
                throw new \RuntimeException("Admin user account could not be resolved.");
            }

            $adminHome = $this->rootFolder->getUserFolder($adminUser->getUID());

            // 2. Ensure Enterprise_Archive base directory exists
            $archiveRoot = $adminHome->nodeExists('Enterprise_Archive')
                ? $adminHome->get('Enterprise_Archive')
                : $adminHome->newFolder('Enterprise_Archive');

            if (!($archiveRoot instanceof Folder)) {
                throw new \RuntimeException("Enterprise_Archive is not a directory.");
            }

            // 3. Ensure Group Base Directory (e.g. Enterprise_Archive/SOC)
            $groupBase = $archiveRoot->nodeExists($groupId)
                ? $archiveRoot->get($groupId)
                : $archiveRoot->newFolder($groupId);

            if (!($groupBase instanceof Folder)) {
                throw new \RuntimeException("Group directory '{$groupId}' is not a directory.");
            }

            // 4. Traverse/create targetPath subdirectories if specified
            $currentDir = $groupBase;
            if ($targetPath !== '') {
                $segments = explode('/', $targetPath);
                foreach ($segments as $seg) {
                    $seg = trim($seg);
                    if ($seg === '') {
                        continue;
                    }
                    $currentDir = $currentDir->nodeExists($seg)
                        ? $currentDir->get($seg)
                        : $currentDir->newFolder($seg);

                    if (!($currentDir instanceof Folder)) {
                        throw new \RuntimeException("Path segment '{$seg}' is not a directory.");
                    }
                }
            }

            // 5. Create Target Folder (or reuse if already exists)
            if ($currentDir->nodeExists($folderName)) {
                $createdFolder = $currentDir->get($folderName);
                if (!($createdFolder instanceof Folder)) {
                    throw new \RuntimeException("An item named '{$folderName}' already exists and is not a folder.");
                }
            } else {
                $createdFolder = $currentDir->newFolder($folderName);
            }

            $folderId = $createdFolder->getId();

            // 6. Inherit and Verify Group Share Permissions
            $this->ensureGroupShareExists($groupBase, $groupId, $createdFolder);

            // 7. Atomic Tag Creation & Group Binding
            $createdTag = $this->autoTagService->getOrCreateRestrictedTag($folderName);
            $tagId = (int)$createdTag->getId();

            // Bind tag strictly to requesting group
            $this->tagOwnershipService->assignTagToGroup($tagId, $groupId);
            $this->tagOwnershipService->setTagOwner($tagId, 'system');

            // Attach tag to the new folder object itself
            try {
                $this->tagManager->tagObject((string)$folderId, 'files', (string)$tagId);
            } catch (\Throwable $t) {
                // Ignore if already tagged
            }

            // 8. Update request status to approved
            $upQb = $this->db->getQueryBuilder();
            $upQb->update('archive_folder_requests')
                 ->set('status', $upQb->createNamedParameter(self::STATUS_APPROVED))
                 ->set('reviewer_uid', $upQb->createNamedParameter($adminUid))
                 ->set('reviewed_at', $upQb->createNamedParameter($now))
                 ->set('updated_at', $upQb->createNamedParameter($now))
                 ->set('created_folder_id', $upQb->createNamedParameter($folderId))
                 ->set('created_tag_id', $upQb->createNamedParameter($tagId))
                 ->set('error_message', $upQb->createNamedParameter(null))
                 ->where($upQb->expr()->eq('id', $upQb->createNamedParameter($id)));
            $upQb->executeStatement();

            $this->logger->info("archive_autotag: Folder request #{$id} APPROVED by '{$adminUid}'. Created folder ID {$folderId}, Tag ID {$tagId}.");

            return $this->getRequest($id, $adminUid);
        } catch (\Throwable $e) {
            // Failure Compensation: Record failed status with error details
            $this->logger->error("archive_autotag: Error approving folder request #{$id}: " . $e->getMessage());

            $errQb = $this->db->getQueryBuilder();
            $errQb->update('archive_folder_requests')
                  ->set('status', $errQb->createNamedParameter(self::STATUS_FAILED))
                  ->set('reviewer_uid', $errQb->createNamedParameter($adminUid))
                  ->set('reviewed_at', $errQb->createNamedParameter($now))
                  ->set('updated_at', $errQb->createNamedParameter($now))
                  ->set('error_message', $errQb->createNamedParameter($e->getMessage()))
                  ->where($errQb->expr()->eq('id', $errQb->createNamedParameter($id)));
            $errQb->executeStatement();

            throw $e;
        }
    }

    /**
     * Reject a folder request with mandatory reason.
     * Can ONLY be performed by System Admin.
     */
    public function rejectRequest(int $id, string $adminUid, string $reason): array {
        if (!$this->isSystemAdmin($adminUid)) {
            throw new SecurityPermissionException("Forbidden: Only System Administrators can reject folder creation requests.");
        }

        $request = $this->getRequest($id, $adminUid);
        if ($request['status'] !== self::STATUS_PENDING) {
            throw new \DomainException("Cannot reject request #{$id}: Current status is '{$request['status']}' (expected 'pending').");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException("Rejection reason is mandatory and cannot be empty.");
        }

        $now = time();
        $upQb = $this->db->getQueryBuilder();
        $upQb->update('archive_folder_requests')
             ->set('status', $upQb->createNamedParameter(self::STATUS_REJECTED))
             ->set('reviewer_uid', $upQb->createNamedParameter($adminUid))
             ->set('reviewed_at', $upQb->createNamedParameter($now))
             ->set('updated_at', $upQb->createNamedParameter($now))
             ->set('rejection_reason', $upQb->createNamedParameter($reason))
             ->where($upQb->expr()->eq('id', $upQb->createNamedParameter($id)));
        $upQb->executeStatement();

        $this->logger->info("archive_autotag: Folder request #{$id} REJECTED by '{$adminUid}'. Reason: '{$reason}'");

        return $this->getRequest($id, $adminUid);
    }

    /**
     * Ensure the folder tree is shared with the group according to enterprise permissions policy.
     */
    private function ensureGroupShareExists(Folder $groupBase, string $groupId, Folder $newFolder): void {
        try {
            $shares = $this->shareManager->getSharesBy($groupBase->getOwner()->getUID(), \OCP\Share\Constants::SHARE_TYPE_GROUP, $groupBase, true, -1);
            $isShared = false;
            foreach ($shares as $share) {
                if ($share->getSharedWith() === $groupId) {
                    $isShared = true;
                    break;
                }
            }

            if (!$isShared) {
                $newShares = $this->shareManager->getSharesBy($newFolder->getOwner()->getUID(), \OCP\Share\Constants::SHARE_TYPE_GROUP, $newFolder, true, -1);
                foreach ($newShares as $s) {
                    if ($s->getSharedWith() === $groupId) {
                        $isShared = true;
                        break;
                    }
                }

                if (!$isShared) {
                    $share = $this->shareManager->newShare();
                    $share->setNode($groupBase);
                    $share->setShareType(\OCP\Share\Constants::SHARE_TYPE_GROUP);
                    $share->setSharedWith($groupId);
                    $share->setPermissions(\OCP\Constants::PERMISSION_READ | \OCP\Constants::PERMISSION_CREATE);
                    $share->setShareOwner($groupBase->getOwner()->getUID());
                    $this->shareManager->createShare($share);
                    $this->logger->info("archive_autotag: Created group share for '{$groupId}' on '{$groupBase->getName()}'.");
                }
            }
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Group share verification warning: " . $t->getMessage());
        }
    }

    /**
     * Helper to cast DB row to standard format.
     */
    private function formatRequestRow(array $row): array {
        return [
            'id' => (int)$row['id'],
            'folder_name' => (string)$row['folder_name'],
            'target_path' => (string)$row['target_path'],
            'description' => (string)($row['description'] ?? ''),
            'group_id' => (string)$row['group_id'],
            'requester_uid' => (string)$row['requester_uid'],
            'status' => (string)$row['status'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'reviewer_uid' => $row['reviewer_uid'] ? (string)$row['reviewer_uid'] : null,
            'reviewed_at' => $row['reviewed_at'] ? (int)$row['reviewed_at'] : null,
            'rejection_reason' => $row['rejection_reason'] ? (string)$row['rejection_reason'] : null,
            'error_message' => $row['error_message'] ? (string)$row['error_message'] : null,
            'created_folder_id' => $row['created_folder_id'] ? (int)$row['created_folder_id'] : null,
            'created_tag_id' => $row['created_tag_id'] ? (int)$row['created_tag_id'] : null,
        ];
    }
}