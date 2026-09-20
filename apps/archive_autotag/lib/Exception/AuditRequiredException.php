<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Exception;

use Exception;
use Throwable;

class AuditRequiredException extends Exception {
    private string $operation;
    private string $target;

    public function __construct(
        string $message,
        string $operation = 'unknown',
        string $target = '',
        int $code = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->operation = $operation;
        $this->target = $target;
    }

    public function getOperation(): string {
        return $this->operation;
    }

    public function getTarget(): string {
        return $this->target;
    }
}
