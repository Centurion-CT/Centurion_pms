<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Corporates\RenewCorporateMembership;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduled corporate-membership re-billing.
 *
 * Picks up every Active corporate membership whose `next_renewal_at`
 * has arrived, holds + captures the period's price, and advances the
 * renewal date. Memberships whose corporate wallet has insufficient
 * funds are auto-Paused so an admin can top up + resume.
 *
 * Idempotent under a flaky scheduler — each row is locked FOR UPDATE
 * inside the action.
 *
 * Runs once per business with a membership due, with that business
 * current: memberships and corporates are scoped to it, so with no
 * business bound the batch would find nothing.
 */
class RenewCorporateMembershipsCommand extends Command
{
    /** @var string */
    protected $signature = 'corporates:renew-memberships
                            {--limit=500 : Maximum memberships to process per business this tick}';

    /** @var string */
    protected $description = 'Charge corporate memberships whose renewal date has arrived';

    public function handle(RenewCorporateMembership $action): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $result = ['processed' => 0, 'renewed' => 0, 'paused' => 0, 'skipped' => 0];

        $tenantIds = Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNotNull('corporate_id')
            ->where('status', MembershipStatus::Active->value)
            ->where('next_renewal_at', '<=', Carbon::now())
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                continue;
            }

            $tenant->makeCurrent();
            try {
                foreach ($action->batch($limit) as $key => $count) {
                    $result[$key] += $count;
                }
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->info(sprintf(
            'Corporate membership renewals — processed=%d renewed=%d paused=%d skipped=%d',
            $result['processed'], $result['renewed'], $result['paused'], $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
