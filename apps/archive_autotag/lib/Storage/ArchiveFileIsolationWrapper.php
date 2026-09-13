<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Storage;

use OCA\ArchiveAutoTag\Service\FileOwnershipService;
use OC\Files\Storage\Wrapper\Wrapper;
use OCP\Files\Cache\ICache;
use OCP\Files\Storage\IStorage;
use OCP\IGroupManager;
use OCP\IUserSession;

class ArchiveFileIsolationWrapper extends Wrapper {
    private FileOwnershipService $fileOwnershipService;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    public function __construct(
        array $parameters,
        FileOwnershipService $fileOwnershipService,
        IUserSession $userSession,
        IGroupManager $groupManager,
    ) {
        parent::__construct($parameters);
        $this->fileOwnershipService = $fileOwnershipService;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
    }

    #[\Override]
    public function getCache(string $path = '', ?IStorage $storage = null): ICache {
        if (!$storage instanceof IStorage) {
            $storage = $this;
        }
        $cache = $this->getWrapperStorage()->getCache($path, $storage);
        return new ArchiveFileIsolationCacheWrapper(
            $cache,
            null,
            $this->fileOwnershipService,
            $this->userSession
        );
    }

    #[\Override]
    public function isReadable(string $path): bool {
        if (!$this->isPathPermitted($path)) {
            return false;
        }
        return parent::isReadable($path);
    }

    #[\Override]
    public function isUpdatable(string $path): bool {
        if (!$this->isPathPermitted($path)) {
            return false;
        }
        return parent::isUpdatable($path);
    }

    #[\Override]
    public function isDeletable(string $path): bool {
        if (!$this->isPathPermitted($path)) {
            return false;
        }
        return parent::isDeletable($path);
    }

    #[\Override]
    public function file_exists(string $path): bool {
        if (!$this->isPathPermitted($path)) {
            return false;
        }
        return parent::file_exists($path);
    }

    #[\Override]
    public function fopen(string $path, string $mode) {
        if (!$this->isPathPermitted($path)) {
            return false;
        }
        return parent::fopen($path, $mode);
    }

    private function isPathPermitted(string $path): bool {
        $path = trim($path, '/');
        if ($path === '' || $this->is_dir($path)) {
            return true;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return true;
        }
        $userId = $user->getUID();

        // System admin has unrestricted access to all files
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        $cache = $this->getWrapperStorage()->getCache('');
        $entry = $cache->get($path);
        if (!$entry) {
            return true;
        }

        if ($entry->getMimetype() === 'httpd/unix-directory') {
            return true;
        }

        return $this->fileOwnershipService->canUserAccessFile((int)$entry->getId(), $userId);
    }
}