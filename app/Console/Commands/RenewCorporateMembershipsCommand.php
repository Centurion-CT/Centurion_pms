<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Corporates\RenewCorporateMembership;
use Illuminate\Console\Command;

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
 */
class RenewCorporateMembershipsCommand extends Command
{
    /** @var string */
    protected $signature = 'corporates:renew-memberships
                            {--limit=500 : Maximum memberships to process this tick}';

    /** @var string */
    protected $description = 'Charge corporate memberships whose renewal date has arrived';

    public function handle(RenewCorporateMembership $action): int
    {
        $limit = (int) $this->option('limit');
        $result = $action->batch(max(1, $limit));

        $this->info(sprintf(
            'Corporate membership renewals — processed=%d renewed=%d paused=%d skipped=%d',
            $result['processed'], $result['renewed'], $result['paused'], $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
