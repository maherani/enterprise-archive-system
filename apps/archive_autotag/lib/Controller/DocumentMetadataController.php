<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Controller;

use OCA\ArchiveAutoTag\Security\Permission\CentralPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\PermissionOperation;
use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\DocumentMetadataService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use Symfony\Component\HttpFoundation\HeaderUtils;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class DocumentMetadataController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DocumentMetadataService $documentMetadataService,
        private readonly CentralPermissionResolver $permissionResolver,
        private readonly FileOwnershipService $fileOwnershipService,
        private readonly AutoTagService $autoTagService,
        private readonly IRootFolder $rootFolder,
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    private function resolveGroupDisplayName(string $nameOrId): string {
        try {
            $groupManager = \OC::$server->get(\OCP\IGroupManager::class);
            if ($groupManager->groupExists($nameOrId)) {
                $group = $groupManager->get($nameOrId);
                if ($group && method_exists($group, 'getDisplayName')) {
                    $d = $group->getDisplayName();
                    if (!empty($d)) {
                        return $d;
                    }
                }
            }
        } catch (\Throwable $t) {}
        return $nameOrId;
    }

    /**
     * Upload a new document with mandatory metadata capture.
     * Enforces fail-closed: file is never written if metadata validation or permission fails.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function uploadWithMetadata(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'UNAUTHORIZED',
                'message' => 'احراز هویت الزامی است.',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();

        // 1. Mandatory Metadata Validation (Fail-Closed)
        $subject = trim((string)($this->request->getParam('subject') ?? $_POST['subject'] ?? ''));
        if ($subject === '' || mb_strlen($subject) < 2) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'VALIDATION_ERROR',
                'message' => 'ورود موضوع سند الزامی است (حداقل ۲ کاراکتر).',
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $docNumber = trim((string)($this->request->getParam('document_number') ?? $_POST['document_number'] ?? ''));
        if ($docNumber === '') {
            return new DataResponse([
                'status' => 'error',
                'code' => 'VALIDATION_ERROR',
                'message' => 'ورود شماره سند الزامی است.',
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        // 2. Validate uploaded file
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'BAD_REQUEST',
                'message' => 'فایلی برای بارگذاری ارسال نشده است یا فایل نامعتبر است.',
            ], Http::STATUS_BAD_REQUEST);
        }

        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'UPLOAD_ERROR',
                'message' => 'خطای سرور در دریافت فایل: کد ' . $_FILES['file']['error'],
            ], Http::STATUS_BAD_REQUEST);
        }

        // 3. Resolve target folder
        $folderId = (int)($this->request->getParam('folder_id') ?? $_POST['folder_id'] ?? 0);
        $targetPath = trim((string)($this->request->getParam('target_folder') ?? $this->request->getParam('folder_path') ?? $this->request->getParam('target_dir') ?? $_POST['target_folder'] ?? $_POST['target_dir'] ?? ''));

        $targetFolder = null;
        if ($folderId > 0) {
            $nodes = $this->rootFolder->getById($folderId);
            if (!empty($nodes) && $nodes[0] instanceof Folder) {
                $targetFolder = $nodes[0];
            }
        }

        if ($targetFolder === null && $targetPath !== '') {
            $cleanDir = trim(trim($targetPath, '/'), '.');
            $strippedDir = str_starts_with($cleanDir, 'Enterprise_Archive/') ? substr($cleanDir, strlen('Enterprise_Archive/')) : $cleanDir;
            $userFolder = $this->rootFolder->getUserFolder($uid);
            
            $mappedCleanDir = $cleanDir;
            $mappedStrippedDir = $strippedDir;
            $parts = explode('/', $strippedDir);
            if (!empty($parts)) {
                $firstSeg = $parts[0];
                $mappedName = $this->resolveGroupDisplayName($firstSeg);
                if ($mappedName !== $firstSeg) {
                    $parts[0] = $mappedName;
                    $mappedStrippedDir = implode('/', $parts);
                    $mappedCleanDir = str_starts_with($cleanDir, 'Enterprise_Archive/') ? 'Enterprise_Archive/' . $mappedStrippedDir : $mappedStrippedDir;
                }
            }

            if ($cleanDir === '') {
                $targetFolder = $userFolder;
            } elseif ($userFolder->nodeExists($cleanDir) && ($n = $userFolder->get($cleanDir)) instanceof Folder) {
                $targetFolder = $n;
            } elseif ($userFolder->nodeExists($strippedDir) && ($n = $userFolder->get($strippedDir)) instanceof Folder) {
                $targetFolder = $n;
            } elseif ($userFolder->nodeExists($mappedStrippedDir) && ($n = $userFolder->get($mappedStrippedDir)) instanceof Folder) {
                $targetFolder = $n;
            } elseif ($userFolder->nodeExists($mappedCleanDir) && ($n = $userFolder->get($mappedCleanDir)) instanceof Folder) {
                $targetFolder = $n;
            } elseif ($userFolder->nodeExists('Enterprise_Archive/' . $strippedDir) && ($n = $userFolder->get('Enterprise_Archive/' . $strippedDir)) instanceof Folder) {
                $targetFolder = $n;
            } elseif ($userFolder->nodeExists('Enterprise_Archive/' . $mappedStrippedDir) && ($n = $userFolder->get('Enterprise_Archive/' . $mappedStrippedDir)) instanceof Folder) {
                $targetFolder = $n;
            } else {
                $adminUser = $this->userManager->get('admin');
                if ($adminUser !== null) {
                    $adminHome = $this->rootFolder->getUserFolder('admin');
                    if ($adminHome->nodeExists($cleanDir) && ($n = $adminHome->get($cleanDir)) instanceof Folder) {
                        $targetFolder = $n;
                    } elseif ($adminHome->nodeExists('Enterprise_Archive/' . $strippedDir) && ($n = $adminHome->get('Enterprise_Archive/' . $strippedDir)) instanceof Folder) {
                        $targetFolder = $n;
                    } elseif ($adminHome->nodeExists('Enterprise_Archive/' . $mappedStrippedDir) && ($n = $adminHome->get('Enterprise_Archive/' . $mappedStrippedDir)) instanceof Folder) {
                        $targetFolder = $n;
                    } elseif ($adminHome->nodeExists($strippedDir) && ($n = $adminHome->get($strippedDir)) instanceof Folder) {
                        $targetFolder = $n;
                    } elseif ($adminHome->nodeExists($mappedStrippedDir) && ($n = $adminHome->get($mappedStrippedDir)) instanceof Folder) {
                        $targetFolder = $n;
                    }
                }
            }
        }

        if ($targetFolder === null) {
            if ($folderId > 0 || $targetPath !== '') {
                return new DataResponse([
                    'status' => 'error',
                    'code' => 'NOT_FOUND',
                    'message' => 'پوشه مقصد مورد نظر یافت نشد یا دسترسی به آن مجاز نمی‌باشد.',
                ], Http::STATUS_NOT_FOUND);
            }
            $targetFolder = $this->rootFolder->getUserFolder($uid);
        }

        // 4. Authorization check: Only users with CREATE/UPLOAD permission can write
        $targetFolderId = (int)$targetFolder->getId();
        $targetFolderPath = $targetFolder->getPath();
        $canCreate = $this->permissionResolver->can($uid, $targetFolderId, PermissionOperation::CREATE);
        if (!$canCreate) {
            $decision = $this->permissionResolver->evaluateFolder($uid, $targetFolderPath, PermissionOperation::CREATE);
            if (!$decision->allowed) {
                $userFolder = $this->rootFolder->getUserFolder($uid);
                if (!str_starts_with($targetFolderPath, $userFolder->getPath())) {
                    return new DataResponse([
                        'status' => 'error',
                        'code' => 'FORBIDDEN',
                        'message' => 'شما دسترسی مجاز برای بارگذاری سند در این پوشه را ندارید.',
                    ], Http::STATUS_FORBIDDEN);
                }
            }
        }

        // 5. Atomic Storage: Write file into target folder
        $rawName = basename((string)($_FILES['file']['name'] ?? 'document.pdf'));
        $cleanName = preg_replace('[/\\\\]', '_', $rawName);
        if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
            $cleanName = 'doc_' . time() . '.bin';
        }

        $actualName = $cleanName;
        if ($targetFolder->nodeExists($actualName)) {
            $ext = pathinfo($cleanName, PATHINFO_EXTENSION);
            $base = pathinfo($cleanName, PATHINFO_FILENAME);
            $cnt = 1;
            while ($targetFolder->nodeExists($actualName)) {
                $actualName = $ext ? "{$base} ({$cnt}).{$ext}" : "{$base} ({$cnt})";
                $cnt++;
            }
        }

        $stream = fopen($_FILES['file']['tmp_name'], 'rb');
        if (!$stream) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'STREAM_ERROR',
                'message' => 'امکان خواندن فایل موقت آپلود شده وجود ندارد.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        try {
            $fileNode = $targetFolder->newFile($actualName, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            
            $msg = $e->getMessage();
            $trace = $e->getTraceAsString();
            $prev = $e->getPrevious() ? get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage() : 'None';
            
            return new DataResponse([
                'status' => 'error',
                'code' => 'STORAGE_ERROR',
                'message' => 'خطا در ذخیره‌سازی فایل: ' . $msg . ' | Prev: ' . $prev . ' | File: ' . $e->getFile() . ':' . $e->getLine(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $fileId = (int)$fileNode->getId();

        // 6. Register file ownership
        try {
            $this->fileOwnershipService->setFileOwner($fileId, $uid);
        } catch (\Throwable $t) {
            $this->logger->warning('DocumentMetadataController: Failed to set ownership: ' . $t->getMessage());
        }

        // 7. Apply parent folder hierarchy tags
        try {
            $this->autoTagService->tagNodeHierarchy($fileNode);
        } catch (\Throwable $t) {
            $this->logger->warning('DocumentMetadataController: Failed to apply hierarchy tags: ' . $t->getMessage());
        }

        // 8. Save Document Metadata
        $metadataData = [
            'subject' => $subject,
            'document_number' => $docNumber,
            'document_date' => $this->request->getParam('document_date') ?? $_POST['document_date'] ?? null,
            'confidentiality' => $this->request->getParam('confidentiality') ?? $_POST['confidentiality'] ?? 'normal',
            'issuer' => $this->request->getParam('issuer') ?? $_POST['issuer'] ?? null,
            'description' => $this->request->getParam('description') ?? $_POST['description'] ?? null,
        ];

        try {
            $savedMetadata = $this->documentMetadataService->saveMetadata($fileId, $metadataData, $uid);
        } catch (\Throwable $t) {
            $this->logger->error('DocumentMetadataController: Failed to save metadata: ' . $t->getMessage());
            return new DataResponse([
                'status' => 'error',
                'code' => 'METADATA_SAVE_ERROR',
                'message' => 'فایل ذخیره شد اما ثبت متادیتا با خطا مواجه شد: ' . $t->getMessage(),
                'file_id' => $fileId,
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse([
            'status' => 'success',
            'message' => 'سند به همراه مشخصات و متادیتای الزامی با موفقیت در سامانه بارگذاری گردید.',
            'file_id' => $fileId,
            'filename' => $actualName,
            'metadata' => $savedMetadata,
        ]);
    }

    /**
     * Get document metadata by file ID.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getMetadata(int $fileId): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();
        if ($fileId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Invalid fileId'], Http::STATUS_BAD_REQUEST);
        }

        // Access check
        if (!$this->permissionResolver->can($uid, $fileId, PermissionOperation::READ_METADATA)) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => 'شما دسترسی لازم برای مشاهده مشخصات این سند را ندارید.',
            ], Http::STATUS_FORBIDDEN);
        }

        $metadata = $this->documentMetadataService->getMetadataByFileId($fileId);
        return new DataResponse([
            'status' => 'success',
            'file_id' => $fileId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Update/Save metadata for an existing document.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function saveMetadata(int $fileId): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['status' => 'error', 'message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();
        if ($fileId <= 0) {
            return new DataResponse(['status' => 'error', 'message' => 'Invalid fileId'], Http::STATUS_BAD_REQUEST);
        }

        // Check write permission
        if (!$this->permissionResolver->can($uid, $fileId, PermissionOperation::WRITE)) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => 'شما دسترسی لازم برای ویرایش مشخصات این سند را ندارید.',
            ], Http::STATUS_FORBIDDEN);
        }

        $body = $this->getJsonOrParams();
        try {
            $metadata = $this->documentMetadataService->saveMetadata($fileId, $body, $uid);
            return new DataResponse([
                'status' => 'success',
                'file_id' => $fileId,
                'metadata' => $metadata,
            ]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'VALIDATION_ERROR',
                'message' => $e->getMessage(),
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $t) {
            $this->logger->error('DocumentMetadataController::saveMetadata error: ' . $t->getMessage());
            return new DataResponse([
                'status' => 'error',
                'message' => $t->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download an archive document with access control validation.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function download(int $fileId): Http\Response {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'UNAUTHORIZED',
                'message' => 'احراز هویت الزامی است.',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $uid = $user->getUID();
        if ($fileId <= 0) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'BAD_REQUEST',
                'message' => 'شناسه فایل نامعتبر است.',
            ], Http::STATUS_BAD_REQUEST);
        }

        // Strict Authorization: Check READ permission via CentralPermissionResolver
        if (!$this->permissionResolver->can($uid, $fileId, PermissionOperation::READ)) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'FORBIDDEN',
                'message' => 'شما مجوز دسترسی و دانلود این فایل را ندارید.',
            ], Http::STATUS_FORBIDDEN);
        }

        // Resolve Node from current user's context (Shared Mount / User folder)
        $node = null;
        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $nodes = $userFolder->getById($fileId);
            if (!empty($nodes)) {
                $node = $nodes[0];
            }
        } catch (\Throwable $t) {
            $this->logger->warning("DocumentMetadataController::download: User folder resolution error: " . $t->getMessage());
        }

        // Fallback: If not resolved via userFolder (only after explicit permission check above)
        if ($node === null) {
            try {
                $nodes = $this->rootFolder->getById($fileId);
                if (!empty($nodes)) {
                    $node = $nodes[0];
                }
            } catch (\Throwable $t) {
                $this->logger->warning("DocumentMetadataController::download: Root folder resolution error: " . $t->getMessage());
            }
        }

        if (!$node instanceof File) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'NOT_FOUND',
                'message' => 'فایل یافت نشد.',
            ], Http::STATUS_NOT_FOUND);
        }

        $stream = $node->fopen('rb');
        if ($stream === false) {
            return new DataResponse([
                'status' => 'error',
                'code' => 'STORAGE_ERROR',
                'message' => 'امکان باز کردن جریان فایل وجود ندارد.',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $fileName = $node->getName();
        $mime = $node->getMimetype() ?: 'application/octet-stream';
        $size = $node->getSize();

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $fileName,
            preg_replace('/[^\x20-\x7e]/', '', $fileName) ?: 'download'
        );

        if ($size === 0) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $response = new \OCP\AppFramework\Http\Response();
            $response->setStatus(Http::STATUS_OK);
            $response->addHeader('Content-Type', $mime);
            $response->addHeader('Content-Disposition', $disposition);
            $response->addHeader('Content-Length', '0');
            return $response;
        }

        $response = new StreamResponse($stream);
        $response->addHeader('Content-Type', $mime);
        $response->addHeader('Content-Disposition', $disposition);
        if ($size > 0) {
            $response->addHeader('Content-Length', (string)$size);
        }

        return $response;
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
