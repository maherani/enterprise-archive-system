<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\AppInfo;

use OCA\ArchiveAutoTag\Listener\BeforeNodeCreatedListener;
use OCA\ArchiveAutoTag\Listener\BeforeNodeWrittenListener;
use OCA\ArchiveAutoTag\Listener\BeforeUserDeletedListener;
use OCA\ArchiveAutoTag\Listener\LoadAdditionalScriptsListener;
use OCA\ArchiveAutoTag\Listener\NodeCreatedListener;
use OCA\ArchiveAutoTag\Listener\NodeDeletedListener;
use OCA\ArchiveAutoTag\Listener\NodeRenamedListener;
use OCA\ArchiveAutoTag\Listener\NodeWrittenListener;
use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCA\ArchiveAutoTag\Service\FolderPolicyService;
use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCA\ArchiveAutoTag\Storage\ArchiveFileIsolationWrapper;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OC\Files\Filesystem;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeCreatedEvent;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\ForbiddenException;
use OCP\Files\Storage\IStorage;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserDeletedEvent;
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

        // Hierarchical dynamic tagging, ownership registration, and post-write size check
        $context->registerEventListener(NodeCreatedEvent::class, NodeCreatedListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, NodeDeletedListener::class);

        // Enforce admin-only user deletion (prevent group admins from deleting accounts)
        $context->registerEventListener(BeforeUserDeletedEvent::class, BeforeUserDeletedListener::class);

        // Native Multi-Tag Intersection Filter in Nextcloud Files Web UI
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);
    }

    public function boot(IBootContext $context): void {
        // Global URL Masking script: keep browser address bar fixed at origin root across all pages
        Util::addScript(self::APP_ID, 'url_mask');

        // Global App Launcher & Navigation restriction: for non-admin groups, only show "??????? ?????"
        Util::addScript(self::APP_ID, 'app_menu_filter');
        Util::addStyle(self::APP_ID, 'app_menu_filter');

        // Restrict navigation and app launcher state: for non-admin users, only display "??????? ?????"
        try {
            /** @var \OCP\IInitialStateService $initialStateService */
            $initialStateService = \OC::$server->get(\OCP\IInitialStateService::class);
            $initialStateService->provideLazyInitialState('core', 'apps', static function () {
                $container = \OC::$server;
                $userSession = $container->get(IUserSession::class);
                $user = $userSession->getUser();
                $navigationManager = $container->get(\OCP\INavigationManager::class);
                $allApps = array_values($navigationManager->getAll());
                if ($user !== null) {
                    $groupManager = $container->get(IGroupManager::class);
                    if (!$groupManager->isAdmin($user->getUID())) {
                        return array_values(array_filter($allApps, static function ($app) {
                            return ($app['id'] ?? '') === self::APP_ID;
                        }));
                    }
                }
                return $allApps;
            });

            // Also filter settingsNavEntries to remove core_apps (App store) for non-admin users
            $initialStateService->provideLazyInitialState('core', 'settingsNavEntries', static function () {
                $container = \OC::$server;
                $userSession = $container->get(IUserSession::class);
                $user = $userSession->getUser();
                $navigationManager = $container->get(\OCP\INavigationManager::class);
                $settingsNav = $navigationManager->getAll('settings');
                if ($user !== null) {
                    $groupManager = $container->get(IGroupManager::class);
                    if (!$groupManager->isAdmin($user->getUID())) {
                        unset($settingsNav['core_apps'], $settingsNav['appstore']);
                    }
                }
                return $settingsNav;
            });

            // Also provide is_admin state for frontend scripts
            $initialStateService->provideLazyInitialState(self::APP_ID, 'is_admin', static function () {
                $container = \OC::$server;
                $userSession = $container->get(IUserSession::class);
                $user = $userSession->getUser();
                if ($user !== null) {
                    $groupManager = $container->get(IGroupManager::class);
                    return $groupManager->isAdmin($user->getUID());
                }
                return false;
            });
        } catch (\Throwable $t) {
        }

        // Connect legacy filesystem hooks for WebDAV early pre-upload and pre-mkdir interception
        Util::connectHook('OC_Filesystem', 'write', self::class, 'preWriteHook');
        Util::connectHook('OC_Filesystem', 'create', self::class, 'preWriteHook');
        Util::connectHook('OC_Filesystem', 'mkdir', self::class, 'preMkdirHook');
        Util::connectHook('OC_Filesystem', 'delete', self::class, 'postDeleteHook');

        // Register Archive File Isolation Storage Wrapper
        Filesystem::addStorageWrapper(
            'archive_file_isolation',
            function (string $mountPoint, IStorage $storage) {
                $container = \OC::$server;
                $fileOwnershipService = $container->get(FileOwnershipService::class);
                $userSession = $container->get(IUserSession::class);
                $groupManager = $container->get(IGroupManager::class);
                return new ArchiveFileIsolationWrapper(
                    ['storage' => $storage, 'mountPoint' => $mountPoint],
                    $fileOwnershipService,
                    $userSession,
                    $groupManager,
                );
            },
            10
        );
    }

    /**
     * Intercept filesystem delete to trigger tag reconciliation upon deletion.
     */
    public static function postDeleteHook(array &$params): void {
        try {
            $container = \OC::$server;
            /** @var AutoTagService $autoTagService */
            $autoTagService = $container->get(AutoTagService::class);
            $autoTagService->reconcileAllTags();
        } catch (\Throwable $t) {
        }
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
