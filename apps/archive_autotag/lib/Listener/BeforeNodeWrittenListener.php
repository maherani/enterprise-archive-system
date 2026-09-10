<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeWrittenEvent;

class BeforeNodeWrittenListener implements IEventListener {
    private UploadLimitService $uploadLimitService;

    public function __construct(UploadLimitService $uploadLimitService) {
        $this->uploadLimitService = $uploadLimitService;
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeNodeWrittenEvent) {
            return;
        }

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])
            ? (int)$_SERVER['CONTENT_LENGTH']
            : null;

        $this->uploadLimitService->enforceLimit($event->getNode(), $contentLength);
    }
}
