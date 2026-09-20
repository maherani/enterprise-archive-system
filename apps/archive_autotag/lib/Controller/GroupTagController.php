<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Service\GroupTagService;
use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Exception\TagInUseException;
use OCA\ArchiveAutoTag\Exception\TagDeletionException;
use OCP\SystemTag\TagNotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class GroupTagController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private GroupTagService $groupTagService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List all tags applicable to group.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listTags(?string $group_id = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $groupId = trim((string)($group_id ?? $this->request->getParam('group_id', '')));
        if ($groupId === '') {
            $adminGroups = $this->groupTagService->getUserAdminGroups($user->getUID());
            if (!empty($adminGroups)) {
                $groupId = $adminGroups[0];
            } else {
                return new DataResponse(['status' => 'error', 'message' => 'Missing parameter: group_id'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $tags = $this->groupTagService->listGroupTags($groupId, $user->getUID());
            return new DataResponse([
                'status' => 'success',
                'group_id' => $groupId,
                'tags' => $tags,
                'total' => count($tags),
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error("GroupTagController::listTags error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a group-specific tag.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createTag(?string $group_id = null, ?string $tag_name = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonOrParams();
        $groupId = trim((string)($group_id ?? $body['group_id'] ?? ''));
        $tagName = trim((string)($tag_name ?? $body['tag_name'] ?? ''));

        if ($groupId === '' || $tagName === '') {
            return new DataResponse(['status' => 'error', 'message' => 'Missing required parameters: group_id, tag_name'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $tag = $this->groupTagService->createGroupTag($user->getUID(), $groupId, $tagName);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Group tag created successfully',
                'tag' => $tag,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error("GroupTagController::createTag error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a group-specific tag atomically.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteTag(?string $group_id = null, $tag_id = null, $force = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonOrParams();
        $groupId = trim((string)($group_id ?? $body['group_id'] ?? ''));
        $tagIdRaw = $tag_id ?? $body['tag_id'] ?? null;
        $tagId = is_numeric($tagIdRaw) ? (int)$tagIdRaw : 0;
        $forceVal = $force ?? $body['force'] ?? false;
        $forceFlag = filter_var($forceVal, FILTER_VALIDATE_BOOLEAN);

        if ($groupId === '' || $tagId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Missing required parameters: group_id, tag_id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->deleteGroupTag($user->getUID(), $groupId, $tagId, $forceFlag);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Group tag deleted successfully',
                'tag_id' => $tagId,
                'data' => $res,
            ]);
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
        } catch (SecurityPermissionException $e) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => $e->getMessage(),
            ], Http::STATUS_FORBIDDEN);
        } catch (TagDeletionException $e) {
            $this->logger->error("GroupTagController::deleteTag error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'code' => 'DELETION_FAILED',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error("GroupTagController::deleteTag error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'code' => 'INTERNAL_ERROR',
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reconcile and self-heal group tags.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function reconcileTags(?string $group_id = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonOrParams();
        $groupId = trim((string)($group_id ?? $body['group_id'] ?? ''));

        if ($groupId === '') {
            $adminGroups = $this->groupTagService->getUserAdminGroups($user->getUID());
            if (!empty($adminGroups)) {
                $groupId = $adminGroups[0];
            } else {
                return new DataResponse(['status' => 'error', 'message' => 'Missing parameter: group_id'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $report = $this->groupTagService->reconcileGroupTags($user->getUID(), $groupId);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Group tags reconciled successfully',
                'report' => $report,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error("GroupTagController::reconcileTags error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Assign a group tag to a target file/folder in the group scope.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function assignTag(?string $group_id = null, $tag_id = null, $file_id = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonOrParams();
        $groupId = trim((string)($group_id ?? $body['group_id'] ?? ''));
        $tagId = (int)($tag_id ?? $body['tag_id'] ?? 0);
        $fileId = (int)($file_id ?? $body['file_id'] ?? 0);

        if ($groupId === '' || $tagId <= 0 || $fileId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Missing required parameters: group_id, tag_id, file_id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->assignTagToTarget($user->getUID(), $groupId, $tagId, $fileId);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tag assigned successfully',
                'data' => $res,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error("GroupTagController::assignTag error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove a group tag from a target file/folder.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function removeTag(?string $group_id = null, $tag_id = null, $file_id = null): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->getJsonOrParams();
        $groupId = trim((string)($group_id ?? $body['group_id'] ?? ''));
        $tagId = (int)($tag_id ?? $body['tag_id'] ?? 0);
        $fileId = (int)($file_id ?? $body['file_id'] ?? 0);

        if ($groupId === '' || $tagId <= 0 || $fileId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Missing required parameters: group_id, tag_id, file_id'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $res = $this->groupTagService->removeTagFromTarget($user->getUID(), $groupId, $tagId, $fileId);
            return new DataResponse([
                'status' => 'success',
                'message' => 'Tag removed successfully',
                'data' => $res,
            ]);
        } catch (SecurityPermissionException $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error("GroupTagController::removeTag error: " . $e->getMessage());
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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
