<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired the first time a Subscription row transitions from Failed/Trial to
 * Active (PRD §3 / §4.2). Listeners react by provisioning the subdomain,
 * sending the welcome mailer, and unlocking the tenant if it was in grace.
 */
class SubscriptionActivated
{
    use Dispatchable;

    public function __construct(public Subscription $subscription) {}
}
