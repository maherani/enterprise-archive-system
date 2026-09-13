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
    public function __construct(
        private AutoTagService $autoTagService,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeRenamedEvent) {
            return;
        }

        $source = $event->getSource();
        $target = $event->getTarget();

        $sourcePath = $source->getPath();
        $targetPath = $target->getPath();

        $sourceInTrash = str_contains($sourcePath, 'files_trashbin');
        $targetInTrash = str_contains($targetPath, 'files_trashbin');

        // Handle moving to trashbin or restoring from trashbin
        if ($targetInTrash || $sourceInTrash) {
            if (!$targetInTrash && $target instanceof Folder) {
                // Restored from trashbin: re-tag hierarchy
                $this->autoTagService->retagAllRecursive($target);
            }
            $this->autoTagService->reconcileAllTags();
            return;
        }

        if ($target instanceof Folder) {
            $oldName = $source->getName();
            $newName = $target->getName();
            $this->autoTagService->handleFolderRenamed($target, $oldName, $newName);
        } elseif ($target instanceof File) {
            // File was moved or renamed: re-tag with its new hierarchy
            $this->autoTagService->tagNodeHierarchy($target);
            $this->autoTagService->reconcileAllTags();
        }
    }
}
