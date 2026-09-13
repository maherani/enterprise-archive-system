<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\ForbiddenException;
use OCP\IUserSession;

class NodeWrittenListener implements IEventListener {
    public function __construct(
        private AutoTagService $autoTagService,
        private UploadLimitService $uploadLimitService,
        private FileOwnershipService $fileOwnershipService,
        private IUserSession $userSession,
    ) {
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

        if ($node instanceof File) {
            $user = $this->userSession->getUser();
            $ownerUid = $user !== null ? $user->getUID() : 'admin';
            $this->fileOwnershipService->setFileOwner((int)$node->getId(), $ownerUid);
        }

        $this->autoTagService->tagNodeHierarchy($node);
    }
}