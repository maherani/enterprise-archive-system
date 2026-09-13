<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Folder;

class NodeDeletedListener implements IEventListener {
    public function __construct(
        private AutoTagService $autoTagService,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeDeletedEvent) {
            return;
        }

        $node = $event->getNode();
        if ($node instanceof Folder) {
            $this->autoTagService->handleFolderDeleted($node);
        } else {
            // When a file is deleted, check and prune any now-orphaned tags
            $this->autoTagService->reconcileAllTags();
        }
    }
}
