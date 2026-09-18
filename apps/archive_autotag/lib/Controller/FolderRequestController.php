<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Service\FolderRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class FolderRequestController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private FolderRequestService $folderRequestService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get role and permission profile for current authenticated user.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getUserRole(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $roleInfo = $this->folderRequestService->getUserRoleInfo($user->getUID());
        return new DataResponse([
            'status' => 'success',
            'role' => $roleInfo,
        ]);
    }

    /**
     * Submit a new folder creation request (Group Admins only).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(
        string $folder_name = '',
        string $target_path = '',
        string $description = '',
        string $group_id = ''
    ): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            // Read JSON body params if empty in parameters
            $body = $this->request->getParams();
            $folderName = $folder_name !== '' ? $folder_name : (string)($body['folder_name'] ?? '');
            $targetPath = $target_path !== '' ? $target_path : (string)($body['target_path'] ?? '');
            $desc = $description !== '' ? $description : (string)($body['description'] ?? '');
            $groupId = $group_id !== '' ? $group_id : (string)($body['group_id'] ?? '');

            $created = $this->folderRequestService->createRequest(
                $folderName,
                $targetPath,
                $desc,
                $groupId,
                $user->getUID()
            );

            return new DataResponse([
                'status' => 'success',
                'message' => 'Folder creation request submitted successfully and is pending admin review.',
                'request' => $created,
            ], Http::STATUS_CREATED);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List folder requests filtered by user role and query parameters.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(?string $group = null, ?string $status = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $requests = $this->folderRequestService->listRequests($user->getUID(), $group, $status);
            return new DataResponse([
                'status' => 'success',
                'requests' => $requests,
                'count' => count($requests),
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show single folder request details.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(int $id): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $request = $this->folderRequestService->getRequest($id, $user->getUID());
            return new DataResponse([
                'status' => 'success',
                'request' => $request,
            ]);
        } catch (NotFoundException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get complete audit trail events for a single request.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function auditTrail(int $id): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $auditTrail = $this->folderRequestService->getRequestAuditTrail($id, $user->getUID());
            return new DataResponse([
                'status' => 'success',
                'request_id' => $id,
                'audit_trail' => $auditTrail,
                'count' => count($auditTrail),
            ]);
        } catch (NotFoundException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Approve folder creation request and atomically create folder and tag (System Admin only).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function approve(int $id): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $updated = $this->folderRequestService->approveRequest($id, $user->getUID());
            return new DataResponse([
                'status' => 'success',
                'message' => 'Folder created, permissions inherited, and group-bound tag registered successfully.',
                'request' => $updated,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\DomainException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reject folder creation request with reason (System Admin only).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reject(int $id, string $reason = ''): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $body = $this->request->getParams();
            $rejectionReason = $reason !== '' ? $reason : (string)($body['reason'] ?? '');

            $updated = $this->folderRequestService->rejectRequest($id, $user->getUID(), $rejectionReason);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Folder creation request rejected.',
                'request' => $updated,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get folder hierarchy for a specific group (for parent path dropdown).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroupFolders(string $group_id = ''): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $groupId = $group_id !== '' ? $group_id : (string)$this->request->getParam('group_id', '');
        if ($groupId === '') {
            return new DataResponse(['status' => 'error', 'message' => 'شناسه گروه الزامی است.'], Http::STATUS_BAD_REQUEST);
        }

        $roleInfo = $this->folderRequestService->getUserRoleInfo($user->getUID());

        // Admin can view any group folders; Group Admin can only view their own subadmin groups
        if (!$roleInfo['is_admin'] && !in_array($groupId, $roleInfo['subadmin_groups'], true)) {
            return new DataResponse(['status' => 'error', 'message' => 'دسترسی به پوشه‌های این گروه برای شما مجاز نیست.'], Http::STATUS_FORBIDDEN);
        }

        $folders = $this->folderRequestService->getGroupFolders($groupId);
        return new DataResponse([
            'status' => 'success',
            'group_id' => $groupId,
            'folders' => $folders,
        ]);
    }

    /**
     * Get all folders in Enterprise_Archive for Admin parent directory selection.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getAllFolders(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        $roleInfo = $this->folderRequestService->getUserRoleInfo($user->getUID());
        if (!$roleInfo['is_admin']) {
            return new DataResponse(['status' => 'error', 'message' => 'تنها مدیر سیستم مجاز به دریافت لیست کامل پوشه‌های آرشیو است.'], Http::STATUS_FORBIDDEN);
        }

        $folders = $this->folderRequestService->getAllArchiveFolders();
        return new DataResponse([
            'status' => 'success',
            'folders' => $folders,
        ]);
    }

    /**
     * Directly create a folder in Enterprise_Archive by System Admin.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createDirect(string $folder_name = '', string $parent_path = '', string $group_id = ''): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Unauthenticated.'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $folderName = $folder_name !== '' ? $folder_name : (string)$this->request->getParam('folder_name', '');
            $parentPath = $parent_path !== '' ? $parent_path : (string)$this->request->getParam('parent_path', '');
            $groupId = $group_id !== '' ? $group_id : (string)$this->request->getParam('group_id', '');

            $result = $this->folderRequestService->createFolderDirectly($folderName, $parentPath, $groupId, $user->getUID());
            return new DataResponse($result);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\DomainException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $t) {
            return new DataResponse(['status' => 'error', 'message' => $t->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

}
