<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Payment;
use App\Multitenancy\TenantScope;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Kicks off the Paystack checkout for a freshly-created Booking (PRD §11):
 *
 *  - Picks the right amount (full total vs deposit) from the Booking row.
 *  - Creates a Pending Payment with a stable paystack_reference.
 *  - Calls Paystack's /transaction/initialize on FregateLab's master
 *    account, routed to the operator's Subaccount so Paystack auto-
 *    splits the settlement (operator's bank ← 98%, Keverna ← 2%).
 *
 * Funds never touch a Keverna-controlled bank account; Paystack handles
 * the split at settlement time. Legacy tenants that pre-date Subaccounts
 * (or have explicitly opted into running their own Paystack account)
 * still fall back to `tenants.paystack_secret_key`.
 */
class InitiateBookingPayment
{
    /**
     * @param  string|null  $callbackUrl  Overrides the config default so
     *                                    marketplace / portal flows can
     *                                    land on their own confirmation
     *                                    pages instead of the tenant one.
     * @param  string|null  $referencePrefix  Overrides "kev_book_" so
     *                                    marketplace payments are easy
     *                                    to spot in Paystack + audit logs.
     */
    public function handle(Booking $booking, ?string $callbackUrl = null, ?string $referencePrefix = null): string
    {
        [$client, $subaccountCode] = $this->resolvePaystackClient($booking);

        // Deposit when the booking carries one, otherwise full payment.
        $isDeposit = $booking->deposit_amount > 0;
        $type = $isDeposit ? PaymentType::Deposit : PaymentType::Full;
        $amountNgn = $isDeposit ? $booking->deposit_amount : $booking->total_amount;
        $reference = $this->makeReference($booking, $referencePrefix);

        // Pre-create the Payment row inside a transaction so a Paystack init
        // failure leaves us with a clean Failed row (vs an orphan reference).
        $payment = DB::transaction(function () use ($booking, $reference, $amountNgn, $type) {
            return Payment::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'paystack_reference' => $reference,
                'amount' => $amountNgn,
                'type' => $type,
                'status' => PaymentStatus::Pending,
            ]);
        });

        try {
            $result = $client->initializeTransaction([
                'email' => $booking->guest_email,
                'amount_ngn' => $amountNgn,
                'reference' => $reference,
                'callback_url' => $callbackUrl ?? (string) config('services.paystack.callback_url'),
                'metadata' => [
                    'booking_id' => $booking->id,
                    'tenant_id' => $booking->tenant_id,
                    'payment_id' => $payment->id,
                    'booking_ref' => $booking->booking_ref,
                ],
                'subaccount' => $subaccountCode ?: null,
            ]);
        } catch (ConnectionException $e) {
            // Network-level failure (DNS, TLS handshake, timeout)
            // reaching Paystack. Roll back everything inventory-side
            // so the room is immediately available to the next guest
            // — the user can't pay, so they shouldn't hold a slot.
            $this->abortBooking($booking, $payment);

            Log::warning('Paystack init connection failure', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                "Couldn't reach the payment provider. The room has been released — please try again."
            );
        } catch (RequestException $e) {
            // Paystack accepted our connection but rejected the call.
            // 401 = bad secret key, which is an operator-config issue
            // we want to surface clearly so support doesn't get a
            // generic "something went wrong" ticket.
            $this->abortBooking($booking, $payment);

            $status = $e->response?->status();
            Log::warning('Paystack init request failure', [
                'booking_ref' => $booking->booking_ref,
                'status' => $status,
                'body' => $e->response?->body(),
            ]);

            if ($status === 401) {
                throw new RuntimeException(
                    "Payments aren't set up correctly for this property yet. "
                    .'Please ask the operator to verify their Paystack secret key. '
                    .'The room has been released.'
                );
            }

            throw new RuntimeException(
                'The payment provider rejected the request. The room has been released — please try again.'
            );
        } catch (Throwable $e) {
            // Any other Paystack failure (malformed response, etc.) —
            // same treatment, the booking can't be paid for so we
            // mustn't hold inventory.
            $this->abortBooking($booking, $payment);

            Log::warning('Paystack init failure', [
                'booking_ref' => $booking->booking_ref,
                'error' => $e->getMessage(),
                'type' => $e::class,
            ]);

            throw $e;
        }

        return $result['authorization_url'];
    }

    /**
     * Inventory-safe rollback after a payment-init failure.
     *
     * Deletes the Booked AvailabilityBlock rows so the room frees up
     * immediately + flips the booking to Cancelled (with a meta
     * marker so analytics can see *why*). Stays within one DB
     * transaction so a partial cleanup is impossible — either the
     * room is fully released or nothing changes.
     *
     * No `BookingCancelled` event is dispatched: the guest never
     * completed payment, so sending them a "your booking was
     * cancelled" email would just confuse them. The RuntimeException
     * thrown by the caller surfaces a friendly message on the
     * booking form instead.
     */
    private function abortBooking(Booking $booking, Payment $payment): void
    {
        DB::transaction(function () use ($booking, $payment): void {
            $payment->forceFill(['status' => PaymentStatus::Failed])->save();

            AvailabilityBlock::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('booking_id', $booking->id)
                ->where('type', AvailabilityBlockType::Booked->value)
                ->delete();

            $booking->forceFill(['booking_status' => BookingStatus::Cancelled])->save();
        });

        activity('booking')
            ->performedOn($booking)
            ->event('payment_init_failed')
            ->log("Booking {$booking->booking_ref} cancelled — payment provider unreachable.");
    }

    private function makeReference(Booking $booking, ?string $prefix = null): string
    {
        return ($prefix ?? 'kev_book_').$booking->id.'_'.Str::lower(Str::random(16));
    }

    /**
     * Picks which Paystack credentials and routing to use for this
     * booking. Resolution order:
     *
     *   1. Master key + operator subaccount code — the preferred flow.
     *      Paystack splits the settlement so the operator's cut lands
     *      in their bank automatically.
     *
     *   2. Operator's own Paystack secret key — legacy pre-Subaccounts
     *      flow. Full amount settles to the operator, Keverna invoices
     *      the platform fee separately.
     *
     *   3. Master key with NO subaccount — platform fallback. Full amount
     *      settles to Keverna's own Paystack account; the operator gets
     *      paid out off-platform (manual bank transfer, reconciled
     *      against the booking ledger). Used when a tenant hasn't
     *      finished payout setup yet — better to accept the booking and
     *      settle later than to break the guest checkout.
     *
     * Returns [PaystackClient, ?string $subaccountCode].
     *
     * @return array{0: PaystackClient, 1: ?string}
     */
    private function resolvePaystackClient(Booking $booking): array
    {
        $tenant = $booking->tenant;

        $subaccountCode = (string) ($tenant->paystack_subaccount_code ?? '');
        if ($subaccountCode !== '') {
            return [PaystackClient::forPlatform(), $subaccountCode];
        }

        $tenantKey = (string) ($tenant->paystack_secret_key ?? '');
        if ($tenantKey !== '') {
            return [PaystackClient::forTenantKey($tenantKey), null];
        }

        // Platform fallback — log so ops can reconcile payouts to the
        // operator later. Booking still succeeds; guest is never blocked
        // from paying because of tenant setup gaps.
        activity('booking')
            ->performedOn($booking)
            ->event('paystack_platform_fallback')
            ->withProperties([
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'booking_ref' => $booking->booking_ref,
                'amount' => $booking->total_amount,
            ])
            ->log(
                "Booking {$booking->booking_ref} used the platform Paystack account "
                ."(tenant {$tenant->slug} has no subaccount / secret key). Manual "
                .'payout to the operator required.'
            );

        return [PaystackClient::forPlatform(), null];
    }
}
