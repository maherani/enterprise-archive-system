<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AiOutboxService;
use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class NodeDeletedListener implements IEventListener {
    public function __construct(
        private AutoTagService $autoTagService,
        private AiOutboxService $aiOutboxService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeDeletedEvent) {
            return;
        }

        $node = $event->getNode();
        if ($node instanceof Folder) {
            $this->autoTagService->handleFolderDeleted($node);
        } elseif ($node instanceof File) {
            $user = $this->userSession->getUser();
            $ownerUid = $user !== null ? $user->getUID() : 'admin';
            try {
                $this->aiOutboxService->recordFileDeleted($node, $ownerUid);
            } catch (\Throwable $t) {
                $this->logger->warning("archive_autotag: Failed to record file deletion in Outbox: " . $t->getMessage());
            }
            $this->autoTagService->reconcileAllTags();
        } else {
            $this->autoTagService->reconcileAllTags();
        }
    }
}
