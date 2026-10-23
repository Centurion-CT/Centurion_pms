<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Payment;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money still held on a booking (cancelled / no-show stays):
 *
 *   held = succeeded payments − refunded payments − kept-as-forfeit
 *
 * "Kept as forfeit" is a settled DepositForfeit charge written by
 * {@see \App\Actions\Bookings\ForfeitHeldBookingMoney} with
 * settled_method = held_forfeit. No-show deposit forfeits recorded by
 * MarkNoShow use a different settled_method and do NOT reduce held,
 * so their existing behaviour on the Refunds page is unchanged.
 *
 * Single source for the Refunds page, its CSV export, Finance's
 * pending-refunds figure and the sidebar badge. Queries run through
 * the models' tenant scope, same as the call sites did before.
 */
final class HeldMoney
{
    public const FORFEIT_METHOD = 'held_forfeit';

    /**
     * Net money taken on a booking from an already-loaded payments
     * collection: succeeded payments minus refund rows (refunds are
     * written as separate Refunded rows by RecordBookingRefund).
     * Pending / failed rows are ignored. Used by the reservation
     * panels' "Paid" figure so they reuse the eager-loaded relation
     * instead of firing a query per booking.
     *
     * @param  iterable<\App\Models\Payment>  $payments
     */
    public static function netPaid(iterable $payments): int
    {
        $net = 0;
        foreach ($payments as $p) {
            $status = $p->status instanceof PaymentStatus ? $p->status : PaymentStatus::tryFrom((string) $p->status);
            if ($status === PaymentStatus::Succeeded) {
                $net += (int) $p->amount;
            } elseif ($status === PaymentStatus::Refunded) {
                $net -= (int) $p->amount;
            }
        }

        return max(0, $net);
    }

    /**
     * @param  iterable<int>  $bookingIds
     * @return array<int, array{paid:int, refunded:int, forfeited:int, held:int}>
     */
    public function byBooking(iterable $bookingIds): array
    {
        $ids = collect($bookingIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = Payment::query()
            ->whereIn('booking_id', $ids)
            ->whereIn('status', [PaymentStatus::Succeeded->value, PaymentStatus::Refunded->value])
            ->toBase()
            ->selectRaw("booking_id,
                SUM(CASE WHEN status = 'succeeded' THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refunded")
            ->groupBy('booking_id')
            ->get();
        foreach ($rows as $r) {
            $out[(int) $r->booking_id] = ['paid' => (int) $r->paid, 'refunded' => (int) $r->refunded, 'forfeited' => 0, 'held' => 0];
        }

        $forfeits = BookingCharge::query()
            ->whereIn('booking_id', $ids)
            ->where('type', BookingChargeType::DepositForfeit->value)
            ->where('settled_method', self::FORFEIT_METHOD)
            ->toBase()
            ->selectRaw('booking_id, SUM(amount) as forfeited')
            ->groupBy('booking_id')
            ->get();
        foreach ($forfeits as $f) {
            $id = (int) $f->booking_id;
            $out[$id] ??= ['paid' => 0, 'refunded' => 0, 'forfeited' => 0, 'held' => 0];
            $out[$id]['forfeited'] = (int) $f->forfeited;
        }

        foreach ($out as $id => $row) {
            $out[$id]['held'] = max(0, $row['paid'] - $row['refunded'] - $row['forfeited']);
        }

        return $out;
    }

    /** Booking statuses whose money can still be "held" (Refunds page). */
    public const HELD_STATUSES = [BookingStatus::Cancelled, BookingStatus::NoShow];

    /**
     * Booking ids still holding money: cancelled / no-show bookings
     * (optionally narrowed to $statuses) with held > 0, scoped to a
     * branch through the booking's room. Single source for the web
     * Refunds page list + KPIs and the host app inbox.
     *
     * @param  list<string>|null  $statuses  booking status values; null = all HELD_STATUSES
     * @param  (Closure(Builder<Booking>): void)|null  $constrain  extra filters (search, dates)
     * @return list<int>
     */
    public function pendingBookingIds(?int $propertyId, ?array $statuses = null, ?Closure $constrain = null): array
    {
        $statuses ??= array_map(fn (BookingStatus $s) => $s->value, self::HELD_STATUSES);

        $query = Booking::query()->whereIn('booking_status', $statuses);
        if ($propertyId !== null) {
            $query->whereHas('room', fn ($r) => $r->where('property_id', $propertyId));
        }
        if ($constrain !== null) {
            $constrain($query);
        }

        $candidateIds = $query->pluck('id')->all();
        if ($candidateIds === []) {
            return [];
        }

        $held = $this->byBooking($candidateIds);
        $qualifying = [];
        foreach ($candidateIds as $bid) {
            if ((int) ($held[$bid]['held'] ?? 0) > 0) {
                $qualifying[] = (int) $bid;
            }
        }

        return $qualifying;
    }

    /** Money still held on one booking. */
    public function heldFor(Booking $booking): int
    {
        return (int) ($this->byBooking([$booking->id])[$booking->id]['held'] ?? 0);
    }
}
