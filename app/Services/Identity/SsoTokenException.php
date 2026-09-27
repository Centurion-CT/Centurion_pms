<?php

declare(strict_types=1);

namespace App\Services\Identity;

use RuntimeException;

/**
 * Discriminated failure kinds for TenantSsoTokenSigner::verify().
 * The consumer controller maps each to a 401/403/404 response — the
 * discriminator lives on the class so log lines are grep-able.
 */
final class SsoTokenException extends RuntimeException
{
    public const KIND_MISSING = 'missing_params';

    public const KIND_EXPIRED = 'expired';

    public const KIND_BAD_SIGNATURE = 'bad_signature';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
