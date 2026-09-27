<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use App\Services\Pricing\PricingCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Extend an existing booking's checkout date.
 *
 * Operational flow:
 *   1. Validate booking is in a state that can be extended (Confirmed,
 *      OnHold, CheckedIn — never Pending/Cancelled/CheckedOut/NoShow).
 *   2. Validate the new checkout is strictly after the current one.
 *   3. Reserve the extra nights via AvailabilityService — throws
 *      RangeNotAvailableException if the room is sold to another
 *      guest after the current checkout.
 *   4. Price the extra nights with PricingCalculator (uses the same
 *      seasonal overrides + per-room rate logic as a fresh booking).
 *   5. Bump booking.checkout_date / nights / total_amount /
 *      balance_due / balance_due_at to reflect the new stay.
 *   6. Drop an Extension-type charge into booking_charges so the
 *      front-desk console can show it as a separate ledger line.
 *
 * Returns the refreshed booking; caller is responsible for any
 * customer-timeline event (we keep the action pure, the Livewire
 * caller wires CustomerLinker since it has the operator context).
 */
class ExtendBooking
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingCalculator $pricing,
    ) {}

    /**
     * @param  bool|null  $breakfastIncluded  null = inherit the booking's
     *  original breakfast tier. true / false lets the caller override
     *  (operator picks a different tier, or guest opts out).
     *
     * @throws RuntimeException for state/validation problems
     * @throws RangeNotAvailableException for collisions during reserve
     */
    public function handle(Booking $booking, Carbon $newCheckout, Operator $operator, ?bool $breakfastIncluded = null): Booking
    {
        $room = $booking->room;
        if ($room === null) {
            throw new RuntimeException('Cannot extend a booking whose room is missing.');
        }

        $currentCheckout = $booking->checkout_date->copy()->startOfDay();
        $newCheckout = $newCheckout->copy()->startOfDay();

        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::CheckedIn], true)) {
            throw new RuntimeException(
                "Cannot extend a booking in state {$booking->booking_status->value}."
            );
        }

        if ($newCheckout->lessThanOrEqualTo($currentCheckout)) {
            throw new RuntimeException('New checkout must be after the current checkout date.');
        }

        // Extension inherits the original booking's breakfast tier
        // unless the caller says otherwise — a guest who booked with
        // breakfast doesn't silently get downgraded on extend.
        $extensionBreakfast = $breakfastIncluded ?? (bool) $booking->breakfast_included;

        // Price the extension as if it were a stay covering only the
        // added nights — keeps the discount logic + seasonal pricing
        // consistent with the engine's other entry points.
        //
        // Use netSubtotal (not totalDue) — extensions are always
        // handled at the counter by an already-checked-in guest, so
        // the Paystack + Keverna online fees that totalDue tacks on
        // for the guest-facing engine don't apply. Same principle
        // CreateBooking uses for walk-in / OTA / manual sources.
        $extensionBreakdown = $this->pricing->calculate(
            $room, $currentCheckout, $newCheckout,
            breakfastIncluded: $extensionBreakfast,
        );
        $extraNights = $extensionBreakdown->nights;
        $extensionRack = $extensionBreakdown->netSubtotal;

        // Carry the guest's original percent discount forward to the
        // extension nights — a stay booked at 30% off keeps that same
        // 30% off for extra nights. Otherwise every extension quietly
        // charged rack rate and the guest ended up on two rates on the
        // same folio.
        //
        // Only percent discounts inherit. A fixed-amount discount was a
        // one-off credit on the original total, not a rate, so it does
        // NOT scale to more nights.
        $inheritedPercent = ($booking->manual_discount_type === ManualDiscountType::Percent)
            ? (float) $booking->manual_discount_percent
            : 0.0;
        $inheritedPercent = max(0.0, min(100.0, $inheritedPercent));
        $inheritedDiscount = (int) floor($extensionRack * $inheritedPercent / 100);
        $extensionCharge = max(0, $extensionRack - $inheritedDiscount);

        return DB::transaction(function () use (
            $booking, $room, $currentCheckout, $newCheckout,
            $extraNights, $extensionRack, $extensionCharge, $inheritedPercent,
            $inheritedDiscount, $operator,
        ): Booking {

            // Reserve the new nights — raises on race / collision.
            $this->availability->reserveRangeForBooking(
                $room, $currentCheckout, $newCheckout, $booking->id,
            );

            // ── Reconcile prior Overstay charges ────────────────
            // Any Overstay row on this booking was created by
            // AutoDeductFromWallet or MarkCheckedOut for nights past
            // the ORIGINAL checkout. The extension we're about to
            // book now covers those exact nights, so leaving those
            // charges on the folio double-bills the guest:
            //   * unsettled Overstay -> the guest would owe both
            //     the auto-charge + the extension price for the same
            //     nights. Void the auto-charge outright.
            //   * settled Overstay -> the guest already paid for
            //     those nights out of their wallet. Net the amount
            //     off the extension charge so they don't pay a
            //     second time via cash/card at the counter.
            $existingOverstay = $booking->charges()
                ->where('type', BookingChargeType::Overstay->value)
                ->get();

            $overstayAlreadyPaid = 0;
            $waivedCount = 0;
            $waivedAmount = 0;
            foreach ($existingOverstay as $osc) {
                if ($osc->settled) {
                    $overstayAlreadyPaid += (int) $osc->amount;
                } else {
                    $waivedAmount += (int) $osc->amount;
                    $waivedCount++;
                    $osc->delete();
                }
            }

            // Net the wallet-settled overstay against what the guest
            // owes now. Extension charge is already at the guest's
            // discounted rate (see $extensionCharge above); the "amount
            // owed now" is that discounted figure minus any overstay
            // the guest already paid out of pocket.
            $extensionChargePayable = max(0, $extensionCharge - $overstayAlreadyPaid);

            // Roll the booking's headline dates + totals forward.
            // `room_total` moves with `nights` so the derived per-night
            // rate (`room_total / nights`) stays truthful post-extend —
            // otherwise the in-house table reads a smaller "paid" rate
            // and mislabels the guest as being on a discount they never
            // got. `total_amount` is the grand total for reporting.
            //
            // `balance_due` is deliberately NOT bumped: the extension
            // is captured as a BookingCharge below, and
            // outstandingTotal() sums balance_due + unsettled charges.
            // Adding to both would double-count the extension in
            // outstanding — the exact bug that shipped before this fix.
            $newTotalNights = (int) ($booking->nights ?? 0) + $extraNights;
            $newTotal = (int) ($booking->total_amount ?? 0) + $extensionCharge;
            $newRoomTotal = (int) ($booking->room_total ?? 0) + $extensionCharge;

            // Roll the inherited discount into manual_discount_amount so
            // the booking-level "you saved ₦N" trail keeps summing to
            // the truth. The percent stays put — that's the rate.
            $newDiscountAmount = (int) $booking->manual_discount_amount + $inheritedDiscount;

            $booking->forceFill([
                'checkout_date' => $newCheckout->toDateString(),
                'nights' => $newTotalNights,
                'total_amount' => $newTotal,
                'room_total' => $newRoomTotal,
                'manual_discount_amount' => $newDiscountAmount,
                'balance_due_at' => $booking->balance_due_at ?? $newCheckout,
            ])->save();

            $description = sprintf(
                'Stay extended to %s · %d extra night%s',
                $newCheckout->toFormattedDateString(),
                $extraNights,
                $extraNights === 1 ? '' : 's',
            );
            if ($inheritedDiscount > 0) {
                $description .= sprintf(
                    ' · %s%% off inherited (₦%s off ₦%s rack)',
                    rtrim(rtrim(number_format($inheritedPercent, 2), '0'), '.'),
                    number_format($inheritedDiscount),
                    number_format($extensionRack),
                );
            }
            if ($overstayAlreadyPaid > 0) {
                $description .= sprintf(' · ₦%s credit for overstay already paid', number_format($overstayAlreadyPaid));
            }

            BookingCharge::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'created_by_operator_id' => $operator->getKey(),
                'type' => BookingChargeType::Extension->value,
                'description' => $description,
                'amount' => $extensionChargePayable,
                'settled' => false,
            ]);

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('extended')
                ->withProperties([
                    'extension_rack_value'    => $extensionRack,
                    'inherited_discount_pct'  => $inheritedPercent,
                    'inherited_discount_amt'  => $inheritedDiscount,
                    'extension_full_value'    => $extensionCharge,
                    'extension_charged'       => $extensionChargePayable,
                    'overstay_waived_count'   => $waivedCount,
                    'overstay_waived_amount'  => $waivedAmount,
                    'overstay_already_paid'   => $overstayAlreadyPaid,
                ])
                ->log(sprintf(
                    'Operator %s extended %s by %d night%s to %s (₦%s%s).',
                    $operator->name,
                    $booking->booking_ref,
                    $extraNights,
                    $extraNights === 1 ? '' : 's',
                    $newCheckout->toDateString(),
                    number_format($extensionChargePayable),
                    ($waivedCount > 0 || $overstayAlreadyPaid > 0)
                        ? sprintf(
                            ', %d overstay charge%s waived / ₦%s credited',
                            $waivedCount, $waivedCount === 1 ? '' : 's',
                            number_format($overstayAlreadyPaid),
                        )
                        : '',
                ));

            return $booking->refresh();
        });
    }
}
