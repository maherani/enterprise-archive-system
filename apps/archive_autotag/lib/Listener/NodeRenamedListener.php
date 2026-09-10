<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\File;

class NodeRenamedListener implements IEventListener {
    private AutoTagService $autoTagService;

    public function __construct(AutoTagService $autoTagService) {
        $this->autoTagService = $autoTagService;
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeRenamedEvent) {
            return;
        }

        $source = $event->getSource();
        $target = $event->getTarget();

        if ($target instanceof Folder) {
            $oldName = $source->getName();
            $newName = $target->getName();
            $this->autoTagService->handleFolderRenamed($target, $oldName, $newName);
        } elseif ($target instanceof File) {
            // File was moved or renamed: re-tag with its new hierarchy
            $this->autoTagService->tagNodeHierarchy($target);
        }
    }
}
