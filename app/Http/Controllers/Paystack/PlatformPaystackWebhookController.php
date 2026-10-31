<?php

declare(strict_types=1);

namespace App\Http\Controllers\Paystack;

use App\Http\Controllers\Controller;
use App\Models\Corporate;
use App\Models\CorporateWalletTopup;
use App\Models\Payment;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webhook address set on the platform's Paystack account:
 * https://{CENTRAL_DOMAIN}/paystack/webhook (docs/MULTITENANCY.md, step 5).
 *
 * Payments through the platform account (a business's subaccount, or a
 * business with no payout setup yet) all notify this one address,
 * whichever business they belong to. It checks the signature with the
 * platform key, finds the business by the payment's reference, binds
 * it, and hands the request to that business's own webhook handler:
 * corporate top-ups (`KCT-`) or booking payments. A business with its
 * own Paystack account sets its own webhook address instead.
 *
 * Unknown references answer 200 so Paystack stops retrying events that
 * aren't ours to handle.
 */
final class PlatformPaystackWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($signature === '' || ! PaystackClient::forPlatform()->verifyWebhookSignature($rawBody, $signature)) {
            return response()->json(['ok' => false, 'reason' => 'invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        $reference = is_array($payload) ? (string) ($payload['data']['reference'] ?? '') : '';
        $isTopup = str_starts_with($reference, 'KCT-');
        $tenantId = $isTopup ? $this->topupBusiness($reference) : $this->paymentBusiness($reference);

        $tenant = $tenantId === null ? null : Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return response()->json(['ok' => true, 'ignored' => 'unknown reference']);
        }

        $tenant->makeCurrent();
        try {
            $handler = $isTopup ? CorporateWalletTopupWebhookController::class : BookingWebhookController::class;

            return app()->call([app($handler), '__invoke'], ['request' => $request]);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function topupBusiness(string $reference): ?int
    {
        $corporateId = CorporateWalletTopup::query()->where('reference', $reference)->value('corporate_id');

        return $corporateId === null ? null : Corporate::query()->withoutGlobalScopes()->whereKey($corporateId)->value('tenant_id');
    }

    private function paymentBusiness(string $reference): ?int
    {
        return $reference === '' ? null : Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('paystack_reference', $reference)
            ->value('tenant_id');
    }
}
