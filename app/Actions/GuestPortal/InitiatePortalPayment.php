<?php

declare(strict_types=1);

namespace App\Actions\GuestPortal;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\GuestPortalIntent;
use App\Models\Payment;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kicks off a Paystack transaction for a guest-portal-initiated
 * charge (extend stay, room-service order, reserve another room).
 * Returns the authorization_url to redirect the guest to.
 *
 * Flow:
 *   1. Create Payment (Pending) tied to the booking.
 *   2. Create GuestPortalIntent (Pending) that carries the "what
 *      to do on success" payload — the callback / webhook resolves
 *      this after Paystack confirms.
 *   3. Initialise Paystack with the operator's subaccount so
 *      settlements auto-split (same pattern as InitiateBookingPayment).
 *
 * The webhook handler / callback URL uses the reference to find
 * both the Payment AND the Intent, marks Payment Succeeded, and
 * runs {@see ResolvePortalPaymentIntent} to fire the follow-up
 * action.
 */
class InitiatePortalPayment
{
    /**
     * @param  array<string,mixed>  $payload  Purpose-specific fields
     *                                        stored on the intent for
     *                                        the resolver to read.
     * @return array{authorization_url: string, reference: string, intent: GuestPortalIntent}
     */
    public function handle(
        Booking $booking,
        int $amountNgn,
        string $purpose,
        array $payload,
    ): array {
        if (! in_array($purpose, [
            GuestPortalIntent::PURPOSE_EXTEND,
            GuestPortalIntent::PURPOSE_ORDER,
            GuestPortalIntent::PURPOSE_RESERVE,
            GuestPortalIntent::PURPOSE_BALANCE,
        ], true)) {
            throw new \InvalidArgumentException("Unknown portal payment purpose: {$purpose}");
        }
        if ($amountNgn <= 0) {
            throw new \InvalidArgumentException('Portal payment amount must be positive.');
        }

        $reference = 'gp-'.$purpose.'-'.Str::ulid()->toBase32();

        [$client, $subaccountCode] = $this->resolvePaystackClient($booking);

        [$payment, $intent] = DB::transaction(function () use ($booking, $reference, $amountNgn, $purpose, $payload) {
            $payment = Payment::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'paystack_reference' => $reference,
                'amount' => $amountNgn,
                'type' => PaymentType::Extra,
                'method' => 'card',
                'reference_note' => 'Guest portal · '.$purpose,
                'status' => PaymentStatus::Pending,
            ]);
            $intent = GuestPortalIntent::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'payment_id' => $payment->id,
                'purpose' => $purpose,
                'status' => GuestPortalIntent::STATUS_PENDING,
                'payload' => $payload,
            ]);
            return [$payment, $intent];
        });

        $result = $client->initializeTransaction([
            'email' => $booking->guest_email ?: 'guest@keverna.test',
            'amount_ngn' => $amountNgn,
            'reference' => $reference,
            'callback_url' => route('tenant.guest.portal.pay.callback'),
            'metadata' => [
                'booking_id' => $booking->id,
                'tenant_id' => $booking->tenant_id,
                'payment_id' => $payment->id,
                'intent_id' => $intent->id,
                'purpose' => $purpose,
                'source' => 'guest_portal',
            ],
            'subaccount' => $subaccountCode ?: null,
        ]);

        return [
            'authorization_url' => $result['authorization_url'],
            'reference' => $reference,
            'intent' => $intent,
        ];
    }

    /**
     * Same subaccount / master-key resolution as
     * {@see \App\Actions\Bookings\InitiateBookingPayment}. Guest-
     * portal charges settle to the same operator bank account as
     * a regular booking payment.
     *
     * @return array{0: PaystackClient, 1: ?string}
     */
    private function resolvePaystackClient(Booking $booking): array
    {
        $tenant = $booking->tenant;
        $subaccountCode = (string) ($tenant?->paystack_subaccount_code ?? '');
        if ($subaccountCode !== '') {
            return [PaystackClient::forPlatform(), $subaccountCode];
        }

        $tenantKey = (string) ($tenant?->paystack_secret_key ?? '');
        if ($tenantKey !== '') {
            return [PaystackClient::forTenantKey($tenantKey), null];
        }

        return [PaystackClient::forPlatform(), null];
    }
}
