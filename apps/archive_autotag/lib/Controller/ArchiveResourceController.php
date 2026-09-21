<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Exception\SecurityPermissionException;
use OCA\ArchiveAutoTag\Service\ArchiveDeletionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ArchiveResourceController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ArchiveDeletionService $deletionService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Permanently and securely delete an archive file or folder (System Admin only).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'احراز هویت الزامی است.',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        $params = $this->request->getParams();
        $fileId = (int)($params['file_id'] ?? $params['fileId'] ?? 0);
        $folderPath = isset($params['folder_path']) ? (string)$params['folder_path'] : (isset($params['folderPath']) ? (string)$params['folderPath'] : null);
        $clientIp = (string)($this->request->getRemoteAddress() ?? '');

        if ($fileId <= 0 && empty($folderPath)) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'شناسه فایل یا مسیر پوشه جهت حذف الزامی است.',
            ], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->deletionService->deleteResource($fileId, $folderPath, $userId, $clientIp);
            return new DataResponse($result, Http::STATUS_OK);
        } catch (SecurityPermissionException $e) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => $e->getMessage(),
            ], Http::STATUS_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error("ArchiveResourceController::delete error: " . $e->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => 'خطای سیستمی در فرآیند حذف منبع: ' . $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
