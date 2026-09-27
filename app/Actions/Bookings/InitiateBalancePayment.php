<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Kicks off the Paystack checkout for the OUTSTANDING balance on an OnHold
 * booking (PRD §6.4.3). Mirrors {@see InitiateBookingPayment} but charges
 * `balance_due` (not `total_amount`) and stamps `type=balance` on the row.
 *
 * The webhook handler ({@see ConfirmBookingPayment}) sees the Balance type
 * and flips Booking back to Confirmed + clears `balance_due` to zero — the
 * same code path that handles a Full payment success.
 */
class InitiateBalancePayment
{
    public function handle(Booking $booking): string
    {
        if ($booking->booking_status !== BookingStatus::OnHold) {
            throw new RuntimeException('Balance can only be paid on OnHold bookings.');
        }
        if ($booking->balance_due <= 0) {
            throw new RuntimeException('Booking has no outstanding balance.');
        }

        $tenant = $booking->tenant;
        $subaccountCode = (string) ($tenant->paystack_subaccount_code ?? '');
        $tenantKey = (string) ($tenant->paystack_secret_key ?? '');

        if ($subaccountCode !== '') {
            $client = PaystackClient::forPlatform();
        } elseif ($tenantKey !== '') {
            $client = PaystackClient::forTenantKey($tenantKey);
        } else {
            throw new RuntimeException(
                'Operator has not set up payouts — cannot accept online payments.',
            );
        }

        $reference = 'kev_bal_'.$booking->id.'_'.Str::lower(Str::random(16));

        $payment = DB::transaction(fn () => Payment::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'paystack_reference' => $reference,
            'amount' => $booking->balance_due,
            'type' => PaymentType::Balance,
            'status' => PaymentStatus::Pending,
        ]));

        try {
            $result = $client->initializeTransaction([
                'email' => $booking->guest_email,
                'amount_ngn' => $booking->balance_due,
                'reference' => $reference,
                'callback_url' => (string) config('services.paystack.callback_url'),
                'metadata' => [
                    'booking_id' => $booking->id,
                    'tenant_id' => $booking->tenant_id,
                    'payment_id' => $payment->id,
                    'booking_ref' => $booking->booking_ref,
                    'payment_type' => 'balance',
                ],
                'subaccount' => $subaccountCode ?: null,
            ]);
        } catch (Throwable $e) {
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();
            throw $e;
        }

        return $result['authorization_url'];
    }
}
