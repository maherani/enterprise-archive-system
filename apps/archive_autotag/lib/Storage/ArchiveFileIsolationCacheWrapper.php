<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Storage;

use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OC\Files\Cache\CacheDependencies;
use OC\Files\Cache\Wrapper\CacheWrapper;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\IUserSession;

class ArchiveFileIsolationCacheWrapper extends CacheWrapper {
    public function __construct(
        ?ICache $cache,
        ?CacheDependencies $dependencies,
        private FileOwnershipService $fileOwnershipService,
        private IUserSession $userSession,
    ) {
        parent::__construct($cache, $dependencies);
    }

    #[\Override]
    protected function formatCacheEntry($entry) {
        if (!$entry instanceof ICacheEntry) {
            return false;
        }

        // Folders are always accessible to maintain archive navigation
        if ($entry->getMimetype() === 'httpd/unix-directory') {
            return $entry;
        }

        $user = $this->userSession->getUser();
        $userId = $user !== null ? $user->getUID() : null;

        $fileId = (int)$entry->getId();
        if ($this->fileOwnershipService->canUserAccessFile($fileId, $userId)) {
            return $entry;
        }

        return false;
    }
}