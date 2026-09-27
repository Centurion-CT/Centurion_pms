<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Services\Customers\CustomerLinker;
use Illuminate\Console\Command;

/**
 * One-shot migration helper. Walks every existing Booking row, attaches
 * it to a Customer (creating one if needed), and recomputes aggregates
 * across the whole tenant once each customer is reached.
 *
 * Safe to re-run — the linker's attachToBooking is idempotent on
 * (customer, booking, system_event), so re-running won't double-write
 * timeline rows or inflate lifetime_value.
 *
 * Usage:
 *   php artisan customers:backfill              — every tenant
 *   php artisan customers:backfill --tenant=42  — single tenant
 */
class BackfillCustomersFromBookings extends Command
{
    protected $signature = 'customers:backfill {--tenant= : limit to a single tenant id}';

    protected $description = 'Link existing bookings to Customer records and rebuild CRM aggregates.';

    public function handle(CustomerLinker $linker): int
    {
        $query = Booking::query()->withoutGlobalScopes()->orderBy('id');
        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $totalBookings = (clone $query)->count();
        if ($totalBookings === 0) {
            $this->info('No bookings to backfill.');

            return self::SUCCESS;
        }

        $this->info("Linking {$totalBookings} booking(s) to customers…");
        $bar = $this->output->createProgressBar($totalBookings);
        $bar->start();

        $query->chunkById(200, function ($bookings) use ($linker, $bar): void {
            foreach ($bookings as $booking) {
                $linker->attachToBooking($booking);
                $bar->advance();
            }
        });
        $bar->finish();
        $this->newLine();

        // Final pass: recompute aggregates per customer to ensure every
        // record reflects every linked booking (in case of mid-chunk
        // dependencies).
        $customerQuery = Customer::query()->withoutGlobalScopes();
        if ($tenantId = $this->option('tenant')) {
            $customerQuery->where('tenant_id', (int) $tenantId);
        }
        $totalCustomers = (clone $customerQuery)->count();
        $this->info("Recomputing aggregates for {$totalCustomers} customer(s)…");
        $bar = $this->output->createProgressBar($totalCustomers);
        $bar->start();
        $customerQuery->chunkById(200, function ($customers) use ($linker, $bar): void {
            foreach ($customers as $customer) {
                $linker->recomputeAggregates($customer);
                $bar->advance();
            }
        });
        $bar->finish();
        $this->newLine();

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
