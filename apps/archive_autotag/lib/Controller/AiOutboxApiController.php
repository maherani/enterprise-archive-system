<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Service\AiFileService;
use OCA\ArchiveAutoTag\Service\AiOutboxService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class AiOutboxApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private AiFileService $aiFileService,
        private AiOutboxService $aiOutboxService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Authenticate caller via AiFileService (supports Bearer Service Token or User session)
     */
    private function checkAuth(): ?JSONResponse {
        $auth = $this->aiFileService->authenticateRequest($this->request);
        if (!$auth['authenticated']) {
            $statusCode = (int)($auth['status_code'] ?? Http::STATUS_UNAUTHORIZED);
            return new JSONResponse([
                'status' => 'error',
                'message' => $auth['error'] ?? 'Unauthorized: valid AI Service Token required.',
                'code' => $statusCode,
            ], $statusCode, [
                'WWW-Authenticate' => 'Bearer realm="Enterprise Archive AI Outbox API"',
            ]);
        }
        return null;
    }

    /**
     * Dynamic Folder & Workspace ACL Endpoint.
     * Evaluates in real-time which workspaces (folders + personal) the calling user has access to.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getWorkspaceAcl(): JSONResponse {
        $user = $this->userSession->getUser();
        $userId = $user !== null ? $user->getUID() : null;

        if ($userId === null || $userId === '') {
            $auth = $this->aiFileService->authenticateRequest($this->request);
            if ($auth['authenticated']) {
                $requestedUid = trim((string)$this->request->getParam('user_id', ''));
                if ($requestedUid !== '') {
                    $userId = $requestedUid;
                }
            }
        }

        if ($userId === null || $userId === '') {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Unauthorized: valid user session or AI Service Token required.',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $isAiChatAllowed = $this->groupManager->isAdmin($userId) || $this->groupManager->isInGroup($userId, 'ai_chat_access');
        if (!$isAiChatAllowed) {
            return new JSONResponse([
                'status' => 'success',
                'user_id' => $userId,
                'workspaces' => [],
            ]);
        }

        $isAdmin = ($userId === 'admin' || $this->groupManager->isAdmin($userId));
        $rawWorkspaces = $this->aiOutboxService->getUserAccessibleWorkspaces($userId);

        $workspaces = [];
        foreach ($rawWorkspaces as $ws) {
            $name = (string)($ws['name'] ?? '');
            $slug = (string)($ws['slug'] ?? '');
            $isEa = (
                strcasecmp($name, 'Enterprise_Archive') === 0 ||
                strcasecmp($slug, 'Enterprise_Archive') === 0 ||
                str_ends_with($slug, '-346')
            );
            if ($isEa && !$isAdmin) {
                continue;
            }
            $workspaces[] = $ws;
        }

        return new JSONResponse([
            'status' => 'success',
            'user_id' => $userId,
            'workspaces' => $workspaces,
        ]);
    }

    /**
     * Retrieve a batch of pending/failed Outbox events for publication.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function getPendingEvents(int $limit = 50): JSONResponse {
        $authError = $this->checkAuth();
        if ($authError !== null) {
            return $authError;
        }

        $events = $this->aiOutboxService->getPendingEvents($limit);

        return new JSONResponse([
            'status' => 'success',
            'count' => count($events),
            'events' => $events,
        ]);
    }

    /**
     * Acknowledge successful message publication and routing in RabbitMQ.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function ackEvent(): JSONResponse {
        $authError = $this->checkAuth();
        if ($authError !== null) {
            return $authError;
        }

        $body = $this->getJsonBody();
        $eventId = (string)($body['event_id'] ?? $this->request->getParam('event_id', ''));

        if ($eventId === '') {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Missing required parameter: event_id',
            ], Http::STATUS_BAD_REQUEST);
        }

        $success = $this->aiOutboxService->markPublished($eventId);
        if (!$success) {
            return new JSONResponse([
                'status' => 'error',
                'message' => "Event {$eventId} not found or already published.",
            ], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse([
            'status' => 'success',
            'event_id' => $eventId,
            'state' => 'PUBLISHED',
        ]);
    }

    /**
     * Record publication failure and schedule retry with incremented retry count.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function failEvent(): JSONResponse {
        $authError = $this->checkAuth();
        if ($authError !== null) {
            return $authError;
        }

        $body = $this->getJsonBody();
        $eventId = (string)($body['event_id'] ?? $this->request->getParam('event_id', ''));
        $error = (string)($body['error'] ?? $this->request->getParam('error', 'Unknown publishing error'));

        if ($eventId === '') {
            return new JSONResponse([
                'status' => 'error',
                'message' => 'Missing required parameter: event_id',
            ], Http::STATUS_BAD_REQUEST);
        }

        $success = $this->aiOutboxService->markFailed($eventId, $error);
        if (!$success) {
            return new JSONResponse([
                'status' => 'error',
                'message' => "Event {$eventId} not found.",
            ], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse([
            'status' => 'success',
            'event_id' => $eventId,
            'state' => 'FAILED',
        ]);
    }

    /**
     * Safely parse JSON request body
     */
    private function getJsonBody(): array {
        $content = file_get_contents('php://input');
        if ($content !== false && $content !== '') {
            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }
}
