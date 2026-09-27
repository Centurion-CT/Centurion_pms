<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Platform-wide booking volume + GMV.
 *
 * Complements the existing Revenue page, Revenue covers what Keverna
 * earned (subscription ARR + transaction fees); this view shows the
 * underlying tenant business: how many bookings, how much GMV is
 * flowing through the platform, and which tenants are driving it.
 *
 * GMV is summed from `bookings.total_amount` across non-cancelled,
 * non-no-show rows in the chosen window. Pending bookings are
 * excluded because they may never confirm.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    /**
     * Trailing window in days. Constrained to the three buttons in
     * the view; arbitrary values fall back to 30.
     */
    #[Url(as: 'window')]
    public string $windowDays = '30';

    /**
     * Statuses we treat as realised revenue for GMV. Excludes
     * Pending (not yet captured), Cancelled, and NoShow.
     *
     * @var list<string>
     */
    private const REALISED_STATUSES = [
        'confirmed',
        'on_hold',
        'checked_in',
        'checked_out',
    ];

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

        return view('livewire.admin.bookings.index', [
            'windowDays'   => $days,
            'stats'        => $this->stats($windowStart),
            'topTenants'   => $this->topTenants($windowStart),
            'statusMix'    => $this->statusMix($windowStart),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(Carbon $windowStart): array
    {
        $base = Booking::query()->withoutGlobalScope(TenantScope::class);

        // Volume, every booking created in the window, irrespective
        // of status, so cancellations still count toward "how busy was
        // the platform".
        $totalVolume = (clone $base)
            ->where('created_at', '>=', $windowStart)
            ->count();

        $realisedVolume = (clone $base)
            ->where('created_at', '>=', $windowStart)
            ->whereIn('booking_status', self::REALISED_STATUSES)
            ->count();

        $cancelled = (clone $base)
            ->where('created_at', '>=', $windowStart)
            ->whereIn('booking_status', [
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ])
            ->count();

        $gmv = (int) (clone $base)
            ->where('created_at', '>=', $windowStart)
            ->whereIn('booking_status', self::REALISED_STATUSES)
            ->sum('total_amount');

        $kevernaFees = (int) (clone $base)
            ->where('created_at', '>=', $windowStart)
            ->whereIn('booking_status', self::REALISED_STATUSES)
            ->sum('keverna_fee');

        $aov = $realisedVolume > 0 ? intdiv($gmv, $realisedVolume) : 0;

        $cancellationPct = $totalVolume > 0
            ? round(($cancelled / $totalVolume) * 100, 1)
            : 0.0;

        return [
            'total_volume'        => $totalVolume,
            'realised_volume'     => $realisedVolume,
            'cancelled'           => $cancelled,
            'cancellation_pct'    => $cancellationPct,
            'gmv_ngn'             => $gmv,
            'keverna_fees_ngn'    => $kevernaFees,
            'aov_ngn'             => $aov,
        ];
    }

    /**
     * Top 10 tenants by GMV in the window.
     *
     * @return Collection<int, object>
     */
    private function topTenants(Carbon $windowStart): Collection
    {
        $rows = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('created_at', '>=', $windowStart)
            ->whereIn('booking_status', self::REALISED_STATUSES)
            ->selectRaw('tenant_id, COUNT(*) as bookings_count, SUM(total_amount) as gmv, SUM(keverna_fee) as keverna_fees')
            ->groupBy('tenant_id')
            ->orderByDesc('gmv')
            ->limit(10)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Resolve tenant names in a single query rather than N+1.
        $tenants = Tenant::query()
            ->whereIn('id', $rows->pluck('tenant_id')->all())
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($tenants): object {
            $tenant = $tenants->get((int) $row->tenant_id);

            return (object) [
                'tenant'          => $tenant,
                'bookings_count'  => (int) $row->bookings_count,
                'gmv'             => (int) $row->gmv,
                'keverna_fees'    => (int) $row->keverna_fees,
            ];
        });
    }

    /**
     * Status breakdown over the window, used for a thin proportion
     * bar so the cancellation rate is visible at a glance.
     *
     * @return Collection<int, object>
     */
    private function statusMix(Carbon $windowStart): Collection
    {
        $rows = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('booking_status, COUNT(*) as n')
            ->groupBy('booking_status')
            ->orderByDesc('n')
            ->get();

        $total = (int) $rows->sum('n');
        if ($total === 0) {
            return collect();
        }

        return $rows->map(function ($r) use ($total): object {
            // Booking casts `booking_status` to BookingStatus, so the
            // selectRaw column hydrates as an enum, collapse to the
            // string value so the view's match() arms and str_replace
            // can compare against scalar status keys.
            $status = $r->booking_status instanceof \App\Enums\BookingStatus
                ? $r->booking_status->value
                : (string) $r->booking_status;

            return (object) [
                'status' => $status,
                'count'  => (int) $r->n,
                'pct'    => round(((int) $r->n / $total) * 100, 1),
            ];
        });
    }
}
