<?php

declare(strict_types=1);

namespace App\Services\Dokwe;

use RuntimeException;
use Throwable;

/**
 * Thrown for any DokweClient call that didn't reach a useful terminal state.
 * Carries the HTTP status (when it came from the API) and an optional
 * structured `code` mirroring dokwe's exception filter — e.g.
 * `WORKSPACE_REQUIRES_2FA`, `TOTP_REQUIRED`, `FLEET_NOT_ACTIVATED` — so the
 * caller can branch without parsing free-form `message` strings.
 */
class DokweException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}
