<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\ForbiddenException;

class NodeWrittenListener implements IEventListener {
    private AutoTagService $autoTagService;
    private UploadLimitService $uploadLimitService;

    public function __construct(
        AutoTagService $autoTagService,
        UploadLimitService $uploadLimitService
    ) {
        $this->autoTagService = $autoTagService;
        $this->uploadLimitService = $uploadLimitService;
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeWrittenEvent) {
            return;
        }

        $node = $event->getNode();

        // Enforce upload size limit on the written file
        try {
            $this->uploadLimitService->enforceLimit($node);
        } catch (ForbiddenException $e) {
            // Delete the oversized file from storage
            try {
                $node->delete();
            } catch (\Throwable $t) {
                // Ignore deletion error
            }
            throw $e;
        }

        $this->autoTagService->tagNodeHierarchy($node);
    }
}
