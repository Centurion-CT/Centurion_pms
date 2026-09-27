<?php

declare(strict_types=1);

namespace App\Http\Controllers\Paystack;

use App\Actions\Subscriptions\ActivateSubscription;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Multitenancy\TenantScope;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Receives subscription-payment webhooks signed with FregateLab's master
 * Paystack key (PRD §3 / §10). Anything we can't verify or recognise gets a
 * 401/422 and is dropped — Paystack will retry, giving us time to fix it.
 *
 * Subscription rows are looked up by `paystack_reference`, which is unique
 * and pre-generated at checkout init. The handler is idempotent so retries
 * are safe.
 */
class SubscriptionWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ActivateSubscription $activate,
    ): JsonResponse {
        $client = PaystackClient::forSubscriptions();
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

        // We only act on charge.success. All other events ack 200 so Paystack stops retrying.
        if ($event !== 'charge.success') {
            return response()->json(['ok' => true, 'ignored' => $event]);
        }

        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return response()->json(['ok' => false, 'reason' => 'missing reference'], 422);
        }

        // Subscriptions live across tenants; the webhook is unauthenticated so
        // we lift the tenant scope to find the row by reference.
        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('paystack_reference', $reference)
            ->first();

        if ($subscription === null) {
            Log::warning('Paystack subscription webhook: unknown reference', ['reference' => $reference]);

            return response()->json(['ok' => false, 'reason' => 'unknown reference'], 422);
        }

        $expectedAmountKobo = $subscription->amount * 100;
        $observedAmountKobo = (int) ($data['amount'] ?? 0);
        if ($observedAmountKobo !== $expectedAmountKobo) {
            Log::error('Paystack subscription webhook: amount mismatch', [
                'reference' => $reference,
                'expected_kobo' => $expectedAmountKobo,
                'observed_kobo' => $observedAmountKobo,
            ]);

            return response()->json(['ok' => false, 'reason' => 'amount mismatch'], 422);
        }

        // Idempotency: ActivateSubscription returns false if the row is already
        // active. We still ack 200 so Paystack stops retrying.
        if ($subscription->status === SubscriptionStatus::Active) {
            return response()->json(['ok' => true, 'idempotent' => true]);
        }

        $paidAt = isset($data['paid_at']) ? Carbon::parse((string) $data['paid_at']) : Carbon::now();
        $activated = $activate->handle($subscription, $paidAt);

        return response()->json(['ok' => true, 'activated' => $activated]);
    }
}
