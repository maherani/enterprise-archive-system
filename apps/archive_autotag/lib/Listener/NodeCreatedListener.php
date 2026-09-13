<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;

class NodeCreatedListener implements IEventListener {
    public function __construct(
        private AutoTagService $autoTagService,
        private FileOwnershipService $fileOwnershipService,
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeCreatedEvent) {
            return;
        }

        $node = $event->getNode();

        if ($node instanceof File) {
            $user = $this->userSession->getUser();
            $ownerUid = $user !== null ? $user->getUID() : 'admin';
            $this->fileOwnershipService->setFileOwner((int)$node->getId(), $ownerUid);
            $this->autoTagService->tagNodeHierarchy($node);
        } elseif ($node instanceof Folder) {
            $this->autoTagService->handleFolderCreated($node);
        }
    }
}
