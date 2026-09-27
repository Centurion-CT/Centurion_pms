<?php

declare(strict_types=1);

namespace App\Console\Commands\Customers;

use App\Models\Customer;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Customers\CustomerLinker;
use Illuminate\Console\Command;

/**
 * Repairs stale customers.lifetime_value / total_bookings /
 * first_booking_at / last_booking_at rows for every tenant.
 *
 * The `BookingCustomerAggregateObserver` keeps these fresh on every
 * booking save going forward, but historical drift (from webhook
 * confirms, cron auto-cancels, or plain skipped calls) needs a
 * sweep to fix. Idempotent — running it a second time is a no-op.
 *
 * Usage:
 *   php artisan keverna:customers:recompute
 *   php artisan keverna:customers:recompute --tenant=citistay-apartments
 *   php artisan keverna:customers:recompute --dry-run
 */
class RecomputeAggregates extends Command
{
    protected $signature = 'keverna:customers:recompute
        {--tenant= : Restrict to a single tenant slug}
        {--dry-run : Compute + report drift but do not save}';

    protected $description = 'Recompute customers.lifetime_value + related aggregates from bookings.';

    public function handle(CustomerLinker $linker): int
    {
        $dry = (bool) $this->option('dry-run');
        $tenantSlug = (string) $this->option('tenant');

        $tenantsQ = Tenant::query();
        if ($tenantSlug !== '') {
            $tenantsQ->where('slug', $tenantSlug);
        }
        $tenants = $tenantsQ->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenants matched.');
            return self::FAILURE;
        }

        $totalFixed = 0;
        $totalScanned = 0;
        $beforeSum = 0;
        $afterSum = 0;

        foreach ($tenants as $tenant) {
            $tenant->makeCurrent();

            $customers = Customer::query()->get();
            foreach ($customers as $customer) {
                $totalScanned++;
                $before = (int) $customer->lifetime_value;
                $beforeSum += $before;

                if ($dry) {
                    // Compute what would be written without persisting.
                    $peek = $this->peek($customer);
                    $afterSum += $peek;
                    if ($peek !== $before) {
                        $totalFixed++;
                        $this->line(sprintf(
                            '  [%s] %s: %s -> %s',
                            $tenant->slug, $customer->name,
                            number_format($before), number_format($peek),
                        ));
                    }
                    continue;
                }

                $linker->recomputeAggregates($customer);
                $after = (int) $customer->fresh()->lifetime_value;
                $afterSum += $after;
                if ($after !== $before) {
                    $totalFixed++;
                    $this->line(sprintf(
                        '  [%s] %s: %s -> %s',
                        $tenant->slug, $customer->name,
                        number_format($before), number_format($after),
                    ));
                }
            }

            Tenant::forgetCurrent();
        }

        $this->newLine();
        $this->info(sprintf(
            '%s scanned, %d %s.',
            number_format($totalScanned),
            $totalFixed,
            $dry ? 'would be fixed' : 'fixed',
        ));
        $this->line(sprintf('LTV total before: ₦%s', number_format($beforeSum)));
        $this->line(sprintf('LTV total %s:  ₦%s', $dry ? 'if fixed' : 'after ', number_format($afterSum)));

        return self::SUCCESS;
    }

    /**
     * Compute what recomputeAggregates WOULD write, without touching
     * the row. Mirrors the logic in CustomerLinker so a --dry-run
     * shows the exact same delta as a real run.
     */
    private function peek(Customer $customer): int
    {
        $bookings = \App\Models\Booking::query()
            ->where('customer_id', $customer->id)
            ->get(['booking_status', 'total_amount']);

        $revenueStatuses = [
            \App\Enums\BookingStatus::Confirmed->value,
            \App\Enums\BookingStatus::CheckedIn->value,
            \App\Enums\BookingStatus::CheckedOut->value,
        ];

        return (int) $bookings
            ->filter(fn ($b) => in_array($b->booking_status->value, $revenueStatuses, true))
            ->sum('total_amount');
    }
}
