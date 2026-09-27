<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an operator completes all three onboarding steps (PRD §6.1.1).
 * Downstream listeners (added in later sections):
 *   - Provision the tenant's {slug}.keverna.com DNS record (§5)
 *   - Send the welcome mailer with getting-started checklist (§17)
 *   - Notify FregateLab admin of the new active tenant (§18)
 */
class TenantOnboarded
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant,
        public Operator $operator,
    ) {}
}
