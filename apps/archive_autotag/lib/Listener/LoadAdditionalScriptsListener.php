<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\AppInfo\Application;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadAdditionalScriptsListener implements IEventListener {
    public function __construct(
        private IUserSession $userSession,
        private IGroupManager $groupManager
    ) {}

    #[\Override]
    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        // Back-Office Guard: /apps/files is strictly reserved for Admin group users
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $uid = $user->getUID();
            $isAdmin = $this->groupManager->isAdmin($uid);
            if (!$isAdmin) {
                // Non-admin user attempted to access Admin Back-Office; redirect to Archive Portal
                header('Location: /index.php/apps/archive_autotag/');
                exit();
            }
        }

        Util::addStyle(Application::APP_ID, 'multi_tag_filter');
        Util::addScript(Application::APP_ID, 'multi_tag_filter');
        Util::addScript(Application::APP_ID, 'url_mask');
    }
}
