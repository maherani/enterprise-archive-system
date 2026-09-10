<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;

class NodeCreatedListener implements IEventListener {
    private AutoTagService $autoTagService;

    public function __construct(AutoTagService $autoTagService) {
        $this->autoTagService = $autoTagService;
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeCreatedEvent) {
            return;
        }

        $node = $event->getNode();
        $this->autoTagService->tagNodeHierarchy($node);
    }
}
