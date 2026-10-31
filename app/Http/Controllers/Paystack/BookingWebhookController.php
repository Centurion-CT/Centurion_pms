<?php

declare(strict_types=1);

namespace App\Http\Controllers\Paystack;

use App\Actions\Bookings\ConfirmBookingPayment;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Paystack\BusinessPaystack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Booking-payment webhook (PRD §11). Receives Paystack `charge.success`
 * events for guest bookings on this tenant. The signing key depends on
 * which payment routing the tenant uses:
 *
 *  - Subaccount mode (default): all transactions are initialised on
 *    FregateLab's master account, so events are signed with the MASTER
 *    secret key. Paystack's dashboard webhook URL points here per
 *    tenant subdomain.
 *  - Legacy operator-key mode: the operator runs their own Paystack
 *    account and registers their own webhook URL pointing here;
 *    events are signed with `tenants.paystack_secret_key`.
 *
 * The tenant is bound by ResolveTenantFromRequest middleware before this
 * controller runs, so we pick the right key off Tenant::current() based
 * on which routing the tenant has configured.
 *
 * Bad signature -> 401 (Paystack retries). Unknown reference -> 422.
 * Amount mismatch -> 422 (signals operator tampered with the link).
 * Idempotency on `paystack_reference` ensures retries are inert.
 */
class BookingWebhookController extends Controller
{
    public function __invoke(Request $request, ConfirmBookingPayment $confirm): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        // Same account the payment started on (BusinessPaystack): the
        // platform key for a subaccount or no payout setup yet, else the
        // business's own key.
        $client = BusinessPaystack::client($tenant);

        $rawBody = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($signature === '' || ! $client->verifyWebhookSignature($rawBody, $signature)) {
            return response()->json(['ok' => false, 'reason' => 'invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! isset($payload['event'], $payload['data'])) {
            return response()->json(['ok' => false, 'reason' => 'malformed payload'], 422);
        }

        $event = (string) $payload['event'];
        $data = (array) $payload['data'];

        if ($event !== 'charge.success') {
            return response()->json(['ok' => true, 'ignored' => $event]);
        }

        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return response()->json(['ok' => false, 'reason' => 'missing reference'], 422);
        }

        // Payment is tenant-scoped via BelongsToTenant; current tenant matches
        // so the regular query is enough — no need to lift the scope.
        $payment = Payment::query()->where('paystack_reference', $reference)->first();
        if ($payment === null) {
            Log::warning('Paystack booking webhook: unknown reference', [
                'reference' => $reference, 'tenant_id' => $tenant->id,
            ]);

            return response()->json(['ok' => false, 'reason' => 'unknown reference'], 422);
        }

        $expectedKobo = $payment->amount * 100;
        $observedKobo = (int) ($data['amount'] ?? 0);
        if ($observedKobo !== $expectedKobo) {
            Log::error('Paystack booking webhook: amount mismatch', [
                'reference' => $reference, 'expected_kobo' => $expectedKobo, 'observed_kobo' => $observedKobo,
            ]);

            return response()->json(['ok' => false, 'reason' => 'amount mismatch'], 422);
        }

        if ($payment->status === PaymentStatus::Succeeded) {
            return response()->json(['ok' => true, 'idempotent' => true]);
        }

        $paidAt = isset($data['paid_at']) ? Carbon::parse((string) $data['paid_at']) : Carbon::now();
        $confirmed = $confirm->handle($payment, $paidAt);

        return response()->json(['ok' => true, 'confirmed' => $confirmed]);
    }
}
