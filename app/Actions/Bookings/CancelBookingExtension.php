<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingChargeType;
use App\Models\Activity;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Undo an unsettled stay extension. Reverses everything
 * {@see ExtendBooking} did for the picked Extension charge:
 *
 *  1. Deletes the Booked-type AvailabilityBlock rows for the
 *     extension nights (checkout minus the extension length →
 *     current checkout).
 *  2. Rolls the booking's checkout_date / nights / room_total /
 *     total_amount back by the extension's shape.
 *  3. Deletes the BookingCharge row itself.
 *  4. Leaves balance_due alone - ExtendBooking never bumped it,
 *     the extension was captured only on the charge.
 *
 * Refuses to run when:
 *  - the charge is not an Extension line
 *  - the charge is already settled (guest paid → refund workflow,
 *    not a silent reversal)
 *  - reversing the checkout would land on or before check-in
 *
 * Any Overstay charges that were waived by the original
 * ExtendBooking are NOT re-materialised - the operator can re-add
 * them if the guest genuinely overstayed. Auto-overstay will re-fire
 * on the next cron tick anyway once checkout_date rolls back and
 * today is still past it.
 */
class CancelBookingExtension
{
    /**
     * @throws RuntimeException on state/validation problems
     */
    public function handle(BookingCharge $charge, Operator $operator): Booking
    {
        if ($charge->type !== BookingChargeType::Extension) {
            throw new RuntimeException(
                'Only Extension charges can be cancelled here.'
            );
        }
        if ($charge->settled) {
            throw new RuntimeException(
                'This extension has already been paid - issue a refund instead of cancelling.'
            );
        }

        $booking = $charge->booking()->firstOrFail();
        $room = $booking->room;
        if ($room === null) {
            throw new RuntimeException(
                'Cannot cancel an extension on a booking with no room assigned.'
            );
        }

        // Recover the extension's night-count from the activity log.
        // ExtendBooking stamps 'extended' activity with the exact
        // extra-nights value under withProperties(). Fall back to
        // parsing the charge description if the activity row was
        // pruned (unlikely in production, harmless in tests).
        $extraNights = $this->recoverExtraNights($charge);
        if ($extraNights < 1) {
            throw new RuntimeException(
                'Cannot determine how many nights this extension added, refusing to reverse.'
            );
        }

        $currentCheckout = $booking->checkout_date->copy()->startOfDay();
        $newCheckout = $currentCheckout->copy()->subDays($extraNights);

        if ($newCheckout->lessThanOrEqualTo($booking->checkin_date->copy()->startOfDay())) {
            throw new RuntimeException(
                'Reversing this extension would leave the booking with no nights.'
            );
        }

        // Post-checkin discounts applied AFTER this extension are
        // reversed as part of the cancel - otherwise the discount
        // amount stays lodged on manual_discount_amount + the missing
        // ₦ stays subtracted from total_amount, which is exactly the
        // bug operators hit when they cancel an extension they'd
        // already given the guest a "sorry for the mess" cut on.
        //
        // Only discounts applied strictly AFTER the extension charge
        // was written are reversed. Pre-existing discounts (baked
        // into the original booking or granted before the extend)
        // are left alone.
        $postExtendDiscounts = $this->postExtendDiscountReversals($booking, $charge);

        return DB::transaction(function () use (
            $booking, $room, $charge, $operator,
            $extraNights, $currentCheckout, $newCheckout,
            $postExtendDiscounts,
        ): Booking {
            // Release exactly the extension nights: everything from
            // the reversed checkout (inclusive) up to but not
            // including the current checkout. Kept scoped to
            // room_id + type=Booked so a pre-existing operator
            // Blocked row on those nights survives.
            AvailabilityBlock::query()
                ->where('room_id', $room->id)
                ->where('booking_id', $booking->id)
                ->where('type', AvailabilityBlockType::Booked->value)
                ->whereDate('date', '>=', $newCheckout->toDateString())
                ->whereDate('date', '<', $currentCheckout->toDateString())
                ->delete();

            $discountRewind = array_sum(array_column($postExtendDiscounts, 'amount'));

            // Charges written by the post-fix extend flow have their
            // amount already reduced by the applied discount + carry
            // "· ₦X discount applied" in the description. Adding
            // $discountRewind back to total_amount would double-count
            // the reversal there (the charge amount already netted
            // the discount out). Charges written by the OLD flow
            // still carry the full pre-discount amount, so the
            // rewind IS needed for them. We also always reduce
            // room_total by the ORIGINAL extension value (nights ×
            // rate) - not the discounted charge amount - so the
            // per-night rate math stays truthful post-cancel.
            $chargeAlreadyReflectsDiscount = str_contains((string) $charge->description, 'discount applied');
            $totalRewindFromDiscount = $chargeAlreadyReflectsDiscount ? 0 : $discountRewind;
            $originalExtensionValue = (int) $charge->amount
                + ($chargeAlreadyReflectsDiscount ? $discountRewind : 0);

            // Roll back the derived totals. `balance_due` stays put:
            //   - ExtendBooking never bumped it (extension money
            //     rides on the charge, not balance_due).
            //   - ApplyPostCheckinDiscount subtracts
            //     min(balance_due, amount) - capped at 0. Without a
            //     snapshot of the pre-discount balance we can't
            //     tell how much actually came off, so restoring
            //     `+ $discountRewind` blindly would over-restore
            //     for guests who'd already paid before the
            //     discount landed. Under-restoring is fine here:
            //     the operator can add a charge if the guest
            //     genuinely owes the difference.
            $newTotalNights = max(0, (int) ($booking->nights ?? 0) - $extraNights);
            $newTotal = max(0, (int) ($booking->total_amount ?? 0) - (int) $charge->amount + $totalRewindFromDiscount);
            $newRoomTotal = max(0, (int) ($booking->room_total ?? 0) - $originalExtensionValue);
            $newManualDiscount = max(0, (int) ($booking->manual_discount_amount ?? 0) - $discountRewind);

            $booking->forceFill([
                'checkout_date'          => $newCheckout->toDateString(),
                'nights'                 => $newTotalNights,
                'total_amount'           => $newTotal,
                'room_total'             => $newRoomTotal,
                'manual_discount_amount' => $newManualDiscount,
            ])->save();

            $chargeAmount = (int) $charge->amount;
            $chargeId = $charge->id;
            $charge->delete();

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('extension_cancelled')
                ->withProperties([
                    'extension_charge_id'    => $chargeId,
                    'nights_released'        => $extraNights,
                    'amount_voided'          => $chargeAmount,
                    'checkout_rolled_to'     => $newCheckout->toDateString(),
                    'post_extend_discount_reversed' => $discountRewind,
                    'post_extend_discount_events'   => array_column($postExtendDiscounts, 'activity_id'),
                ])
                ->log(sprintf(
                    'Operator %s cancelled a %d-night extension on %s (₦%s voided%s, checkout rolled back to %s).',
                    $operator->name,
                    $extraNights,
                    $booking->booking_ref,
                    number_format($chargeAmount),
                    $discountRewind > 0
                        ? sprintf(' + ₦%s post-extend discount reversed', number_format($discountRewind))
                        : '',
                    $newCheckout->toDateString(),
                ));

            return $booking->refresh();
        });
    }

