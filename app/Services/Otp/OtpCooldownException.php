<?php

declare(strict_types=1);

namespace App\Services\Otp;

use RuntimeException;

/**
 * Thrown by OtpService when the caller asks to issue a new code before the
 * 60-second resend cooldown elapses (PRD §6.1.1).
 */
class OtpCooldownException extends RuntimeException
{
    public function __construct(public readonly int $secondsRemaining)
    {
        parent::__construct("Resend available in {$secondsRemaining} seconds.");
    }
}
