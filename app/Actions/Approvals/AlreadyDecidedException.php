<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use RuntimeException;

/**
 * Thrown by the approval-style actions (guest portal holds, held money,
 * corporate requests) when the item is no longer waiting for a decision
 * because someone else decided it first. The host API maps it to
 * 409 `already_decided`.
 */
class AlreadyDecidedException extends RuntimeException
{
    public function __construct(string $message = 'Someone else already decided this.')
    {
        parent::__construct($message);
    }
}
