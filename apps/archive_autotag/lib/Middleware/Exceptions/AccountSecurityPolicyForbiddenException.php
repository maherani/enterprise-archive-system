<?php

declare(strict_types=1);

namespace OCA\ArchiveAutoTag\Middleware\Exceptions;

use Exception;
use OCP\AppFramework\Http;
use Throwable;

class AccountSecurityPolicyForbiddenException extends Exception {
    public function __construct(
        string $message = 'Access denied: Credential and settings management is restricted to system administrators.',
        int $code = Http::STATUS_FORBIDDEN,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
