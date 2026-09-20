<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Storage;

use OCA\ArchiveAutoTag\Security\Permission\CentralPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\IPermissionResolver;
use OCA\ArchiveAutoTag\Security\Permission\PermissionOperation;
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
    private ?IPermissionResolver $permissionResolver;

    public function __construct(
        array $parameters,
        FileOwnershipService $fileOwnershipService,
        IUserSession $userSession,
        IGroupManager $groupManager,
        ?IPermissionResolver $permissionResolver = null,
    ) {
        parent::__construct($parameters);
        $this->fileOwnershipService = $fileOwnershipService;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->permissionResolver = $permissionResolver;
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
        if (!$this->isPathPermitted($path, PermissionOperation::READ)) {
            return false;
        }
        return parent::isReadable($path);
    }

    #[\Override]
    public function isUpdatable(string $path): bool {
        if (!$this->isPathPermitted($path, PermissionOperation::WRITE)) {
            return false;
        }
        return parent::isUpdatable($path);
    }

    #[\Override]
    public function isCreatable(string $path): bool {
        if (!$this->isPathPermitted($path, PermissionOperation::CREATE)) {
            return false;
        }
        return parent::isCreatable($path);
    }

    #[\Override]
    public function isDeletable(string $path): bool {
        if (!$this->isPathPermitted($path, PermissionOperation::DELETE)) {
            return false;
        }
        return parent::isDeletable($path);
    }

    #[\Override]
    public function file_exists(string $path): bool {
        if (!$this->isPathPermitted($path, PermissionOperation::READ_METADATA)) {
            return false;
        }
        return parent::file_exists($path);
    }

    #[\Override]
    public function fopen(string $path, string $mode) {
        $op = (str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, '+'))
            ? PermissionOperation::WRITE
            : PermissionOperation::READ;

        if (!$this->isPathPermitted($path, $op)) {
            return false;
        }
        return parent::fopen($path, $mode);
    }

    /**
     * Centralized Fail-Closed Path Permission Evaluation
     */
    private function isPathPermitted(string $path, int $operation = PermissionOperation::READ): bool {
        $cleanPath = trim(trim($path, '/'), '.');
        if ($cleanPath === '') {
            return true;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            // Internal / CLI / Service context (e.g. AI API with Bearer token)
            // Authorization is explicitly enforced by CentralPermissionResolver in the service layer
            return true;
        }
        $userId = $user->getUID();

        // System admin has unrestricted superuser access across all files and folders
        if ($userId === 'admin' || $this->groupManager->isAdmin($userId)) {
            return true;
        }

        $resolver = $this->getResolver();

        // 1. Directory Check
        if ($this->is_dir($path)) {
            if ($resolver !== null) {
                return $resolver->evaluateFolder($userId, $cleanPath, $operation)->allowed;
            }
            return true;
        }

        // 2. File Check via Cache
        $cache = $this->getWrapperStorage()->getCache('');
        $entry = $cache->get($path);
        if (!$entry && $path !== $cleanPath) {
            $entry = $cache->get($cleanPath);
        }

        // 3. Handling Missing / Unindexed Cache Entries (Fail-Closed Architecture)
        if (!$entry) {
            // Scenario A: Write / Creation Operation (e.g. Uploading a brand new file via WebDAV PUT or fopen('w'))
            // The file does not exist in cache yet. Authorization is determined by whether the user
            // has CREATE permission in the parent directory.
            if (($operation & (PermissionOperation::WRITE | PermissionOperation::CREATE)) !== 0) {
                $parentDir = dirname($cleanPath);
                if ($parentDir === '.' || $parentDir === '' || $parentDir === '/') {
                    // Regular users cannot write to archive root (Fail-Closed)
                    return false;
                }
                if ($resolver !== null) {
                    return $resolver->evaluateFolder($userId, $parentDir, PermissionOperation::CREATE)->allowed;
                }
                return false;
            }

            // Scenario B: Read / Metadata probe on a file that physically exists on underlying storage
            if ($this->getWrapperStorage()->file_exists($path)) {
                try {
                    // On-demand scanner reconciliation
                    $this->getWrapperStorage()->getScanner()->scanFile($path);
                    $entry = $cache->get($path);
                } catch (\Throwable $t) {}
            }

            // If still no entry in filecache (bogus path, unindexed probe, non-existent file), FAIL-CLOSED
            if (!$entry) {
                return false;
            }
        }

        if ($entry->getMimetype() === 'httpd/unix-directory') {
            if ($resolver !== null) {
                return $resolver->evaluateFolder($userId, $cleanPath, $operation)->allowed;
            }
            return true;
        }

        $fileId = (int)$entry->getId();
        if ($resolver !== null) {
            return $resolver->can($userId, $fileId, $operation);
        }

        return $this->fileOwnershipService->canUserAccessFile($fileId, $userId);
    }

    private function getResolver(): ?IPermissionResolver {
        if ($this->permissionResolver !== null) {
            return $this->permissionResolver;
        }
        try {
            $resolver = \OC::$server->get(CentralPermissionResolver::class);
            if ($resolver instanceof IPermissionResolver) {
                $this->permissionResolver = $resolver;
                return $resolver;
            }
        } catch (\Throwable $t) {}
        return null;
    }
}
