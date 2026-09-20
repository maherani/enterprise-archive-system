<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Security\Permission;

class PermissionOperation {
    public const NONE           = 0;
    public const READ           = 1;   // 000001: Read file content, download, stream
    public const WRITE          = 2;   // 000010: Update, overwrite, write stream
    public const CREATE         = 4;   // 000100: Create subfolder or upload new file
    public const DELETE         = 8;   // 001000: Delete file or directory
    public const SHARE          = 16;  // 010000: Share with other users/groups
    public const MANAGE         = 32;  // 100000: Admin/Subadmin management (grants, tags)
    public const READ_METADATA  = 64;  // 1000000: Inspect existence, size, tags, path
    public const TAG_ASSIGN     = 128; // 10000000: Assign or remove group tags

    public const ALL = self::READ | self::WRITE | self::CREATE | self::DELETE | self::SHARE | self::MANAGE | self::READ_METADATA | self::TAG_ASSIGN;

    public static function toString(int $operation): string {
        $ops = [];
        if ($operation & self::READ) $ops[] = 'READ';
        if ($operation & self::WRITE) $ops[] = 'WRITE';
        if ($operation & self::CREATE) $ops[] = 'CREATE';
        if ($operation & self::DELETE) $ops[] = 'DELETE';
        if ($operation & self::SHARE) $ops[] = 'SHARE';
        if ($operation & self::MANAGE) $ops[] = 'MANAGE';
        if ($operation & self::READ_METADATA) $ops[] = 'READ_METADATA';
        if ($operation & self::TAG_ASSIGN) $ops[] = 'TAG_ASSIGN';
        return empty($ops) ? 'NONE' : implode('|', $ops);
    }

    public static function fromString(string $name): int {
        return match (strtoupper(trim($name))) {
            'READ' => self::READ,
            'WRITE', 'UPDATE' => self::WRITE,
            'CREATE', 'UPLOAD' => self::CREATE,
            'DELETE' => self::DELETE,
            'SHARE' => self::SHARE,
            'MANAGE' => self::MANAGE,
            'READ_METADATA', 'BROWSE', 'LIST' => self::READ_METADATA,
            'TAG_ASSIGN' => self::TAG_ASSIGN,
            'ALL' => self::ALL,
            default => self::READ,
        };
    }
}
