<?php

declare(strict_types=1);

namespace App\Livewire\Admin\PaystackFallback;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Cross-tenant ops report of bookings that landed via the platform
 * Paystack fallback, i.e. tenants without a subaccount + without a
 * legacy secret key. Money for these bookings sits in Keverna's
 * platform account and needs to be paid out to the operator manually.
 *
 * Source of truth: activity_log rows where `event = 'paystack_platform_fallback'`,
 * stamped by {@see \App\Actions\Bookings\InitiateBookingPayment::resolvePaystackClient}
 * every time it falls back. Each row carries `tenant_id`, `tenant_slug`,
 * `booking_ref`, `amount` in its properties JSON.
 *
 * We cross-check each activity row against the payments table: if the
 * matching Payment row for the booking isn't in Succeeded status, the
 * guest never completed checkout, so we exclude it from the payout
 * totals (charged column) while still counting the initiation.
 */
#[Layout('admin.layout')]
class Index extends Component
{
    /** Filter: 'all' | 'unresolved' (default) | 'resolved'. */
    #[Url(as: 'view')]
    public string $viewFilter = 'unresolved';

    public function render(): View
    {
        $rows = $this->buildRows();

        return view('livewire.admin.paystack-fallback.index', [
            'rows'         => $rows,
            'kpi'          => [
                'tenants'  => count($rows),
                'bookings' => array_sum(array_column($rows, 'succeeded_bookings')),
                'amount'   => array_sum(array_column($rows, 'succeeded_amount')),
                'unpaid_tenants' => Tenant::query()
                    ->whereNull('paystack_subaccount_code')
                    ->whereNull('paystack_secret_key')
                    ->count(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRows(): array
    {
        // Booking IDs whose init used the fallback path, grouped by
        // tenant. Activity properties are stored as JSON; both MySQL
        // and PostgreSQL support `->>` for text extraction.
        $activities = Activity::query()
            ->where('event', 'paystack_platform_fallback')
            ->orderByDesc('id')
            ->get();

        if ($activities->isEmpty()) {
            return [];
        }

        // Payment ledger for these bookings, a booking counts as
        // "collected" only when its Payment row is Succeeded (i.e. the
        // guest actually completed checkout). Withdrawn / abandoned
        // sessions leave Pending rows we exclude.
        $bookingIds = $activities->pluck('subject_id')->filter()->unique()->all();
        $succeededByBooking = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('booking_id', $bookingIds)
            ->where('status', PaymentStatus::Succeeded->value)
            ->select('booking_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('booking_id')
            ->pluck('paid', 'booking_id');

        // Group activities per tenant and roll up.
        $grouped = $activities->groupBy(fn (Activity $a) => (int) ($a->properties['tenant_id'] ?? 0))
            ->filter(fn ($_, $k) => $k > 0);

        $tenants = Tenant::query()
            ->whereIn('id', $grouped->keys()->all())
            ->get()
            ->keyBy('id');

        return $grouped->map(function ($activities, $tenantId) use ($tenants, $succeededByBooking): array {
            $tenant = $tenants->get($tenantId);
            $totalAmount = 0;
            $latest = null;
            $countedBookings = [];   // dedupe: multiple fallback attempts for the same booking
            $succeededAmount = 0;

            foreach ($activities as $a) {
                $totalAmount += (int) ($a->properties['amount'] ?? 0);
                if ($latest === null || $a->created_at > $latest) {
                    $latest = $a->created_at;
                }
                $bid = (int) $a->subject_id;
                if ($bid > 0 && ! isset($countedBookings[$bid]) && isset($succeededByBooking[$bid])) {
                    $succeededAmount += (int) $succeededByBooking[$bid];
                    $countedBookings[$bid] = true;
                }
            }

            return [
                'tenant_id'          => (int) $tenantId,
                'tenant_name'        => $tenant?->name ?? '(deleted tenant)',
                'tenant_slug'        => $tenant?->slug ?? (string) ($activities->first()->properties['tenant_slug'] ?? ''),
                'has_own_paystack'   => $tenant?->hasOwnPaystackPayouts() ?? false,
                'total_bookings'     => $activities->count(),
                'succeeded_bookings' => count($countedBookings),
                'total_amount'       => $totalAmount,
                'succeeded_amount'   => $succeededAmount,
                'latest_at'          => $latest,
            ];
        })
        ->when(
            $this->viewFilter === 'unresolved',
            fn ($c) => $c->filter(fn ($r) => ! $r['has_own_paystack']),
        )
        ->when(
            $this->viewFilter === 'resolved',
            fn ($c) => $c->filter(fn ($r) => $r['has_own_paystack']),
        )
        ->sortByDesc('succeeded_amount')
        ->values()
        ->all();
    }
}
