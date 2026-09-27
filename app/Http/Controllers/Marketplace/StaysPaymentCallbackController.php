<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Actions\Bookings\ConfirmBookingPayment;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paystack's post-payment callback for Keverna Stays marketplace
 * bookings. Distinct from the per-tenant BookingWebhookController
 * because marketplace flows run on the central domain (no tenant in
 * the URL) and always use FregateLab's master account with the
 * property's Paystack Subaccount.
 *
 * Flow:
 *   1. Grab ?reference=<kev_stays_...> from the callback
 *   2. Cross-tenant lookup of the Payment (bypasses TenantScope)
 *   3. Switch Tenant::current() to that Payment's tenant so
 *      Booking + downstream events see the correct scope
 *   4. Verify the transaction server-side with the master key
 *   5. Confirm the booking + fire the BookingConfirmed pipeline
 *      (email + WhatsApp handled by existing listeners)
 *   6. Redirect the guest to /stays/pay/success/{booking_ref}
 *      or /stays/pay/failed on any hard failure
 *
 * Always restores whatever tenant context (usually none) was live
 * before the callback ran so downstream middleware doesn't observe
 * a leaked tenant.
 */
final class StaysPaymentCallbackController
{
    public function __invoke(Request $request, ConfirmBookingPayment $confirm): RedirectResponse
    {
        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? '');
        if ($reference === '') {
            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'missing-reference']);
        }

        $payment = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('paystack_reference', $reference)
            ->first();
        if ($payment === null) {
            Log::warning('Stays callback: unknown reference', ['reference' => $reference]);
            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'unknown-reference']);
        }

        $tenant = Tenant::query()->withoutGlobalScope(TenantScope::class)->find($payment->tenant_id);
        if ($tenant === null) {
            Log::error('Stays callback: payment.tenant_id resolves nowhere', [
                'reference' => $reference,
                'payment_id' => $payment->id,
            ]);
            return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'tenant-missing']);
        }

        $previous = Tenant::current();
        $tenant->makeCurrent();

        try {
            // Idempotency: if a webhook beat us to it, jump straight
            // to the success page instead of re-verifying. Booking is
            // now visible in-scope since we just made the tenant
            // current, so a plain BelongsToTenant query works.
            if ($payment->status === PaymentStatus::Succeeded) {
                $ref = Booking::query()->where('id', $payment->booking_id)->value('booking_ref');
                return $ref !== null
                    ? redirect()->route('marketplace.stays.pay.success', ['booking_ref' => $ref])
                    : redirect()->route('marketplace.stays.pay.failed', ['reason' => 'booking-missing']);
            }

            try {
                $verified = PaystackClient::forPlatform()->verifyTransaction($reference);
            } catch (Throwable $e) {
                Log::warning('Stays callback: Paystack verify failed', [
                    'reference' => $reference, 'error' => $e->getMessage(),
                ]);
                return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'verify-error']);
            }

            if (($verified['status'] ?? '') !== 'success') {
                Log::info('Stays callback: transaction not success', [
                    'reference' => $reference, 'status' => $verified['status'] ?? 'unknown',
                ]);
                return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'not-success']);
            }

            if ((int) $verified['amount_ngn'] !== (int) $payment->amount) {
                Log::error('Stays callback: amount mismatch', [
                    'reference' => $reference,
                    'expected'  => $payment->amount,
                    'observed'  => $verified['amount_ngn'],
                ]);
                return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'amount-mismatch']);
            }

            $paidAt = isset($verified['paid_at']) ? Carbon::parse((string) $verified['paid_at']) : Carbon::now();
            $confirm->handle($payment->refresh(), $paidAt);

            $bookingRef = Booking::query()->where('id', $payment->booking_id)->value('booking_ref');
            if ($bookingRef === null) {
                return redirect()->route('marketplace.stays.pay.failed', ['reason' => 'booking-missing']);
            }

            return redirect()->route('marketplace.stays.pay.success', ['booking_ref' => $bookingRef]);
        } finally {
            Tenant::forgetCurrent();
            $previous?->makeCurrent();
        }
    }
}
