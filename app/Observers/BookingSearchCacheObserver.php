<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Events\BookingSearchCacheMutated;
use App\Models\Booking;
use Carbon\Carbon;

/**
 * Broadcasts a BookingSearchCacheMutated whenever a booking row
 * changes in a way that affects the dashboard search cache — so an
 * operator's local IDB store can upsert / evict without waiting for
 * the periodic pull.
 *
 * "Eligible" mirrors SearchCacheController: booking is CheckedIn /
 * Confirmed / OnHold, OR its checkin_date sits within the rolling
 * window (today-3d..today+30d).
 *
 * Emits `upsert` when the booking should be in the cache,
 * `delete` when it must leave (status flipped to cancelled/no_show,
 * or checkin scrolled out of the window).
 */
class BookingSearchCacheObserver
{
    private const AFFECTING_COLUMNS = [
        'booking_status',
        'guest_name',
        'guest_email',
        'guest_phone',
        'room_id',
        'checkin_date',
        'checkout_date',
    ];

    private const LIVE_STATUSES = [
        BookingStatus::CheckedIn,
        BookingStatus::Confirmed,
        BookingStatus::OnHold,
    ];

    /** Statuses that ALWAYS evict from cache, regardless of check-in date. */
    private const DEAD_STATUSES = [
        BookingStatus::Cancelled,
        BookingStatus::NoShow,
    ];

    public function created(Booking $booking): void
    {
        $this->dispatch($booking);
    }

    public function updated(Booking $booking): void
    {
        // Skip the broadcast when no search-cache-relevant column
        // changed. Same booking, same aggregate — nothing for the
        // client cache to redo.
        $changes = array_keys($booking->getChanges());
        if (empty(array_intersect($changes, self::AFFECTING_COLUMNS))) {
            return;
        }
        $this->dispatch($booking);
    }

    public function deleted(Booking $booking): void
    {
        event(BookingSearchCacheMutated::deleteFor(
            tenantId: (int) $booking->tenant_id,
            bookingId: (int) $booking->id,
        ));
    }

    private function dispatch(Booking $booking): void
    {
        if ($this->isEligibleForCache($booking)) {
            event(BookingSearchCacheMutated::upsertFor($booking));
        } else {
            event(BookingSearchCacheMutated::deleteFor(
                tenantId: (int) $booking->tenant_id,
                bookingId: (int) $booking->id,
            ));
        }
    }

    private function isEligibleForCache(Booking $booking): bool
    {
        // Cancelled / no-show are always out — even a future check-in
        // date doesn't rescue them.
        if (in_array($booking->booking_status, self::DEAD_STATUSES, true)) {
            return false;
        }
        if (in_array($booking->booking_status, self::LIVE_STATUSES, true)) {
            return true;
        }
        $checkin = $booking->checkin_date;
        if ($checkin === null) {
            return false;
        }
        $today = Carbon::today();
        return $checkin->betweenIncluded(
            $today->copy()->subDays(3),
            $today->copy()->addDays(30),
        );
    }
}
