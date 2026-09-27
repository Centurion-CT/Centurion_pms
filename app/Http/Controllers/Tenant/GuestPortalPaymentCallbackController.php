<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\GuestPortal\ResolvePortalPaymentIntent;
use App\Http\Controllers\Controller;
use App\Models\GuestPortalIntent;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Paystack redirects the guest here after a card-payment attempt
 * from the in-room portal. We verify the transaction server-side
 * (belt-and-braces vs the webhook), resolve the intent (extend /
 * order / reserve), then bounce the guest back to the room portal
 * with a status flash.
 *
 * The Paystack webhook fires the same resolver as a safety net —
 * both paths are idempotent so a race doesn't double-run the
 * follow-up action.
 */
class GuestPortalPaymentCallbackController extends Controller
{
    public function __invoke(Request $request, ResolvePortalPaymentIntent $resolver): RedirectResponse
    {
        $reference = (string) $request->query('reference', '');
        if ($reference === '') {
            return $this->redirectToPortalHome(null, 'Payment reference missing.');
        }

        $tenant = Tenant::current();
        $payment = Payment::query()
            ->where('paystack_reference', $reference)
            ->where('tenant_id', $tenant?->getKey())
            ->first();
        if ($payment === null) {
            return $this->redirectToPortalHome(null, 'Payment not found.');
        }

        $intent = GuestPortalIntent::query()
            ->where('payment_id', $payment->id)
            ->first();
        if ($intent === null) {
            return $this->redirectToPortalHome(null, 'Payment intent not found.');
        }

        // Server-side verify vs Paystack — the webhook can lag or fail;
        // this makes the callback path authoritative when the guest is
        // waiting in front of the screen.
        try {
            $client = $this->clientForBooking($intent);
            $verified = $client->verifyTransaction($reference);
        } catch (\Throwable $e) {
            report($e);
            return $this->redirectToPortalHome($intent, "Couldn't verify with Paystack right now — try again in a moment or ask the front desk.");
        }

        if (($verified['status'] ?? '') !== 'success') {
            return $this->redirectToPortalHome($intent, 'Your card payment did not succeed. You have not been charged.');
        }

        $paidAt = isset($verified['paid_at']) ? Carbon::parse((string) $verified['paid_at']) : Carbon::now();
        $intent = $resolver->handle($intent, $paidAt);

        $message = $intent->status === GuestPortalIntent::STATUS_COMPLETED
            ? 'Payment received — your request is confirmed.'
            : ($intent->error_message ?? 'Payment received but the follow-up failed. Please ask the front desk.');

        return $this->redirectToPortalHome($intent, $message);
    }

    private function clientForBooking(GuestPortalIntent $intent): PaystackClient
    {
        $booking = $intent->booking;
        $tenant = $booking?->tenant;
        $tenantKey = (string) ($tenant?->paystack_secret_key ?? '');
        if ($tenantKey !== '') {
            return PaystackClient::forTenantKey($tenantKey);
        }
        return PaystackClient::forPlatform();
    }

    private function redirectToPortalHome(?GuestPortalIntent $intent, string $message): RedirectResponse
    {
        $room = $intent?->booking?->room;
        if ($room === null) {
            // No context — bounce to the tenant landing.
            return redirect('/')->with('portal_flash', $message);
        }
        session()->flash('portal_flash', $message);
        return redirect()->route('tenant.guest.portal', ['qrSlug' => $room->qr_slug]);
    }
}
