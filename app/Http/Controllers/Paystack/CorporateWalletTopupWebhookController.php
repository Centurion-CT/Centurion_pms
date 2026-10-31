<?php

declare(strict_types=1);

namespace App\Http\Controllers\Paystack;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Corporates\TopupService;
use App\Services\Paystack\BusinessPaystack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Receives wallet-top-up webhooks on the business's address, signed
 * with the Paystack account its payments go through (BusinessPaystack).
 * Top-ups through the platform account arrive via
 * PlatformPaystackWebhookController, which hands them on to here.
 *
 * We dispatch by reference prefix — only references starting `KCT-`
 * (Keverna Corporate Top-up) are handled here. Other prefixes that
 * arrive at this endpoint are 422'd so a misconfigured webhook
 * doesn't silently swallow subscription payments.
 *
 * Idempotent: TopupService::finaliseFromReference is a no-op when the
 * row is already Succeeded.
 */
class CorporateWalletTopupWebhookController extends Controller
{
    public function __invoke(Request $request, TopupService $topups): JsonResponse
    {
        $client = BusinessPaystack::client(Tenant::current());
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
        if ($reference === '' || ! str_starts_with($reference, 'KCT-')) {
            return response()->json(['ok' => false, 'reason' => 'unknown reference prefix'], 422);
        }

        try {
            $paidAt = isset($data['paid_at']) ? Carbon::parse((string) $data['paid_at']) : Carbon::now();
            $credited = $topups->finaliseFromReference($reference, $paidAt);
        } catch (\Throwable $e) {
            Log::error('Corporate top-up webhook failure', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'reason' => 'finalise failure'], 500);
        }

        return response()->json(['ok' => true, 'credited' => $credited]);
    }
}
