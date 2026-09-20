<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Exception;

class TagInUseException extends \Exception {
    public function __construct(
        string $message,
        private int $tagId = 0,
        private int $usageCount = 0,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getTagId(): int {
        return $this->tagId;
    }

    public function getUsageCount(): int {
        return $this->usageCount;
    }
}
