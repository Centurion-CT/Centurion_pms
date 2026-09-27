<?php

declare(strict_types=1);

namespace App\Services\Sendpipe;

use RuntimeException;
use Throwable;

/**
 * Surfaces a structured Sendpipe error envelope (PRD §3 / Sendpipe v1 spec):
 *
 *   { "error": { "code": "VALIDATION_ERROR", "message": "...", "details": [...] } }
 *
 * The HTTP status, error code and the field-level details are preserved so
 * callers can branch on them (e.g. 429 + RATE_LIMITED for backoff, 401 +
 * UNAUTHORIZED for credential rotation alerts).
 */
class SendpipeException extends RuntimeException
{
    /**
     * @param  list<array{field: string, message: string}>  $details
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
