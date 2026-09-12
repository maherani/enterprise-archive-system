<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserDeletedEvent;
use Psr\Log\LoggerInterface;

class BeforeUserDeletedListener implements IEventListener {
    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeUserDeletedEvent) {
            return;
        }

        $actor = $this->userSession->getUser();
        // If operation is performed via CLI / internal console (no active web user session), allow
        if ($actor === null) {
            return;
        }

        $actorUid = $actor->getUID();
        // Full system administrators are authorized to delete accounts
        if ($actorUid === 'admin' || $this->groupManager->isAdmin($actorUid)) {
            return;
        }

        $targetUser = $event->getUser();
        $targetUid = $targetUser->getUID();

        $this->logger->warning(
            "archive_autotag: User deletion blocked. Actor '{$actorUid}' attempted to delete user '{$targetUid}', but account deletion is restricted to system administrators only."
        );

        throw new OCSForbiddenException(
            'User deletion is strictly restricted to system administrators. Group administrators may only modify group members.'
        );
    }
}
