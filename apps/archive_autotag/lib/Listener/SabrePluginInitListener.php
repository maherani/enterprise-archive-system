<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCA\ArchiveAutoTag\Service\FolderPolicyService;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;

class SabrePluginInitListener implements IEventListener {
    private UploadLimitService $uploadLimitService;
    private FolderPolicyService $folderPolicyService;
    private IUserSession $userSession;

    public function __construct(
        UploadLimitService $uploadLimitService,
        FolderPolicyService $folderPolicyService,
        IUserSession $userSession
    ) {
        $this->uploadLimitService = $uploadLimitService;
        $this->folderPolicyService = $folderPolicyService;
        $this->userSession = $userSession;
    }

    public function handle(Event $event): void {
        if (!$event instanceof SabrePluginAuthInitEvent) {
            return;
        }

        $server = $event->getServer();

        // Check folder creation restriction (MKCOL)
        $checkFolderCreation = function () {
            if (!$this->folderPolicyService->canCreateFolder()) {
                throw new Forbidden(
                    'Creating new folders is restricted to administrators only. You may only upload documents into existing folders.'
                );
            }
        };

        $server->on('beforeMethod:MKCOL', $checkFolderCreation);
        $server->on('beforeCreateDirectory', $checkFolderCreation);

        // Check upload file size limit
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
    }
}