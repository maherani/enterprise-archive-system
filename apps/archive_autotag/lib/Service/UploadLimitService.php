<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Service;

use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Files\ForbiddenException;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

class UploadLimitService {
    public const APP_ID = 'archive_autotag';
    public const CONFIG_KEY = 'max_upload_size_bytes';

    private IConfig $config;
    private IUserManager $userManager;
    private IUserSession $userSession;
    private LoggerInterface $logger;

    public function __construct(
        IConfig $config,
        IUserManager $userManager,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->logger = $logger;
    }

    /**
     * Set max upload size limit for a user in bytes.
     * Pass 0 to remove the limit (unlimited).
     */
    public function setUserLimit(string $userId, int $bytes): void {
        if ($bytes <= 0) {
            $this->config->deleteUserValue($userId, self::APP_ID, self::CONFIG_KEY);
            $this->logger->info("archive_autotag: Removed upload limit for user '{$userId}'");
        } else {
            $this->config->setUserValue($userId, self::APP_ID, self::CONFIG_KEY, (string)$bytes);
            $this->logger->info("archive_autotag: Set upload limit for user '{$userId}' to {$bytes} bytes");
        }
    }

    /**
     * Get user limit in bytes, or 0 if unlimited.
     */
    public function getUserLimit(string $userId): int {
        $val = $this->config->getUserValue($userId, self::APP_ID, self::CONFIG_KEY, '0');
        return (int)$val;
    }

    /**
     * Get all users with configured limits.
     *
     * @return array<string, int> [userId => limitBytes]
     */
    public function getAllConfiguredLimits(): array {
        $users = $this->userManager->search('');
        $limits = [];
        foreach ($users as $user) {
            $uid = $user->getUID();
            $limit = $this->getUserLimit($uid);
            if ($limit > 0) {
                $limits[$uid] = $limit;
            }
        }
        return $limits;
    }

    /**
     * Check if a file creation or write exceeds the user's configured limit.
     *
     * @throws ForbiddenException
     */
    public function enforceLimit(Node $node, ?int $reportedSize = null): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return; // No authenticated user (e.g. internal/CLI), skip
        }

        $userId = $user->getUID();
        $limitBytes = $this->getUserLimit($userId);
        if ($limitBytes <= 0) {
            return; // No limit configured for this user
        }

        // Determine size: either explicit reportedSize (Content-Length) or node size
        $size = $reportedSize;
        if ($size === null || $size <= 0) {
            try {
                $size = $node->getSize();
            } catch (\Throwable $e) {
                $size = 0;
            }
        }

        if ($size > $limitBytes) {
            $limitFormatted = self::formatSize($limitBytes);
            $sizeFormatted = self::formatSize($size);
            $msg = "Upload rejected: File size ({$sizeFormatted}) exceeds the maximum allowed limit of {$limitFormatted} for user '{$userId}'.";
            $this->logger->warning("archive_autotag: {$msg}");
            throw new ForbiddenException($msg, false);
        }
    }

    /**
     * Parse human-readable size string (e.g. '10M', '500K', '2G', '1048576') to bytes.
     */
    public static function parseSize(string $sizeStr): int {
        $sizeStr = trim($sizeStr);
        if ($sizeStr === '' || $sizeStr === '0' || strtolower($sizeStr) === 'unlimited') {
            return 0;
        }

        $lastChar = strtoupper(substr($sizeStr, -1));
        if (!is_numeric($lastChar)) {
            $number = (float)substr($sizeStr, 0, -1);
            switch ($lastChar) {
                case 'G':
                    return (int)($number * 1024 * 1024 * 1024);
                case 'M':
                    return (int)($number * 1024 * 1024);
                case 'K':
                    return (int)($number * 1024);
                default:
                    return (int)$number;
            }
        }

        return (int)$sizeStr;
    }

    /**
     * Format bytes into human-readable format.
     */
    public static function formatSize(int $bytes): string {
        if ($bytes <= 0) {
            return 'Unlimited';
        }
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
