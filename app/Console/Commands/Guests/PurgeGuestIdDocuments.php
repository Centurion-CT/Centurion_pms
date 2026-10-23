<?php

declare(strict_types=1);

namespace App\Console\Commands\Guests;

use App\Models\GuestIdDocument;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Guests\GuestIdDocuments;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Daily NDPA retention sweep for guest ID documents captured at
 * check-in: per tenant, wipes number, nationality, date of birth,
 * expiry and the scan once `retain_until` (check-out +
 * keverna.guest_id_retention_months) has passed. The row stays with its
 * type and `purged_at` as the record that an ID was seen.
 */
class PurgeGuestIdDocuments extends Command
{
    protected $signature = 'guests:purge-id-documents {--date= : Treat this day (Y-m-d) as today}';

    protected $description = 'Delete guest ID data and scans past their retention period';

    public function handle(GuestIdDocuments $documents): int
    {
        $today = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : Carbon::today();

        $tenantIds = GuestIdDocument::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereNull('purged_at')
            ->whereDate('retain_until', '<', $today->toDateString())
            ->distinct()
            ->pluck('tenant_id');

        $total = 0;
        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                continue;
            }
            $tenant->makeCurrent();
            try {
                $total += $documents->purge($today);
            } catch (Throwable $e) {
                report($e);
            } finally {
                Tenant::forgetCurrent();
            }
        }

        $this->components->info("Guest ID documents purged: {$total}");

        return self::SUCCESS;
    }
}
