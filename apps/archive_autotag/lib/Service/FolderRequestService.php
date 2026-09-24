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
use OCP\Notification\IManager as INotificationManager;
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
    private INotificationManager $notificationManager;
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
        INotificationManager $notificationManager,
        LoggerInterface $logger,
        private ?ReliableAuditService $reliableAuditService = null
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
        $this->notificationManager = $notificationManager;
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

        return $gids;
    }
    
    public function getSubadminGroupsDetails(string $userId): array {
        $details = [];
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('gid')
               ->from('group_admin')
               ->where($qb->expr()->eq('uid', $qb->createNamedParameter($userId)));
            $rows = $qb->executeQuery()->fetchAllAssociative();
            foreach ($rows as $row) {
                $gid = (string)$row['gid'];
                $group = $this->groupManager->get($gid);
                $displayName = $group && method_exists($group, 'getDisplayName') ? $group->getDisplayName() : $gid;
                $details[] = [
                    'id' => $gid,
                    'name' => $displayName ?: $gid
                ];
            }
        } catch (\Throwable $t) {
            $this->logger->warning("FolderRequestService::getSubadminGroupsDetails DB error: " . $t->getMessage());
        }
        return $details;
    }
    
    public function getMemberGroupsDetails(array $memberGroupIds): array {
        if (empty($memberGroupIds)) return [];
        $details = [];
        foreach ($memberGroupIds as $gid) {
            $group = $this->groupManager->get($gid);
            $displayName = $group && method_exists($group, 'getDisplayName') ? $group->getDisplayName() : $gid;
            $details[] = [
                'id' => $gid,
                'name' => $displayName ?: $gid
            ];
        }
        return $details;
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
    public function getAllGroupsDetails(): array {
        $details = [];
        try {
            $groups = $this->groupManager->search('');
            foreach ($groups as $g) {
                if ($g instanceof \OCP\IGroup) {
                    $gid = $g->getGID();
                    if (strtolower(trim($gid)) === 'admin') continue;
                    $displayName = method_exists($g, 'getDisplayName') ? $g->getDisplayName() : $gid;
                    $details[] = [
                        'id' => $gid,
                        'name' => !empty($displayName) ? $displayName : $gid,
                    ];
                }
            }
            usort($details, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        } catch (\Throwable $t) {
            $this->logger->warning("FolderRequestService::getAllGroupsDetails DB error: " . $t->getMessage());
        }
        return $details;
    }

    public function getUserRoleInfo(string $userId): array {
        $isAdmin = $this->isSystemAdmin($userId);
        $subadminGroups = $this->getSubadminGroups($userId);
        $subadminGroupsDetails = $this->getSubadminGroupsDetails($userId);
        $user = $this->userManager->get($userId);
        $memberGroups = $user !== null ? $this->groupManager->getUserGroupIds($user) : [];
        $memberGroupsDetails = $this->getMemberGroupsDetails($memberGroups);

        return [
            'user_id' => $userId,
            'is_admin' => $isAdmin,
            'is_group_admin' => !empty($subadminGroups),
            'subadmin_groups' => $subadminGroups,
            'subadmin_groups_details' => $subadminGroupsDetails,
            'member_groups' => $memberGroups,
            'member_groups_details' => $memberGroupsDetails,
            'all_groups_details' => $this->getAllGroupsDetails(),
        ];
    }

    /**
     * Validate that no duplicate pending request or physical folder exists for this path.
     */
    public function validateNoDuplicates(string $folderName, string $targetPath, string $groupId): void {
        // 1. Check if a pending request already exists for this exact path in this group
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('archive_folder_requests')
           ->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
           ->andWhere($qb->expr()->eq('target_path', $qb->createNamedParameter($targetPath)))
           ->andWhere($qb->expr()->eq('folder_name', $qb->createNamedParameter($folderName)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING)));
        $pendingRow = $qb->executeQuery()->fetchAssociative();

        if ($pendingRow) {
            $pendingId = (int)$pendingRow['id'];
            throw new \DomainException("درخواست دیگری برای ایجاد این مسیر (پوشه «{$folderName}») در حال حاضر با شناسه #{$pendingId} در وضعیت «در انتظار بررسی» (pending) ثبت شده است.");
        }

        // 2. Check if the folder physically exists in the archive filesystem
        try {
            $adminUser = $this->userManager->get('admin');
            if ($adminUser !== null) {
                $adminHome = $this->rootFolder->getUserFolder($adminUser->getUID());
                if ($adminHome->nodeExists('Enterprise_Archive')) {
                    $archiveRoot = $adminHome->get('Enterprise_Archive');
                    if ($archiveRoot instanceof Folder && $archiveRoot->nodeExists($groupId)) {
                        $groupBase = $archiveRoot->get($groupId);
                        if ($groupBase instanceof Folder) {
                            $checkDir = $groupBase;
                            if ($targetPath !== '') {
                                $segments = explode('/', $targetPath);
                                foreach ($segments as $seg) {
                                    $seg = trim($seg);
                                    if ($seg !== '' && $checkDir instanceof Folder && $checkDir->nodeExists($seg)) {
                                        $node = $checkDir->get($seg);
                                        if ($node instanceof Folder) {
                                            $checkDir = $node;
                                        }
                                    }
                                }
                            }
                            if ($checkDir instanceof Folder && $checkDir->nodeExists($folderName)) {
                                throw new \DomainException("پوشه‌ای با نام «{$folderName}» در مسیر مشخص‌شده برای گروه '{$groupId}' از قبل در ساختار آرشیو وجود فیزیکی دارد.");
                            }
                        }
                    }
                }
            }
        } catch (\DomainException $de) {
            throw $de;
        } catch (\Throwable $t) {
            $this->logger->warning("validateNoDuplicates filesystem check warning: " . $t->getMessage());
        }
    }

    /**
     * Record an audit event in the database and system audit log.
     */
    public function logAuditEvent(
        int $requestId,
        string $eventType,
        string $actorUid,
        string $groupId,
        string $folderName,
        string $folderPath,
        ?string $prevStatus = null,
        ?string $newStatus = null,
        ?string $details = null,
        ?string $rejectionReason = null,
        ?string $errorInfo = null,
        string $correlationId = '',
        string $clientIp = '',
        ?IDBConnection $conn = null
    ): void {
        $now = time();
        $auditData = [
            'request_id' => $requestId,
            'event_type' => $eventType,
            'actor_uid' => $actorUid,
            'group_id' => $groupId,
            'folder_name' => $folderName,
            'folder_path' => $folderPath,
            'prev_status' => $prevStatus,
            'new_status' => $newStatus,
            'details' => $details,
            'rejection_reason' => $rejectionReason,
            'error_info' => $errorInfo,
            'correlation_id' => $correlationId,
            'client_ip' => $clientIp,
            'created_at' => $now,
        ];

        // Folder Approval and Rejection events are Audit-Required (Fail-Closed)
        $isRequired = in_array($eventType, [
            'request_approved',
            'folder_created',
            'permissions_applied',
            'tag_created',
            'request_completed',
            'request_rejected',
        ], true);

        if ($this->reliableAuditService !== null) {
            if ($isRequired) {
                $this->reliableAuditService->recordRequired('archive_folder_request_audit', $auditData, $conn);
            } else {
                $this->reliableAuditService->recordBestEffort('archive_folder_request_audit', $auditData);
            }
        } else {
            try {
                $c = $conn ?? $this->db;
                $qb = $c->getQueryBuilder();
                $qb->insert('archive_folder_request_audit')
                   ->values([
                       'request_id' => $qb->createNamedParameter($requestId),
                       'event_type' => $qb->createNamedParameter($eventType),
                       'actor_uid' => $qb->createNamedParameter($actorUid),
                       'group_id' => $qb->createNamedParameter($groupId),
                       'folder_name' => $qb->createNamedParameter($folderName),
                       'folder_path' => $qb->createNamedParameter($folderPath),
                       'prev_status' => $qb->createNamedParameter($prevStatus),
                       'new_status' => $qb->createNamedParameter($newStatus),
                       'details' => $qb->createNamedParameter($details),
                       'rejection_reason' => $qb->createNamedParameter($rejectionReason),
                       'error_info' => $qb->createNamedParameter($errorInfo),
                       'created_at' => $qb->createNamedParameter($now),
                   ]);
                $qb->executeStatement();
            } catch (\Throwable $t) {
                $this->logger->error("archive_autotag: Failed to record audit event '{$eventType}' for request #{$requestId}: " . $t->getMessage());
                if ($isRequired) {
                    throw $t;
                }
            }
        }

        $this->logger->info("[archive_audit] request_id={$requestId} event={$eventType} actor={$actorUid} group={$groupId} folder='{$folderName}' prev={$prevStatus} new={$newStatus}");
    }

    /**
     * Send a Nextcloud native notification to the user.
     */
    private function sendNotificationToUser(string $recipientUid, string $subject, array $params, int $requestId): void {
        try {
            $recipient = $this->userManager->get($recipientUid);
            if ($recipient === null) {
                return;
            }

            $notification = $this->notificationManager->createNotification();
            $notification->setApp('archive_autotag')
                         ->setUser($recipientUid)
                         ->setDateTime(new \DateTime())
                         ->setObject('folder_request', (string)$requestId)
                         ->setSubject($subject, $params);

            $this->notificationManager->notify($notification);
            $this->logger->info("archive_autotag: Notification '{$subject}' dispatched to '{$recipientUid}' for request #{$requestId}.");
        } catch (\Throwable $t) {
            $this->logger->warning("archive_autotag: Could not dispatch notification to '{$recipientUid}': " . $t->getMessage());
        }
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

        // Duplicate Prevention Validation
        $this->validateNoDuplicates($folderName, $targetPath, $groupId);

        $description = trim($description);
        $now = time();

        try {
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
        } catch (\Throwable $t) {
            // Check for unique constraint violation on concurrent requests
            if (str_contains($t->getMessage(), 'arch_folder_req_pending_uniq_idx') || str_contains($t->getMessage(), 'unique')) {
                throw new \DomainException("درخواست دیگری برای ایجاد این مسیر هم‌اکنون به صورت همزمان ثبت شده و در انتظار بررسی است.");
            }
            throw $t;
        }

        // Record Audit Trail Events
        $this->logAuditEvent($id, 'request_created', $requesterUid, $groupId, $folderName, $targetPath, null, self::STATUS_PENDING, 'درخواست ایجاد پوشه جدید ثبت گردید.');
        $this->logAuditEvent($id, 'request_pending', $requesterUid, $groupId, $folderName, $targetPath, null, self::STATUS_PENDING, 'در انتظار بررسی و تأیید مدیر سیستم قرار گرفت.');

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
     * Get complete audit trail events for a request.
     */
    public function getRequestAuditTrail(int $id, string $currentUid): array {
        // Enforce access control on request
        $request = $this->getRequest($id, $currentUid);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('archive_folder_request_audit')
           ->where($qb->expr()->eq('request_id', $qb->createNamedParameter($id)))
           ->orderBy('id', 'ASC');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(function (array $r) {
            $gid = (string)$r['group_id'];
            $groupDisplayName = $gid;
            try {
                $g = $this->groupManager->get($gid);
                if ($g && method_exists($g, 'getDisplayName')) {
                    $d = $g->getDisplayName();
                    if (!empty($d)) $groupDisplayName = $d;
                }
            } catch (\Throwable $t) {}

            return [
                'id' => (int)$r['id'],
                'request_id' => (int)$r['request_id'],
                'event_type' => (string)$r['event_type'],
                'actor_uid' => (string)$r['actor_uid'],
                'group_id' => $gid,
                'group_name' => $groupDisplayName,
                'group_display_name' => $groupDisplayName,
                'folder_name' => (string)$r['folder_name'],
                'folder_path' => (string)$r['folder_path'],
                'prev_status' => $r['prev_status'] ? (string)$r['prev_status'] : null,
                'new_status' => $r['new_status'] ? (string)$r['new_status'] : null,
                'details' => $r['details'] ? (string)$r['details'] : null,
                'rejection_reason' => $r['rejection_reason'] ? (string)$r['rejection_reason'] : null,
                'error_info' => $r['error_info'] ? (string)$r['error_info'] : null,
                'created_at' => (int)$r['created_at'],
            ];
        }, $rows);
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
        $requesterUid = $request['requester_uid'];

        $createdFolder = null;
        $createdTag = null;
        $now = time();

        // Audit Event: Approval initiated
        $this->logAuditEvent($id, 'request_approved', $adminUid, $groupId, $folderName, $targetPath, self::STATUS_PENDING, self::STATUS_APPROVED, 'فرآیند بررسی و تأیید توسط مدیر سیستم آغاز گردید.');

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

            // Audit Event: Physical Folder Created
            $this->logAuditEvent($id, 'folder_created', $adminUid, $groupId, $folderName, $targetPath, 'pending', 'approved', "پوشه فیزیکی با شناسه {$folderId} ایجاد شد.");

            // 6. Inherit and Verify Group Share Permissions
            $this->ensureGroupShareExists($groupBase, $groupId, $createdFolder);

            // Audit Event: Permissions Applied
            $this->logAuditEvent($id, 'permissions_applied', $adminUid, $groupId, $folderName, $targetPath, 'pending', 'approved', "مجوزهای دسترسی گروه '{$groupId}' (Read+Create) فعال گردید.");

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

            // Audit Event: Tag Created & Bound
            $this->logAuditEvent($id, 'tag_created', $adminUid, $groupId, $folderName, $targetPath, 'pending', 'approved', "برچسب سیستمی اختصاصی '{$folderName}' با شناسه {$tagId} تولید و مقید شد.");

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

            // Audit Event: Completed
            $this->logAuditEvent($id, 'request_completed', $adminUid, $groupId, $folderName, $targetPath, self::STATUS_PENDING, self::STATUS_APPROVED, 'درخواست با موفقیت تایید و تکمیل شد.');

            // Dispatch Native Nextcloud Notification to Group Admin
            $this->sendNotificationToUser($requesterUid, 'folder_request_approved', [
                'folderName' => $folderName,
                'groupId' => $groupId,
                'groupName' => ($request['group_display_name'] ?? $groupId),
                'targetPath' => $targetPath,
                'adminUid' => $adminUid,
            ], $id);

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

            // Audit Event: Failed / Error
            $this->logAuditEvent($id, 'request_failed', $adminUid, $groupId, $folderName, $targetPath, self::STATUS_PENDING, self::STATUS_FAILED, null, null, $e->getMessage());

            // Notify Requester about Error
            $this->sendNotificationToUser($requesterUid, 'folder_request_failed', [
                'folderName' => $folderName,
                'groupId' => $groupId,
                'groupName' => ($request['group_display_name'] ?? $groupId),
                'error' => $e->getMessage(),
            ], $id);

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
        $this->db->beginTransaction();
        try {
            $upQb = $this->db->getQueryBuilder();
            $upQb->update('archive_folder_requests')
                 ->set('status', $upQb->createNamedParameter(self::STATUS_REJECTED))
                 ->set('reviewer_uid', $upQb->createNamedParameter($adminUid))
                 ->set('reviewed_at', $upQb->createNamedParameter($now))
                 ->set('updated_at', $upQb->createNamedParameter($now))
                 ->set('rejection_reason', $upQb->createNamedParameter($reason))
                 ->where($upQb->expr()->eq('id', $upQb->createNamedParameter($id)));
            $upQb->executeStatement();

            // Audit Event: Rejected (Audit-Required: fails-closed if audit write fails)
            $this->logAuditEvent($id, 'request_rejected', $adminUid, $request['group_id'], $request['folder_name'], $request['target_path'], self::STATUS_PENDING, self::STATUS_REJECTED, 'درخواست ایجاد پوشه توسط مدیر ارشد سیستم رد شد.', $reason, null, '', '', $this->db);

            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("archive_autotag: Failed to reject folder request #{$id} atomically: " . $t->getMessage());
            throw $t;
        }

        // Notify Group Admin
        $this->sendNotificationToUser($request['requester_uid'], 'folder_request_rejected', [
            'folderName' => $request['folder_name'],
            'groupId' => $request['group_id'],
            'groupName' => ($request['group_display_name'] ?? $request['group_id']),
            'reason' => $reason,
            'adminUid' => $adminUid,
        ], $id);

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
     * Get all available archive directories for a specific group to populate parent folder selections.
     * Returns a list of relative paths from the group's archive root.
     *
     * @return array<int, array{path: string, name: string, display: string, level: int}>
     */
    public function getGroupFolders(string $groupId): array {
        $folders = [
            [
                'path' => '',
                'name' => 'ریشه گروه',
                'display' => '📁 ریشه گروه (اصلی)',
                'level' => 0,
            ]
        ];

        try {
            $adminUser = $this->userManager->get('admin');
            if ($adminUser === null) {
                return $folders;
            }

            $adminHome = $this->rootFolder->getUserFolder($adminUser->getUID());
            if (!$adminHome->nodeExists('Enterprise_Archive')) {
                return $folders;
            }

            $archiveRoot = $adminHome->get('Enterprise_Archive');
            if (!($archiveRoot instanceof Folder) || !$archiveRoot->nodeExists($groupId)) {
                return $folders;
            }

            $groupBase = $archiveRoot->get($groupId);
            if (!($groupBase instanceof Folder)) {
                return $folders;
            }

            // Recursive scan helper
            $scan = function (Folder $dir, string $relativePrefix, int $level) use (&$scan, &$folders) {
                $nodes = $dir->getDirectoryListing();
                usort($nodes, function ($a, $b) {
                    return strcmp($a->getName(), $b->getName());
                });

                foreach ($nodes as $node) {
                    if ($node instanceof Folder) {
                        $name = $node->getName();
                        $relPath = $relativePrefix === '' ? $name : $relativePrefix . '/' . $name;
                        $indent = str_repeat("  ", $level - 1);
                        $folders[] = [
                            'path' => $relPath,
                            'name' => $name,
                            'display' => ($level > 1 ? $indent . '↳ ' : '') . '📁 ' . ($level > 1 ? str_replace('/', ' / ', $relPath) : $name),
                            'level' => $level,
                        ];
                        // Recurse into subfolder
                        $scan($node, $relPath, $level + 1);
                    }
                }
            };

            $scan($groupBase, '', 1);

        } catch (\Throwable $t) {
            $this->logger->error("archive_autotag: Failed to get group folders for '{$groupId}': " . $t->getMessage(), ['exception' => $t]);
        }

        return $folders;
    }

    /**
     * Helper to cast DB row to standard format.
     */
    private function formatRequestRow(array $row): array {
        $gid = (string)$row['group_id'];
        $groupDisplayName = $gid;
        try {
            $group = $this->groupManager->get($gid);
            if ($group && method_exists($group, 'getDisplayName')) {
                $d = $group->getDisplayName();
                if (!empty($d)) {
                    $groupDisplayName = $d;
                }
            }
        } catch (\Throwable $t) {}

        $ruid = (string)$row['requester_uid'];
        $requesterDisplayName = $ruid;
        try {
            $user = $this->userManager->get($ruid);
            if ($user && method_exists($user, 'getDisplayName')) {
                $ud = $user->getDisplayName();
                if (!empty($ud)) {
                    $requesterDisplayName = $ud;
                }
            }
        } catch (\Throwable $t) {}

        $reviewerUid = $row['reviewer_uid'] ? (string)$row['reviewer_uid'] : null;
        $reviewerDisplayName = $reviewerUid;
        if ($reviewerUid !== null) {
            try {
                $revUser = $this->userManager->get($reviewerUid);
                if ($revUser && method_exists($revUser, 'getDisplayName')) {
                    $rd = $revUser->getDisplayName();
                    if (!empty($rd)) {
                        $reviewerDisplayName = $rd;
                    }
                }
            } catch (\Throwable $t) {}
        }

        return [
            'id' => (int)$row['id'],
            'folder_name' => (string)$row['folder_name'],
            'target_path' => (string)$row['target_path'],
            'description' => (string)($row['description'] ?? ''),
            'group_id' => $gid,
            'group_name' => $groupDisplayName,
            'group_display_name' => $groupDisplayName,
            'requester_uid' => $ruid,
            'requester_name' => $requesterDisplayName,
            'requester_display_name' => $requesterDisplayName,
            'status' => (string)$row['status'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'reviewer_uid' => $reviewerUid,
            'reviewer_name' => $reviewerDisplayName,
            'reviewer_display_name' => $reviewerDisplayName,
            'reviewed_at' => $row['reviewed_at'] ? (int)$row['reviewed_at'] : null,
            'rejection_reason' => $row['rejection_reason'] ? (string)$row['rejection_reason'] : null,
            'error_message' => $row['error_message'] ? (string)$row['error_message'] : null,
            'created_folder_id' => $row['created_folder_id'] ? (int)$row['created_folder_id'] : null,
            'created_tag_id' => $row['created_tag_id'] ? (int)$row['created_tag_id'] : null,
        ];
    }

    /**
     * Get all folders within Enterprise_Archive recursively for system administration.
     *
     * @return array<int, array{path: string, name: string, display: string, level: int}>
     */
    public function getAllArchiveFolders(): array {
        $folders = [
            [
                'path' => '',
                'name' => 'ریشه آرشیو سازمانی',
                'display' => '🏛️ ریشه آرشیو سازمانی (Enterprise_Archive)',
                'level' => 0,
            ]
        ];

        try {
            $adminUser = $this->userManager->get('admin');
            if ($adminUser === null) {
                return $folders;
            }

            $adminHome = $this->rootFolder->getUserFolder($adminUser->getUID());
            if (!$adminHome->nodeExists('Enterprise_Archive')) {
                return $folders;
            }

            $archiveRoot = $adminHome->get('Enterprise_Archive');
            if (!($archiveRoot instanceof Folder)) {
                return $folders;
            }

            $scan = function (Folder $dir, string $relativePrefix, int $level) use (&$scan, &$folders) {
                $nodes = $dir->getDirectoryListing();
                usort($nodes, function ($a, $b) {
                    return strcmp($a->getName(), $b->getName());
                });

                foreach ($nodes as $node) {
                    if ($node instanceof Folder) {
                        $name = $node->getName();
                        $relPath = $relativePrefix === '' ? $name : $relativePrefix . '/' . $name;
                        $indent = str_repeat("  ", $level - 1);
                        $folders[] = [
                            'path' => $relPath,
                            'name' => $name,
                            'display' => ($level > 1 ? $indent . '↳ ' : '') . '📁 ' . ($level > 1 ? str_replace('/', ' / ', $relPath) : $name),
                            'level' => $level,
                        ];
                        $scan($node, $relPath, $level + 1);
                    }
                }
            };

            $scan($archiveRoot, '', 1);
        } catch (\Throwable $t) {
            $this->logger->error("archive_autotag: Failed to get all archive folders: " . $t->getMessage(), ['exception' => $t]);
        }

        return $folders;
    }

    /**
     * Directly create a folder in Enterprise_Archive by Administrator.
     * Atomically creates the directory on disk, applies permissions, binds group tag, and reconciles system tags.
     */
    public function createFolderDirectly(string $folderName, string $parentPath, ?string $groupId, string $adminUid): array {
        if (!$this->isSystemAdmin($adminUid)) {
            throw new SecurityPermissionException("Forbidden: Only System Administrators can directly create archive folders.");
        }

        $folderName = trim($folderName);
        if ($folderName === '') {
            throw new \InvalidArgumentException("نام پوشه نمی‌تواند خالی باشد.");
        }

        if (preg_match('[/\\\\]', $folderName)) {
            throw new \InvalidArgumentException("نام پوشه نمی‌تواند حاوی کاراکترهای اسلش باشد.");
        }

        $adminUser = $this->userManager->get('admin') ?? $this->userManager->get($adminUid);
        if ($adminUser === null) {
            throw new \RuntimeException("حساب کاربری مدیر سیستم یافت نشد.");
        }

        $adminHome = $this->rootFolder->getUserFolder($adminUser->getUID());
        $archiveRoot = $adminHome->nodeExists('Enterprise_Archive')
            ? $adminHome->get('Enterprise_Archive')
            : $adminHome->newFolder('Enterprise_Archive');

        if (!($archiveRoot instanceof Folder)) {
            throw new \RuntimeException("Enterprise_Archive یک پوشه معتبر نیست.");
        }

        // Navigate to target parent directory
        $currentDir = $archiveRoot;
        $parentPath = trim($parentPath, '/');
        if (str_starts_with($parentPath, 'Enterprise_Archive')) {
            $parentPath = trim(substr($parentPath, strlen('Enterprise_Archive')), '/');
        }

        if ($parentPath !== '') {
            $segments = explode('/', $parentPath);
            foreach ($segments as $seg) {
                $seg = trim($seg);
                if ($seg === '') {
                    continue;
                }
                $currentDir = $currentDir->nodeExists($seg)
                    ? $currentDir->get($seg)
                    : $currentDir->newFolder($seg);

                if (!($currentDir instanceof Folder)) {
                    throw new \RuntimeException("مسیر والد '{$seg}' یک پوشه نیست.");
                }
            }
        }

        if ($currentDir->nodeExists($folderName)) {
            throw new \DomainException("پوشه‌ای با نام «{$folderName}» در این مسیر از قبل وجود دارد.");
        }

        $createdFolder = $currentDir->newFolder($folderName);
        $folderId = $createdFolder->getId();

        // If groupId specified, ensure group share exists and tag is bound
        if ($groupId !== null && $groupId !== '' && $groupId !== 'all') {
            $groupBase = $archiveRoot->nodeExists($groupId)
                ? $archiveRoot->get($groupId)
                : $archiveRoot->newFolder($groupId);
            $this->ensureGroupShareExists($groupBase, $groupId, $createdFolder);

            $createdTag = $this->autoTagService->getOrCreateRestrictedTag($folderName);
            $tagId = (int)$createdTag->getId();
            $this->tagOwnershipService->assignTagToGroup($tagId, $groupId, $adminUid);
        } else {
            // General system tag
            $createdTag = $this->autoTagService->getOrCreateRestrictedTag($folderName);
        }

        // Reconcile tags for the hierarchy
        $this->autoTagService->handleFolderCreated($createdFolder);

        $this->logger->info("archive_autotag: Admin '{$adminUid}' directly created archive folder '{$folderName}' under '{$parentPath}'.");

        return [
            'status' => 'success',
            'message' => "پوشه «{$folderName}» با موفقیت در ساختار آرشیو ایجاد شد.",
            'folder_id' => $folderId,
            'path' => $createdFolder->getPath(),
        ];
    }

}
