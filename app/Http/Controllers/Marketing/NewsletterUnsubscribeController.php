<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Models\NewsletterSubscription;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * One-click unsubscribe from the newsletter footer. Token is a
 * per-subscription random string persisted at signup, so no
 * signing key is needed and the URL survives config rotation.
 */
class NewsletterUnsubscribeController
{
    public function __invoke(string $token): Response
    {
        $sub = NewsletterSubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if ($sub === null) {
            return response()->view('emails.marketing.newsletter-unsubscribe', [
                'ok' => false, 'email' => null,
            ], 404);
        }

        if ($sub->status !== 'unsubscribed') {
            $sub->forceFill([
                'status' => 'unsubscribed',
                'unsubscribed_at' => Carbon::now(),
            ])->save();

            activity('newsletter_subscription')
                ->on($sub)
                ->log("Unsubscribed {$sub->email}.");
        }

        return response()->view('emails.marketing.newsletter-unsubscribe', [
            'ok' => true, 'email' => $sub->email,
        ]);
    }
}
