<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Exception\TagInUseException;
use OCA\ArchiveAutoTag\Exception\TagDeletionException;
use OCA\ArchiveAutoTag\Service\GroupTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

class AdminTagController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GroupTagService $groupTagService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    private function checkAdmin(): ?DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Authentication required',
            ], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->groupTagService->isSystemAdmin($user->getUID())) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => "Forbidden: User '{$user->getUID()}' is not an authorized System Administrator.",
            ], Http::STATUS_FORBIDDEN);
        }

        return null;
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

    /**
     * List all tags across system with usage and scopes.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listAllTags(): DataResponse {
        $authError = $this->checkAdmin();
        if ($authError !== null) return $authError;

        $user = $this->userSession->getUser();
        try {
            $tags = $this->groupTagService->listAllTagsForAdmin($user->getUID());
            return new DataResponse([
                'status' => 'success',
                'tags' => $tags,
                'total' => count($tags),
            ], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error("AdminTagController::listAllTags error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new tag (system-wide or group-scoped).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createTag(): DataResponse {
        $authError = $this->checkAdmin();
        if ($authError !== null) return $authError;

        $user = $this->userSession->getUser();
        $params = $this->getJsonOrParams();
        $tagName = trim((string)($params['tag_name'] ?? $params['name'] ?? ''));
        $scope = trim((string)($params['scope'] ?? 'system'));
        $groupId = isset($params['group_id']) ? trim((string)$params['group_id']) : null;

        if ($tagName === '') {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Tag name is required.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->createAdminTag($user->getUID(), $scope, $groupId, $tagName);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tag created successfully',
                'data' => $res,
            ], Http::STATUS_OK);
        } catch (\DomainException $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error("AdminTagController::createTag error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete tag with safety check and cascading detachment.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteTag(): DataResponse {
        $authError = $this->checkAdmin();
        if ($authError !== null) return $authError;

        $user = $this->userSession->getUser();
        $params = $this->getJsonOrParams();
        $tagId = (int)($params['tag_id'] ?? $params['tagId'] ?? 0);
        $force = filter_var($params['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($tagId <= 0) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Tag ID is required.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->deleteAdminTag($user->getUID(), $tagId, $force);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tag deleted successfully',
                'data' => $res,
            ], Http::STATUS_OK);
        } catch (TagInUseException $e) {
            return new DataResponse([
                'status' => 'conflict',
                'code' => 'TAG_IN_USE',
                'tag_id' => $e->getTagId(),
                'usage_count' => $e->getUsageCount(),
                'message' => $e->getMessage(),
            ], Http::STATUS_CONFLICT);
        } catch (TagNotFoundException $e) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'TAG_NOT_FOUND',
                'message' => $e->getMessage(),
            ], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error("AdminTagController::deleteTag error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Assign any tag to any file or folder.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function assignTag(): DataResponse {
        $authError = $this->checkAdmin();
        if ($authError !== null) return $authError;

        $user = $this->userSession->getUser();
        $params = $this->getJsonOrParams();
        $tagId = (int)($params['tag_id'] ?? $params['tagId'] ?? 0);
        $fileId = (int)($params['file_id'] ?? $params['fileId'] ?? 0);

        if ($tagId <= 0 || $fileId <= 0) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Both tag_id and file_id are required.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->assignAdminTag($user->getUID(), $tagId, $fileId);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tag assigned successfully',
                'data' => $res,
            ], Http::STATUS_OK);
        } catch (TagNotFoundException $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error("AdminTagController::assignTag error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove any tag from any file or folder.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function removeTag(): DataResponse {
        $authError = $this->checkAdmin();
        if ($authError !== null) return $authError;

        $user = $this->userSession->getUser();
        $params = $this->getJsonOrParams();
        $tagId = (int)($params['tag_id'] ?? $params['tagId'] ?? 0);
        $fileId = (int)($params['file_id'] ?? $params['fileId'] ?? 0);

        if ($tagId <= 0 || $fileId <= 0) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Both tag_id and file_id are required.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->removeAdminTag($user->getUID(), $tagId, $fileId);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tag removed successfully',
                'data' => $res,
            ], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error("AdminTagController::removeTag error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Run global tag reconciliation.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reconcileTags(): DataResponse {
        $authError = $this->checkAdmin();
        if ($authError !== null) return $authError;

        $user = $this->userSession->getUser();
        try {
            $res = $this->groupTagService->reconcileAdminTags($user->getUID());
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tags reconciled successfully',
                'data' => $res,
            ], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error("AdminTagController::reconcileTags error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
