<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Central authority for identifying the one configured primary system administrator.
 *
 * The configured UID alone is not sufficient: that account must also belong to
 * Nextcloud's system "admin" group. Group-admin/subadmin membership is not enough.
 */
final class PrimarySystemAdminResolver {
    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    public function isPrimarySystemAdmin(?IUser $user): bool {
        if ($user === null) {
            return false;
        }

        try {
            $primaryUid = trim(
                (string)$this->config->getSystemValue('primary_system_admin_uid', '')
            );

            if ($primaryUid === '' || !hash_equals($primaryUid, $user->getUID())) {
                return false;
            }

            return $this->groupManager->isAdmin($user->getUID());
        } catch (\Throwable $exception) {
            $this->logger->error(
                'PrimarySystemAdminResolver: unable to verify primary system administrator.',
                ['exception' => $exception]
            );
            return false;
        }
    }
}
