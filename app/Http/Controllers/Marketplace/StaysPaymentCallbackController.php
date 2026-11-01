<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Actions\Bookings\ConfirmBookingPayment;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Paystack\BusinessPaystack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where Paystack sends a guest back after paying for a booking, on the
 * address of the business they booked with (docs/MULTITENANCY.md,
 * step 5). Every booking payment returns here: new bookings from the
 * booking site and the stays checkout, and balances paid later.
 *
 * Flow:
 *   1. Grab ?reference= from the callback
 *   2. Find the Payment among this business's payments only
 *   3. Verify the transaction with the Paystack account the business's
 *      payments go through (BusinessPaystack, same as when it started)
 *   4. Confirm the booking + fire the BookingConfirmed pipeline
 *      (email + WhatsApp handled by existing listeners)
 *   5. Redirect the guest to /stays/pay/success/{booking_ref}
 *      or /stays/pay/failed on any hard failure
 *
 * The webhook (BookingWebhookController) confirms the same payment
 * independently; whichever lands first wins and the other is a no-op.
 */
final class StaysPaymentCallbackController
{
    public function __invoke(Request $request, ConfirmBookingPayment $confirm): RedirectResponse
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? '');
        if ($reference === '') {
            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'missing-reference']);
        }

        $payment = Payment::query()->where('paystack_reference', $reference)->first();
        if ($payment === null) {
            Log::warning('Payment return: unknown reference for this business', [
                'reference' => $reference, 'tenant_id' => $tenant->id,
            ]);

            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'unknown-reference']);
        }

        // Idempotency: if the webhook beat us to it, jump straight to the
        // success page instead of re-verifying.
        if ($payment->status === PaymentStatus::Succeeded) {
            return $this->toSuccess($payment);
        }

        try {
            $verified = BusinessPaystack::client($tenant)->verifyTransaction($reference);
        } catch (Throwable $e) {
            Log::warning('Payment return: Paystack verify failed', [
                'reference' => $reference, 'error' => $e->getMessage(),
            ]);

            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'verify-error']);
        }

        if (($verified['status'] ?? '') !== 'success') {
            Log::info('Payment return: transaction not success', [
                'reference' => $reference, 'status' => $verified['status'] ?? 'unknown',
            ]);

            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'not-success']);
        }

        if ((int) $verified['amount_ngn'] !== (int) $payment->amount) {
            Log::error('Payment return: amount mismatch', [
                'reference' => $reference,
                'expected' => $payment->amount,
                'observed' => $verified['amount_ngn'],
            ]);

            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'amount-mismatch']);
        }

        $paidAt = isset($verified['paid_at']) ? Carbon::parse((string) $verified['paid_at']) : Carbon::now();
        $confirm->handle($payment->refresh(), $paidAt);

        return $this->toSuccess($payment);
    }

    private function toSuccess(Payment $payment): RedirectResponse
    {
        $ref = Booking::query()->where('id', $payment->booking_id)->value('booking_ref');

        return $ref !== null
            ? redirect()->route('marketplace.stays.pay.success', ['booking_ref' => $ref])
            : redirect()->route('marketplace.stays.pay.failed', ['reason' => 'booking-missing']);
    }
}
