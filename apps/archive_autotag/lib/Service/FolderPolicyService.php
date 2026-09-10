<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IConfig;

class FolderPolicyService {
    public const APP_ID = 'archive_autotag';
    public const CONFIG_KEY_RESTRICT_FOLDER_CREATION = 'restrict_folder_creation';

    private IUserSession $userSession;
    private IGroupManager $groupManager;
    private IConfig $config;

    public function __construct(
        IUserSession $userSession,
        IGroupManager $groupManager,
        IConfig $config
    ) {
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->config = $config;
    }

    /**
     * Check if folder creation restriction policy is enabled.
     * Defaults to true ('yes').
     */
    public function isPolicyEnabled(): bool {
        return $this->config->getAppValue(self::APP_ID, self::CONFIG_KEY_RESTRICT_FOLDER_CREATION, 'yes') === 'yes';
    }

    /**
     * Enable or disable policy.
     */
    public function setPolicyEnabled(bool $enabled): void {
        $this->config->setAppValue(self::APP_ID, self::CONFIG_KEY_RESTRICT_FOLDER_CREATION, $enabled ? 'yes' : 'no');
    }

    /**
     * Check if user is allowed to create folders.
     */
    public function canCreateFolder(?string $userId = null): bool {
        if (!$this->isPolicyEnabled()) {
            return true;
        }

        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return true;
            }
            $userId = $user->getUID();
        }

        if ($userId === 'admin' || $this->groupManager->isAdmin($userId) || $this->groupManager->isInGroup($userId, 'admin')) {
            return true;
        }

        return false;
    }
}