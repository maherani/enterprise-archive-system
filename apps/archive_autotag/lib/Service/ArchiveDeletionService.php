<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Security\Permission\CentralPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\PermissionOperation;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ArchiveDeletionService {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IRootFolder $rootFolder,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly CentralPermissionResolver $permissionResolver,
        private readonly DocumentMetadataService $metadataService,
        private readonly FileOwnershipService $fileOwnershipService,
        private readonly AutoTagService $autoTagService,
        private readonly ReliableAuditService $auditService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Check whether actor has system administrator privileges
     */
    public function isSystemAdmin(string $userId): bool {
        if ($userId === 'admin') {
            return true;
        }
        $user = $this->userManager->get($userId);
        return $user !== null && $this->groupManager->isAdmin($userId);
    }

    /**
     * Delete an archive file or directory securely with authorization, cascading cleanup and audit.
     *
     * @param int $fileId
     * @param string|null $folderPath
     * @param string $actorUid
     * @param string $clientIp
     * @param string $requestId
     * @param string $correlationId
     * @return array
     * @throws SecurityPermissionException
     * @throws \InvalidArgumentException
     */
    public function deleteResource(
        int $fileId,
        ?string $folderPath,
        string $actorUid,
        string $clientIp = '',
        string $requestId = '',
        string $correlationId = ''
    ): array {
        $reqId = $requestId !== '' ? $requestId : ('req_del_' . bin2hex(random_bytes(6)));

        // 1. Role Gate: Strictly System Administrators
        if (!$this->isSystemAdmin($actorUid)) {
            try {
                $this->auditService->recordBestEffort('archive_permission_audit', [
                    'request_id' => $reqId,
                    'correlation_id' => $correlationId,
                    'actor_uid' => $actorUid,
                    'file_id' => $fileId,
                    'grantee_type' => 'resource',
                    'grantee_id' => (string)$fileId,
                    'action' => 'DELETE_DENIED',
                    'permissions' => PermissionOperation::DELETE,
                    'prev_permissions' => null,
                    'result' => 'denied',
                    'client_ip' => $clientIp,
                    'error_info' => 'Non-admin user attempted archive deletion',
                    'created_at' => time(),
                ]);
            } catch (\Throwable $t) {}

            throw new SecurityPermissionException("دسترسی غیرمجاز: تنها مدیران ارشد سیستم مجاز به حذف فایل یا پوشه هستند.");
        }

        // 2. Early Guard against Root Archive Deletion by Path
        if (!empty($folderPath)) {
            $norm = trim(trim($folderPath, '/'), '.');
            if ($norm === '' ||
                strcasecmp($norm, 'Enterprise_Archive') === 0 ||
                strcasecmp($norm, 'files/Enterprise_Archive') === 0 ||
                str_ends_with(strtolower($norm), '/enterprise_archive')) {
                throw new \InvalidArgumentException("عملیات غیرمجاز: ریشه بایگانی سازمانی قابل حذف نمی‌باشد.");
            }
        }

        // 3. Node Resolution
        $node = null;
        if ($fileId > 0) {
            $nodes = $this->rootFolder->getById($fileId);
            if (!empty($nodes)) {
                $node = $nodes[0];
            }
        }

        if ($node === null && !empty($folderPath)) {
            $cleanP = trim(trim($folderPath, '/'), '.');
            try {
                $userFolder = $this->rootFolder->getUserFolder($actorUid);
                if ($userFolder->nodeExists($cleanP)) {
                    $node = $userFolder->get($cleanP);
                }
            } catch (\Throwable $t) {}

            if ($node === null) {
                try {
                    $node = $this->rootFolder->get($cleanP);
                } catch (\Throwable $t) {
                    try {
                        $node = $this->rootFolder->get('files/' . $cleanP);
                    } catch (\Throwable $t2) {}
                }
            }
        }

        if ($node === null) {
            throw new \InvalidArgumentException("منبع مورد نظر با شناسه یا مسیر مشخص‌شده در سامانه ذخیره‌سازی یافت نشد.");
        }

        $isFolder = ($node instanceof Folder);
        $nodePath = $node->getPath();
        $nodeName = $node->getName();
        $effectiveFileId = ($fileId > 0) ? $fileId : (int)$node->getId();

        // 4. Guard against Root Archive Deletion by Node
        $cleanPath = trim(trim($nodePath, '/'), '.');
        $nodeNameLower = strtolower($nodeName);
        if ($cleanPath === '' ||
            $cleanPath === '.' ||
            $cleanPath === 'files' ||
            strcasecmp($cleanPath, 'Enterprise_Archive') === 0 ||
            strcasecmp($cleanPath, 'files/Enterprise_Archive') === 0 ||
            str_ends_with(strtolower($cleanPath), '/enterprise_archive') ||
            $nodeNameLower === 'enterprise_archive') {
            throw new \InvalidArgumentException("عملیات غیرمجاز: ریشه بایگانی سازمانی قابل حذف نمی‌باشد.");
        }

        // 5. Authorization Check via CentralPermissionResolver
        $decision = $isFolder
            ? $this->permissionResolver->evaluateFolder($actorUid, $nodePath, PermissionOperation::DELETE)
            : $this->permissionResolver->evaluateFile($actorUid, $effectiveFileId, PermissionOperation::DELETE);

        if (!$decision->allowed) {
            throw new SecurityPermissionException("دسترسی رد شد: " . $decision->reason);
        }

        $now = time();
        $this->db->beginTransaction();
        try {
            // 6. Cascading Cleanup
            if ($isFolder) {
                // Find folder entry in filecache to get its exact storage and internal path
                $folderRowQb = $this->db->getQueryBuilder();
                $folderRowQb->select('storage', 'path')
                    ->from('filecache')
                    ->where($folderRowQb->expr()->eq('fileid', $folderRowQb->createNamedParameter($effectiveFileId)));
                $folderRow = $folderRowQb->executeQuery()->fetchAssociative();

                if ($folderRow) {
                    $fStorage = (int)$folderRow['storage'];
                    $fPath = rtrim((string)$folderRow['path'], '/') . '/';

                    $fqb = $this->db->getQueryBuilder();
                    $fqb->select('fileid')
                        ->from('filecache')
                        ->where($fqb->expr()->eq('storage', $fqb->createNamedParameter($fStorage)))
                        ->andWhere($fqb->expr()->like('path', $fqb->createNamedParameter($fPath . '%')));
                    $descendantRows = $fqb->executeQuery()->fetchAllAssociative();

                    foreach ($descendantRows as $row) {
                        $cId = (int)$row['fileid'];
                        $this->metadataService->deleteMetadata($cId, $actorUid);
                        $this->fileOwnershipService->deleteFileOwner($cId);
                        $this->fileOwnershipService->purgeAllGrants($cId);

                        $sqb = $this->db->getQueryBuilder();
                        $sqb->delete('share')
                            ->where($sqb->expr()->eq('item_source', $sqb->createNamedParameter((string)$cId)))
                            ->executeStatement();
                    }
                }
            }

            // Cleanup for the target resource itself
            $this->metadataService->deleteMetadata($effectiveFileId, $actorUid);
            $this->fileOwnershipService->deleteFileOwner($effectiveFileId);
            $this->fileOwnershipService->purgeAllGrants($effectiveFileId);

            $sqb = $this->db->getQueryBuilder();
            $sqb->delete('share')
                ->where($sqb->expr()->eq('item_source', $sqb->createNamedParameter((string)$effectiveFileId)))
                ->executeStatement();

            // 7. Physical Node Deletion via Nextcloud API
            $node->delete();

            // 8. Transactional Reliable Audit Log
            $action = $isFolder ? 'FOLDER_DELETE' : 'FILE_DELETE';
            $this->auditService->recordRequired('archive_permission_audit', [
                'request_id' => $reqId,
                'correlation_id' => $correlationId,
                'actor_uid' => $actorUid,
                'file_id' => $effectiveFileId,
                'grantee_type' => $isFolder ? 'folder' : 'file',
                'grantee_id' => $nodeName,
                'action' => $action,
                'permissions' => PermissionOperation::DELETE,
                'prev_permissions' => null,
                'result' => 'success',
                'client_ip' => $clientIp,
                'error_info' => json_encode([
                    'path' => $nodePath,
                    'name' => $nodeName,
                    'is_folder' => $isFolder,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ], $this->db);

            $this->db->commit();
        } catch (\Throwable $t) {
            $this->db->rollBack();
            $this->logger->error("ArchiveDeletionService: Failed to delete resource ID {$effectiveFileId}: " . $t->getMessage());
            throw $t;
        }

        $this->logger->info("ArchiveDeletionService: Successfully deleted resource ID {$effectiveFileId} ('{$nodeName}') by '{$actorUid}'");

        return [
            'status' => 'success',
            'message' => 'منبع با موفقیت به صورت کامل و دائمی حذف شد.',
            'deleted_id' => $effectiveFileId,
            'deleted_name' => $nodeName,
            'is_folder' => $isFolder,
        ];
    }
}
