<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Events\BookingConfirmed;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Flips a Payment + its Booking into the final state after a verified
 * Paystack charge.success event (PRD §11). Idempotent: a second webhook
 * delivery for the same reference returns false and changes nothing,
 * so downstream notifications don't double-fire.
 *
 * Outcome map:
 *   PaymentType=Full + succeeded    -> Booking.Confirmed
 *   PaymentType=Deposit + succeeded -> Booking.OnHold  (balance_due > 0)
 *   PaymentType=Balance + succeeded -> Booking.Confirmed (clears balance_due)
 *
 * Fires BookingConfirmed event AFTER the transaction commits so listener
 * mailables see the saved row.
 */
class ConfirmBookingPayment
{
    public function handle(Payment $payment, ?Carbon $paidAt = null): bool
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            return false;
        }

        $booking = null;
        $siblings = collect();

        DB::transaction(function () use ($payment, $paidAt, &$booking, &$siblings): void {
            $now = $paidAt ?? Carbon::now();

            $payment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'paid_at' => $now,
            ])->save();

            $booking = $payment->booking()->first();
            if ($booking === null) {
                return;
            }

            $newStatus = match ($payment->type) {
                PaymentType::Full, PaymentType::Balance => BookingStatus::Confirmed,
                PaymentType::Deposit => BookingStatus::OnHold,
            };

            $updates = [
                'booking_status' => $newStatus,
                'payment_status' => PaymentStatus::Succeeded,
            ];

            // Balance payment clears the outstanding amount entirely.
            if ($payment->type === PaymentType::Balance) {
                $updates['balance_due'] = 0;
            }

            $booking->forceFill($updates)->save();

            // Cart fanout - when the Payment carries a stays_cart_ref,
            // this single Paystack success confirms every sibling
            // booking created in the same marketplace checkout. Each
            // sibling gets the same booking_status + payment_status
            // as the primary. Kept inside the transaction so a
            // partial fanout is impossible.
            $cartRef = (string) ($payment->stays_cart_ref ?? '');
            if ($cartRef !== '') {
                $siblings = \App\Models\Booking::query()
                    ->where('stays_cart_ref', $cartRef)
                    ->where('id', '!=', $booking->id)
                    ->get();
                foreach ($siblings as $sib) {
                    $sibUpdates = [
                        'booking_status' => $newStatus,
                        'payment_status' => PaymentStatus::Succeeded,
                    ];
                    if ($payment->type === PaymentType::Balance) {
                        $sibUpdates['balance_due'] = 0;
                    }
                    $sib->forceFill($sibUpdates)->save();
                }
            }
        });

        if ($booking !== null) {
            BookingConfirmed::dispatch($booking->refresh());
            foreach ($siblings as $sib) {
                BookingConfirmed::dispatch($sib->refresh());
            }
        }

        return true;
    }
}
