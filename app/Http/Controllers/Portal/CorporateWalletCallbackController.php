<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CorporateWalletTopup;
use App\Services\Corporates\TopupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Paystack callback URL after the corporate admin completes (or
 * abandons) a wallet top-up checkout. Belt-and-braces verifies the
 * transaction immediately — the webhook is the authoritative path,
 * but webhooks can lag, so we re-verify here so the admin sees an
 * accurate status the moment they land back in the portal.
 *
 * Always redirects to /corporates/wallet with a flash so the admin
 * lands on a familiar page rather than dead-ending on a JSON blob.
 */
class CorporateWalletCallbackController extends Controller
{
    public function __invoke(Request $request, TopupService $topups): RedirectResponse
    {
        $reference = (string) $request->query('reference', '');

        if ($reference === '' || ! str_starts_with($reference, 'KCT-')) {
            session()->flash('error', 'Top-up reference not recognised.');

            return redirect()->route('corporate.wallet');
        }

        /** @var CorporateWalletTopup|null $topup */
        $topup = CorporateWalletTopup::query()->where('reference', $reference)->first();

        // Defensive: the callback URL doesn't auth the admin (Paystack
        // doesn't carry the session cookie back). The webhook handles
        // the actual credit. Here we just sync the row's status so the
        // portal page shows the right thing when the admin returns.
        if ($topup !== null) {
            try {
                $topups->finaliseFromReference($reference);
            } catch (\Throwable) {
                // swallow — the webhook will catch up
            }

            $topup->refresh();
            if ($topup->isSucceeded()) {
                session()->flash('status', 'Top-up of ₦'.number_format($topup->amount_naira).' confirmed.');
            } else {
                session()->flash('error', 'Top-up not yet confirmed by Paystack — refresh in a moment.');
            }
        }

        return redirect()->route('corporate.wallet');
    }
}
