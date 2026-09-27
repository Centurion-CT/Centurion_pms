<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Thrown by the global Eloquent write guard when a tenant with an expired
 * trial (or otherwise-expired subscription) tries to mutate any tenant-scoped
 * model. Rendered as a redirect to /subscription for full-page requests, and
 * as a Livewire-friendly 403 for wire updates.
 */
class SubscriptionExpiredException extends HttpException
{
    public function __construct(
        string $message = 'Your trial has ended. Renew your subscription to make changes.',
        ?Throwable $previous = null,
    ) {
        parent::__construct(statusCode: 403, message: $message, previous: $previous);
    }
}
