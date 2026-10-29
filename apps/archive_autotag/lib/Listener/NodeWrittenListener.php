<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Listener;

use OCA\ArchiveAutoTag\Service\AiOutboxService;
use OCA\ArchiveAutoTag\Service\AutoTagService;
use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OCA\ArchiveAutoTag\Service\UploadLimitService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\ForbiddenException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class NodeWrittenListener implements IEventListener {
    public function __construct(
        private AutoTagService $autoTagService,
        private UploadLimitService $uploadLimitService,
        private FileOwnershipService $fileOwnershipService,
        private IUserSession $userSession,
        private AiOutboxService $aiOutboxService,
        private LoggerInterface $logger,
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

            $this->logger->info("archive_autotag: NodeWrittenListener handling file ID {$node->getId()} ({$node->getName()})");

            // Fail-Closed: If Outbox registration fails on a qualified text file,
            // do NOT swallow the error. Propagate exception to halt the request
            // so SabreDAV returns HTTP 500 and does NOT send false HTTP 201/204 success to user.
            try {
                $this->aiOutboxService->recordFileEvent($node, $ownerUid);
            } catch (\Throwable $t) {
                $this->logger->error("archive_autotag: CRITICAL: Outbox recording failed for file ID {$node->getId()}: " . $t->getMessage());
                throw new \RuntimeException("Upload halted: AI Outbox registration failed: " . $t->getMessage(), 0, $t);
            }
        }

        $this->autoTagService->tagNodeHierarchy($node);
    }
}
