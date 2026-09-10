<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\AppInfo;

use OCA\ArchiveAutoTag\Listener\BeforeNodeCreatedListener;
use OCA\ArchiveAutoTag\Listener\BeforeNodeWrittenListener;
use OCA\ArchiveAutoTag\Listener\NodeCreatedListener;
use OCA\ArchiveAutoTag\Listener\NodeRenamedListener;
use OCA\ArchiveAutoTag\Listener\NodeWrittenListener;
use OCA\ArchiveAutoTag\Service\FolderPolicyService;
use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\ForbiddenException;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'archive_autotag';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Enforce per-user upload size limits and folder creation restriction
        $context->registerEventListener(BeforeNodeCreatedEvent::class, BeforeNodeCreatedListener::class);
        $context->registerEventListener(BeforeNodeWrittenEvent::class, BeforeNodeWrittenListener::class);

        // SabreDAV 403 Forbidden enforcement (Upload limits and MKCOL folder restriction)
        $context->registerEventListener(
            \OCA\DAV\Events\SabrePluginAuthInitEvent::class,
            \OCA\ArchiveAutoTag\Listener\SabrePluginInitListener::class
        );

        // Hierarchical dynamic tagging and post-write size check
        $context->registerEventListener(NodeCreatedEvent::class, NodeCreatedListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);
    }

    public function boot(IBootContext $context): void {
        // Connect legacy filesystem hooks for WebDAV early pre-upload and pre-mkdir interception
        Util::connectHook('OC_Filesystem', 'write', self::class, 'preWriteHook');
        Util::connectHook('OC_Filesystem', 'create', self::class, 'preWriteHook');
        Util::connectHook('OC_Filesystem', 'mkdir', self::class, 'preMkdirHook');
    }

    /**
     * Intercept filesystem mkdir to enforce admin-only folder creation.
     */
    public static function preMkdirHook(array &$params): void {
        $container = \OC::$server;
        /** @var FolderPolicyService $folderPolicyService */
        $folderPolicyService = $container->get(FolderPolicyService::class);
        if (!$folderPolicyService->canCreateFolder()) {
            $params['run'] = false;
            $msg = 'Creating new folders is restricted to administrators only. You may only upload documents into existing folders.';
            if (class_exists(\Sabre\DAV\Exception\Forbidden::class)) {
                throw new \Sabre\DAV\Exception\Forbidden($msg);
            }
            throw new ForbiddenException($msg, false);
        }
    }

    /**
     * Intercept filesystem pre-write to enforce per-user upload size limits.
     */
    public static function preWriteHook(array &$params): void {
        $container = \OC::$server;
        /** @var IUserSession $userSession */
        $userSession = $container->get(IUserSession::class);
        $user = $userSession->getUser();
        if ($user === null) {
            return;
        }

        $userId = $user->getUID();
        /** @var UploadLimitService $uploadLimitService */
        $uploadLimitService = $container->get(UploadLimitService::class);
        $limitBytes = $uploadLimitService->getUserLimit($userId);
        if ($limitBytes <= 0) {
            return;
        }

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
            ? (int)$_SERVER['CONTENT_LENGTH']
            : 0;

        if ($contentLength > $limitBytes) {
            $params['run'] = false;
            $limitFmt = UploadLimitService::formatSize($limitBytes);
            $sizeFmt = UploadLimitService::formatSize($contentLength);
            $msg = "Upload rejected: File size ({$sizeFmt}) exceeds the maximum allowed limit of {$limitFmt} for user '{$userId}'.";
            if (class_exists(\Sabre\DAV\Exception\Forbidden::class)) {
                throw new \Sabre\DAV\Exception\Forbidden($msg);
            }
            throw new ForbiddenException($msg, false);
        }
    }
}