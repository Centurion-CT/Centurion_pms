<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Booking;
use App\Models\Customer;
use App\Services\Customers\CustomerLinker;

/**
 * Keeps `customers.lifetime_value` + `total_bookings` + `first_booking_at`
 * + `last_booking_at` truthful against the actual booking rows.
 *
 * The linker's `attachToBooking` + `recordBookingEvent` already run in
 * the happy-path Livewire flows (CreateBooking, checkIn, checkOut,
 * cancel, extend). But drift creeps in from paths that flip
 * `booking_status` or edit `total_amount` without calling the linker
 * — Paystack webhooks, scheduled auto-cancel / auto-checkout crons,
 * ad-hoc admin edits, backfill migrations.
 *
 * Hooking Eloquent's `created` / `updated` / `deleted` events means
 * EVERY booking write funnels through here — no code path can
 * accidentally leave the aggregate stale. The recompute is a single
 * `WHERE customer_id` query so the per-save cost is negligible.
 *
 * We use separate created/updated events instead of the combined
 * `saved` because Eloquent's `getChanges()` is populated for updates
 * but empty for inserts, and `wasRecentlyCreated` stays true across
 * subsequent saves on the same instance — neither reliably answers
 * "is this an insert?" from inside `saved`.
 */
class BookingCustomerAggregateObserver
{
    /**
     * Columns whose change would move `lifetime_value` /
     * `total_bookings` / `first_booking_at` / `last_booking_at`.
     * Updates that touch none of these skip the recompute.
     */
    private const AFFECTING_COLUMNS = [
        'customer_id',
        'booking_status',
        'total_amount',
        'checkin_date',
    ];

    /**
     * A brand-new booking with a customer_id already attached
     * (CreateBooking sets it inline via CustomerLinker). Recompute
     * unconditionally — there's no "was it a meaningful change?"
     * to gate on for an insert.
     */
    public function created(Booking $booking): void
    {
        $this->recomputeIfLinked($booking);
    }

    /**
     * Updates only matter when one of the affecting columns moved.
     * If customer_id itself moved, ALSO recompute the prior customer
     * so they don't hold a phantom sum.
     */
    public function updated(Booking $booking): void
    {
        $changed = array_keys($booking->getChanges());
        if (! array_intersect($changed, self::AFFECTING_COLUMNS)) {
            return;
        }

        $this->recomputeIfLinked($booking);

        if ($booking->wasChanged('customer_id')) {
            $original = $booking->getOriginal('customer_id');
            if ($original !== null && (int) $original !== (int) $booking->customer_id) {
                $prior = Customer::query()->find($original);
                if ($prior !== null) {
                    app(CustomerLinker::class)->recomputeAggregates($prior);
                }
            }
        }
    }

    /**
     * Soft-delete counts as "the booking is gone" — the linker's
     * filter-by-`customer_id` won't see the row (soft-deleted rows
     * are excluded by the query scope), so the aggregate naturally
     * drops it. Just re-run.
     */
    public function deleted(Booking $booking): void
    {
        $this->recomputeIfLinked($booking);
    }

    private function recomputeIfLinked(Booking $booking): void
    {
        $customerId = (int) ($booking->customer_id ?? 0);
        if ($customerId === 0) {
            return;
        }
        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return;
        }
        app(CustomerLinker::class)->recomputeAggregates($customer);
    }
}
