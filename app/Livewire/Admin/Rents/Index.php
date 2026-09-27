<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Rents;

use App\Enums\LeasePaymentStatus;
use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\LeasePayment;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Platform-wide rent collection visibility for residential tenants
 * (PropertyType::Residential).
 *
 * Sister page to {@see \App\Livewire\Admin\Bookings\Index}, that
 * covers the short-stay GMV; this covers the lease cashflow. Lifts
 * the tenant scope on both Lease and LeasePayment so admins see every
 * tenant's pipeline in one place.
 *
 * Stats are computed in a trailing window for "collected" / "overdue
 * in window" and as point-in-time totals for "active leases" /
 * "overdue total" (the latter is unbounded, anything past-due, ever).
 */
#[Layout('admin.layout')]
class Index extends Component
{
    #[Url(as: 'window')]
    public string $windowDays = '30';

    public function setWindow(string $days): void
    {
        if (in_array($days, ['7', '30', '90', '365'], true)) {
            $this->windowDays = $days;
        }
    }

    public function render(): View
    {
        $days = in_array($this->windowDays, ['7', '30', '90', '365'], true)
            ? (int) $this->windowDays
            : 30;

        $windowStart = Carbon::now()->subDays($days)->startOfDay();

        return view('livewire.admin.rents.index', [
            'windowDays'  => $days,
            'stats'       => $this->stats($windowStart),
            'topLandlords'=> $this->topLandlords($windowStart),
            'statusMix'   => $this->statusMix(),
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function stats(Carbon $windowStart): array
    {
        $payments = LeasePayment::query()->withoutGlobalScope(TenantScope::class);

        $collectedInWindow = (int) (clone $payments)
            ->where('status', LeasePaymentStatus::Paid->value)
            ->where('paid_at', '>=', $windowStart)
            ->sum('amount_naira');

        $dueOpen = (int) (clone $payments)
            ->whereIn('status', [
                LeasePaymentStatus::Upcoming->value,
                LeasePaymentStatus::Due->value,
            ])
            ->sum('amount_naira');

        // Overdue is a point-in-time total, anything past due that
        // hasn't been paid or waived. Not windowed because aging debt
        // beyond the window is what an admin needs to chase.
        $overdueValue = (int) (clone $payments)
            ->where('status', LeasePaymentStatus::Overdue->value)
            ->sum('amount_naira');

        $overdueCount = (clone $payments)
            ->where('status', LeasePaymentStatus::Overdue->value)
            ->count();

        $activeLeases = Lease::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', LeaseStatus::Active->value)
            ->count();

        $expiringSoon = Lease::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', LeaseStatus::Active->value)
            ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays(60)->endOfDay()])
            ->count();

        // Collection rate: paid / (paid + overdue) for items whose due
        // date has already arrived. Tells you how well operators are
        // collecting in the chosen window.
        $totalRealisedInWindow = (clone $payments)
            ->where('due_at', '>=', $windowStart->toDateString())
            ->where('due_at', '<=', now()->toDateString())
            ->whereIn('status', [
                LeasePaymentStatus::Paid->value,
                LeasePaymentStatus::Overdue->value,
            ])
            ->count();

        $paidInWindow = (clone $payments)
            ->where('due_at', '>=', $windowStart->toDateString())
            ->where('due_at', '<=', now()->toDateString())
            ->where('status', LeasePaymentStatus::Paid->value)
            ->count();

        $collectionPct = $totalRealisedInWindow > 0
            ? round(($paidInWindow / $totalRealisedInWindow) * 100, 1)
            : 0.0;

        return [
            'collected_ngn'    => $collectedInWindow,
            'due_open_ngn'     => $dueOpen,
            'overdue_value'    => $overdueValue,
            'overdue_count'    => $overdueCount,
            'active_leases'    => $activeLeases,
            'expiring_soon'    => $expiringSoon,
            'collection_pct'   => $collectionPct,
        ];
    }

    /**
     * Top 10 tenants by rent collected in the window.
     *
     * @return Collection<int, object>
     */
    private function topLandlords(Carbon $windowStart): Collection
    {
        $rows = LeasePayment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', LeasePaymentStatus::Paid->value)
            ->where('paid_at', '>=', $windowStart)
            ->selectRaw('tenant_id, COUNT(*) as collected_count, SUM(amount_naira) as collected')
            ->groupBy('tenant_id')
            ->orderByDesc('collected')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $tenants = Tenant::query()
            ->whereIn('id', $rows->pluck('tenant_id')->all())
            ->get()
            ->keyBy('id');

        // Co-load active lease + overdue counts per tenant so admins
        // see who is collecting well vs accumulating arrears.
        $tenantIds = $rows->pluck('tenant_id')->all();

        $activeLeasesByTenant = Lease::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', LeaseStatus::Active->value)
            ->selectRaw('tenant_id, COUNT(*) as n')
            ->groupBy('tenant_id')
            ->pluck('n', 'tenant_id');

        $overdueByTenant = LeasePayment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', LeasePaymentStatus::Overdue->value)
            ->selectRaw('tenant_id, SUM(amount_naira) as o')
            ->groupBy('tenant_id')
            ->pluck('o', 'tenant_id');

        return $rows->map(function ($row) use ($tenants, $activeLeasesByTenant, $overdueByTenant): object {
            $tenantId = (int) $row->tenant_id;

            return (object) [
                'tenant'          => $tenants->get($tenantId),
                'collected_count' => (int) $row->collected_count,
                'collected'       => (int) $row->collected,
                'active_leases'   => (int) ($activeLeasesByTenant[$tenantId] ?? 0),
                'overdue'         => (int) ($overdueByTenant[$tenantId] ?? 0),
            ];
        });
    }

    /**
     * Status breakdown across every lease payment ever, the proportion
     * bar tells admins whether the platform's collection health is
     * healthy or accumulating arrears.
     *
     * @return Collection<int, object>
     */
    private function statusMix(): Collection
    {
        $rows = LeasePayment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->orderByDesc('n')
            ->get();

        $total = (int) $rows->sum('n');
        if ($total === 0) {
            return collect();
        }

        return $rows->map(function ($r) use ($total): object {
            $status = $r->status instanceof LeasePaymentStatus
                ? $r->status->value
                : (string) $r->status;

            return (object) [
                'status' => $status,
                'count'  => (int) $r->n,
                'pct'    => round(((int) $r->n / $total) * 100, 1),
            ];
        });
    }
}
