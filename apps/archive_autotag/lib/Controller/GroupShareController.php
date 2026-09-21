<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Service\GroupShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class GroupShareController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GroupShareService $groupShareService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get dynamic list of available groups for sharing.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getGroups(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $groups = $this->groupShareService->getShareableGroups($user->getUID());
            return new DataResponse([
                'status' => 'success',
                'groups' => $groups,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error("GroupShareController::getGroups error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List active group shares for a resource.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getResourceShares($file_id = null, $resource_id = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $fileId = (int)($file_id ?? $resource_id ?? $this->request->getParam('file_id') ?? $this->request->getParam('resource_id') ?? 0);
        if ($fileId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Missing parameter: file_id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $shares = $this->groupShareService->getResourceShares($fileId, $user->getUID());
            return new DataResponse([
                'status' => 'success',
                'file_id' => $fileId,
                'shares' => $shares,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error("GroupShareController::getResourceShares error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create or update a group share.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function shareWithGroup(
        $file_id = null,
        ?string $group_id = null,
        $permissions = null
    ): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->groupShareService->isSystemAdmin($user->getUID())) {
            return new DataResponse(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'Forbidden: Only system administrators can share resources with groups.'], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getJsonOrParams();
        $fileId = (int)($file_id ?? $body['file_id'] ?? $body['resource_id'] ?? 0);
        $groupId = trim((string)($group_id ?? $body['group_id'] ?? ''));
        $perms = (int)($permissions ?? $body['permissions'] ?? 1);

        if ($fileId <= 0 || $groupId === '') {
            return new DataResponse(['status' => 'error', 'message' => 'Missing parameters: file_id/resource_id, group_id'], Http::STATUS_BAD_REQUEST);
        }

        $reqId = (string)($body['request_id'] ?? $this->request->getHeader('X-Request-ID') ?? '');
        $corrId = (string)($body['correlation_id'] ?? $this->request->getHeader('X-Correlation-ID') ?? '');
        $clientIp = (string)($this->request->getRemoteAddress() ?? '');

        try {
            $res = $this->groupShareService->createOrUpdateGroupShare(
                $fileId,
                $groupId,
                $perms,
                $user->getUID(),
                $reqId,
                $corrId,
                $clientIp
            );
            return new DataResponse([
                'status' => 'success',
                'message' => 'Resource shared with group successfully',
                'share_id' => $res['share_id'] ?? null,
                'action' => $res['action'] ?? null,
                'data' => $res,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['status' => 'error', 'code' => 'BAD_REQUEST', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error("GroupShareController::shareWithGroup error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove a group share.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function removeShare($share_id = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->groupShareService->isSystemAdmin($user->getUID())) {
            return new DataResponse(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => 'Forbidden: Only system administrators can remove group shares.'], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getJsonOrParams();
        $shareId = (int)($share_id ?? $body['share_id'] ?? 0);
        $resourceId = (int)($body['resource_id'] ?? $body['file_id'] ?? 0);
        $groupId = trim((string)($body['group_id'] ?? ''));

        if ($shareId <= 0 && ($resourceId <= 0 || $groupId === '')) {
            return new DataResponse(['status' => 'error', 'message' => 'Missing parameter: share_id or (resource_id and group_id)'], Http::STATUS_BAD_REQUEST);
        }

        $reqId = (string)($body['request_id'] ?? $this->request->getHeader('X-Request-ID') ?? '');
        $corrId = (string)($body['correlation_id'] ?? $this->request->getHeader('X-Correlation-ID') ?? '');
        $clientIp = (string)($this->request->getRemoteAddress() ?? '');

        try {
            $res = $this->groupShareService->removeGroupShare(
                $shareId,
                $user->getUID(),
                $resourceId,
                $groupId,
                $reqId,
                $corrId,
                $clientIp
            );
            return new DataResponse([
                'status' => 'success',
                'message' => 'Group share removed successfully',
                'data' => $res,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'code' => 'FORBIDDEN', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['status' => 'error', 'code' => 'NOT_FOUND', 'message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error("GroupShareController::removeShare error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'code' => 'INTERNAL_ERROR', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function getJsonOrParams(): array {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $this->request->getParams();
    }
}
