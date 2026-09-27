<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Models\Booking;

/**
 * Return payload for {@see ReassignRoom::handle()}.
 *
 * The `rateDelta` is signed: positive means the guest owes more (new
 * room is pricier); negative means the guest is due a refund or coupon
 * (new room is cheaper). Callers use it to decide which reconciliation
 * flow to open in the UI — collect payment vs. issue refund vs. issue
 * a store-credit coupon.
 *
 * `rateDelta` covers only the REMAINING nights (past nights already
 * spent in the old room stay at the old rate, per hospitality
 * convention). `fullNightsDelta` covers the full booked stay — the
 * larger number the operator should reach for when they're correcting
 * a wrong-room placement rather than accepting a mid-stay downgrade.
 * `remainingNights` and `nights` are surfaced so the UI can explain
 * the two numbers to the operator instead of just showing them.
 */
final readonly class ReassignmentResult
{
    public function __construct(
        public Booking $booking,
        public int $rateDelta,
        public int $oldNightlyRate,
        public int $newNightlyRate,
        public int $nights,
        public int $remainingNights = 0,
        public int $fullNightsDelta = 0,
    ) {}
}
