<?php

declare(strict_types=1);

namespace App\Services\Discounts;

use App\Enums\BookingSource;
use App\Models\Discount;
use App\Models\Room;
use Illuminate\Support\Carbon;

/**
 * Single point of truth for evaluating which discount(s) apply to a
 * given booking attempt.
 *
 * Two flows are supported:
 *
 *   1. **By code** — operator or guest typed a coupon code. We look
 *      up the discount by case-insensitive code, then verify every
 *      condition (validity window, stay-length, channel, room
 *      targeting, usage cap, active). A typo or expired code returns
 *      null + a reason string for surfacing to the UI.
 *
 *   2. **Auto-apply** — no code typed. We find the BEST applicable
 *      `code IS NULL` discount for the booking. "Best" = largest
 *      `amount_off` against the supplied netSubtotal. Ties broken by
 *      most-recently-created discount.
 *
 * Phase 1 does NOT stack — at most one discount applies per booking.
 * Returning a {@see DiscountResolution} keeps the call site clean: it
 * gets the discount + the actual ₦ off + a reason when nothing applied.
 */
class DiscountEngine
{
    /**
     * Find the best auto-apply discount for these stay parameters.
     */
    public function bestAutoApply(
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        BookingSource $channel,
        int $netSubtotal,
    ): ?DiscountResolution {
        $candidates = Discount::query()
            ->active()
            ->autoApply()
            ->get();

        return $this->pickBest($candidates, $room, $checkin, $checkout, $channel, $netSubtotal);
    }

    /**
     * Resolve a typed coupon code. Returns null only if the code
     * truly doesn't exist; an existing-but-not-applicable code returns
     * a resolution with `applies = false` and a reason.
     */
    public function resolveByCode(
        string $code,
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        BookingSource $channel,
        int $netSubtotal,
    ): ?DiscountResolution {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $discount = Discount::query()
            ->active()
            ->withCode($code)
            ->first();

        if ($discount === null) {
            return DiscountResolution::rejected(
                code: $code,
                reason: 'No discount found for that code.',
            );
        }

        $rejection = $this->whyNotApplicable($discount, $room, $checkin, $checkout, $channel);
        if ($rejection !== null) {
            return DiscountResolution::rejected(
                code: $code,
                reason: $rejection,
                discount: $discount,
            );
        }

        $amountOff = $discount->computeAmountOff($netSubtotal);

        return DiscountResolution::accepted(
            discount: $discount,
            amountOff: $amountOff,
            code: $code,
            autoApplied: false,
        );
    }

    /**
     * Increment a discount's `used_count` atomically. Caller invokes
     * this inside the same transaction as the booking creation so a
     * race that hits `max_uses` rolls back cleanly.
     *
     * Returns true if the increment respected the cap; false means
     * another concurrent booking already took the last slot — caller
     * should bail out.
     */
    public function recordUsage(Discount $discount): bool
    {
        if ($discount->max_uses === null) {
            $discount->increment('used_count');

            return true;
        }

        // Atomic UPDATE … WHERE used_count < max_uses. Affected rows
        // count tells us whether we actually got a slot.
        $affected = Discount::query()
            ->where('id', $discount->id)
            ->where('used_count', '<', $discount->max_uses)
            ->update(['used_count' => $discount->used_count + 1]);

        return $affected > 0;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Discount>  $candidates
     */
    private function pickBest(
        $candidates,
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        BookingSource $channel,
        int $netSubtotal,
    ): ?DiscountResolution {
        $best = null;
        $bestAmount = 0;
        foreach ($candidates as $candidate) {
            if ($this->whyNotApplicable($candidate, $room, $checkin, $checkout, $channel) !== null) {
                continue;
            }
            $amount = $candidate->computeAmountOff($netSubtotal);
            if ($amount > $bestAmount) {
                $best = $candidate;
                $bestAmount = $amount;
            } elseif ($best !== null && $amount === $bestAmount) {
                // Tie-break: prefer the more recently created rule —
                // operators expect their latest promo to "win".
                if ($candidate->id > $best->id) {
                    $best = $candidate;
                }
            }
        }

        if ($best === null || $bestAmount <= 0) {
            return null;
        }

        return DiscountResolution::accepted(
            discount: $best,
            amountOff: $bestAmount,
            code: null,
            autoApplied: true,
        );
    }

    /**
     * Returns a human-readable reason a discount does NOT apply, or
     * null if it does. Centralised so the by-code and auto-apply flows
     * use the same validation.
     */
    private function whyNotApplicable(
        Discount $d,
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        BookingSource $channel,
    ): ?string {
        if (! $d->active) {
            return 'Discount is no longer active.';
        }

        if ($d->max_uses !== null && $d->used_count >= $d->max_uses) {
            return 'Discount has reached its usage cap.';
        }

        // Validity window — checkin date must overlap with the rule.
        if ($d->starts_on !== null && $checkin->lessThan($d->starts_on)) {
            return 'Discount starts on '.$d->starts_on->toFormattedDateString().'.';
        }
        if ($d->ends_on !== null && $checkin->greaterThanOrEqualTo($d->ends_on)) {
            return 'Discount expired on '.$d->ends_on->toFormattedDateString().'.';
        }

        // Stay-length window.
        $nights = (int) $checkin->diffInDays($checkout, absolute: true);
        if ($d->min_nights !== null && $nights < $d->min_nights) {
            return sprintf('Requires at least %d night%s.', $d->min_nights, $d->min_nights === 1 ? '' : 's');
        }
        if ($d->max_nights !== null && $nights > $d->max_nights) {
            return sprintf('Capped at %d night%s.', $d->max_nights, $d->max_nights === 1 ? '' : 's');
        }

        // Channel restriction. NULL or empty array = no restriction.
        $channels = $d->channels;
        if (is_array($channels) && count($channels) > 0 && ! in_array($channel->value, $channels, true)) {
            return 'Not valid on this channel.';
        }

        // Targeting precedence:
        //   - room-level pivot rows present   → restrict to those rooms
        //   - category-level pivot rows present → restrict to those categories
        //   - both empty                       → applies to every room
        //
        // Both are checked independently so an operator who set BOTH
        // (rare) gets the more restrictive intersection.
        if ($d->targetsSpecificRooms()) {
            $allowedRoomIds = $d->rooms()->pluck('rooms.id')->all();
            if (! in_array($room->id, $allowedRoomIds, true)) {
                return 'Not valid on this room.';
            }
        }

        if ($d->targetsSpecificRoomCategories()) {
            $allowedCategoryIds = $d->roomCategories()->pluck('room_categories.id')->all();
            if ($room->room_category_id === null
                || ! in_array($room->room_category_id, $allowedCategoryIds, true)
            ) {
                return 'Not valid on this room category.';
            }
        }

        return null;
    }
}