    /**
     * ExtendBooking stamps the extra-nights count into the charge
     * description as "... · N extra night(s)". Pulling from the
     * description keeps the reversal self-contained: no dependency
     * on the activity log surviving between extend and cancel.
     */
    private function recoverExtraNights(BookingCharge $charge): int
    {
        if (preg_match('/(\d+)\s+extra\s+night/i', (string) $charge->description, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Collect every post_checkin_discount_applied event that landed
     * on this booking AFTER the extension charge was created. Each
     * event's `amount` is the ₦ off that ApplyPostCheckinDiscount
     * subtracted from total_amount + added to manual_discount_amount;
     * cancelling the extension needs those undone in the same
     * transaction so the "MANUAL DISCOUNT" tile doesn't keep growing
     * across an extend / discount / cancel cycle.
     *
     * @return list<array{activity_id: int, amount: int}>
     */
    private function postExtendDiscountReversals(Booking $booking, BookingCharge $charge): array
    {
        $extendedAt = $charge->created_at;
        if ($extendedAt === null) {
            return [];
        }

        $activities = Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->id)
            ->where('event', 'post_checkin_discount_applied')
            ->where('created_at', '>=', $extendedAt)
            ->orderBy('id')
            ->get(['id', 'properties']);

        $out = [];
        foreach ($activities as $act) {
            $props = $act->properties ?? collect();
            $amount = (int) (is_array($props) ? ($props['amount'] ?? 0) : $props->get('amount', 0));
            if ($amount <= 0) {
                continue;
            }
            $out[] = ['activity_id' => (int) $act->id, 'amount' => $amount];
        }

        return $out;
    }
}
