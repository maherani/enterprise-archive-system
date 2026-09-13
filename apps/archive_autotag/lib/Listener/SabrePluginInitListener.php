<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCA\ArchiveAutoTag\Service\FolderPolicyService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;

class SabrePluginInitListener implements IEventListener {
    private UploadLimitService $uploadLimitService;
    private FolderPolicyService $folderPolicyService;
    private FileOwnershipService $fileOwnershipService;
    private IUserSession $userSession;

    public function __construct(
        UploadLimitService $uploadLimitService,
        FolderPolicyService $folderPolicyService,
        FileOwnershipService $fileOwnershipService,
        IUserSession $userSession
    ) {
        $this->uploadLimitService = $uploadLimitService;
        $this->folderPolicyService = $folderPolicyService;
        $this->fileOwnershipService = $fileOwnershipService;
        $this->userSession = $userSession;
    }

    public function handle(Event $event): void {
        if (!$event instanceof SabrePluginAuthInitEvent) {
            return;
        }

        $server = $event->getServer();

        // 1. Check folder creation restriction (MKCOL)
        $checkFolderCreation = function () {
            if (!$this->folderPolicyService->canCreateFolder()) {
                throw new Forbidden(
                    'Creating new folders is restricted to administrators only. You may only upload documents into existing folders.'
                );
            }
        };

        $server->on('beforeMethod:MKCOL', $checkFolderCreation);
        $server->on('beforeCreateDirectory', $checkFolderCreation);

        // 2. Check upload file size limit
        $checkLimit = function ($uri, $data = null, $parent = null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return;
            }

            $userId = $user->getUID();
            $limitBytes = $this->uploadLimitService->getUserLimit($userId);
            if ($limitBytes <= 0) {
                return;
            }

            $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
                ? (int)$_SERVER['CONTENT_LENGTH']
                : 0;

            if ($contentLength > $limitBytes) {
                $limitFmt = UploadLimitService::formatSize($limitBytes);
                $sizeFmt = UploadLimitService::formatSize($contentLength);
                throw new Forbidden(
                    "Upload rejected: File size ({$sizeFmt}) exceeds the maximum allowed limit of {$limitFmt} for user '{$userId}'."
                );
            }
        };

        $server->on('beforeCreateFile', $checkLimit);
        $server->on('beforeWriteContent', $checkLimit);

        // 3. File ACL & User Isolation:
        // Exclude unauthorized files from PROPFIND folder listings
        $server->on('propFind', function (\Sabre\DAV\PropFind $propFind, \Sabre\DAV\INode $node) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return true;
            }
            $userId = $user->getUID();
            if ($userId === 'admin') {
                return true;
            }

            if ($node instanceof \OCA\DAV\Connector\Sabre\File) {
                $fileId = $node->getId();
                if ($fileId !== null && !$this->fileOwnershipService->canUserAccessFile($fileId, $userId)) {
                    return false;
                }
            }
            return true;
        }, 50);

        // 4. File ACL & User Isolation:
        // Intercept direct file operations (GET, HEAD, DELETE, PROPPATCH, COPY, MOVE, PROPFIND, and PUT to existing file)
        $checkFileAccess = function (\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) use ($server) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return;
            }
            $userId = $user->getUID();
            if ($userId === 'admin') {
                return;
            }

            $path = trim($request->getPath(), '/');
            if ($path === '') {
                return;
            }

            try {
                $node = $server->tree->getNodeForPath($path);
            } catch (\Throwable $e) {
                return;
            }

            if ($node instanceof \OCA\DAV\Connector\Sabre\File) {
                $fileId = $node->getId();
                if ($fileId !== null && !$this->fileOwnershipService->canUserAccessFile($fileId, $userId)) {
                    throw new NotFound('File not found');
                }
            }

            // Also check destination if present (e.g. MOVE/COPY overwrite target)
            $destHeader = $request->getHeader('Destination');
            if ($destHeader) {
                try {
                    $destPath = $server->calculateUri($destHeader);
                    $destNode = $server->tree->getNodeForPath($destPath);
                    if ($destNode instanceof \OCA\DAV\Connector\Sabre\File) {
                        $destFileId = $destNode->getId();
                        if ($destFileId !== null && !$this->fileOwnershipService->canUserAccessFile($destFileId, $userId)) {
                            throw new NotFound('File not found');
                        }
                    }
                } catch (\Throwable $e) {
                    if ($e instanceof NotFound) {
                        throw $e;
                    }
                }
            }
        };

        $server->on('beforeMethod:GET', $checkFileAccess, 150);
        $server->on('beforeMethod:HEAD', $checkFileAccess, 150);
        $server->on('beforeMethod:DELETE', $checkFileAccess, 150);
        $server->on('beforeMethod:PROPPATCH', $checkFileAccess, 150);
        $server->on('beforeMethod:COPY', $checkFileAccess, 150);
        $server->on('beforeMethod:MOVE', $checkFileAccess, 150);
        $server->on('beforeMethod:PROPFIND', $checkFileAccess, 150);
        $server->on('beforeMethod:PUT', $checkFileAccess, 150);
    }
}
