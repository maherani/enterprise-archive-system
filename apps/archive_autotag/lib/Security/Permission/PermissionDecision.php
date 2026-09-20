<?php
declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Security\Permission;

class PermissionDecision {
    public function __construct(
        public readonly bool $allowed,
        public readonly string $matchedRule,
        public readonly string $reason,
        public readonly int $effectiveMask,
        public readonly array $auditContext = []
    ) {}

    public static function allow(string $matchedRule, string $reason, int $effectiveMask = PermissionOperation::ALL, array $context = []): self {
        return new self(true, $matchedRule, $reason, $effectiveMask, $context);
    }

    public static function deny(string $matchedRule, string $reason, int $effectiveMask = PermissionOperation::NONE, array $context = []): self {
        return new self(false, $matchedRule, $reason, $effectiveMask, $context);
    }

    public function isAllowed(): bool {
        return $this->allowed;
    }

    public function toArray(): array {
        return [
            'allowed' => $this->allowed,
            'matched_rule' => $this->matchedRule,
            'reason' => $this->reason,
            'effective_mask' => $this->effectiveMask,
            'effective_operations' => PermissionOperation::toString($this->effectiveMask),
            'audit_context' => $this->auditContext,
        ];
    }
}
