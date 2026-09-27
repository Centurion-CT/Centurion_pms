<?php

declare(strict_types=1);

namespace App\Livewire\Marketing;

use App\Mail\Marketing\NewsletterWelcomeMail;
use App\Models\NewsletterSubscription;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Inline newsletter form the landing page mounts where the static
 * <form> used to sit. Idempotent on repeat submissions - the same
 * email flips a re-subscribe back to active but only fires the
 * welcome mail once.
 */
class NewsletterSubscribe extends Component
{
    public string $email = '';

    /** Honeypot - bots love filling every field. */
    public string $company_website = '';

    /** Attribution for the admin queue. Passed in from the parent
     *  when mounting via <livewire:marketing.newsletter-subscribe source="footer" />. */
    public string $source = 'landing';

    public bool $submitted = false;

    public function subscribe(): void
    {
        if ($this->company_website !== '') {
            $this->submitted = true;
            return;
        }

        $this->validate([
            'email' => ['required', 'email', 'max:180'],
        ]);

        // Rate-limit repeat hits per IP so a fetch loop can't hammer
        // us. 5/minute is generous for a real human.
        $key = 'newsletter:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in a minute.');
            return;
        }
        RateLimiter::hit($key, 60);

        $email = strtolower(trim($this->email));
        $existing = NewsletterSubscription::query()->where('email', $email)->first();
        $isFirstTime = $existing === null;

        if ($existing !== null) {
            // Re-subscribe path: flip status back to active, keep the
            // original subscribed_at. No welcome mail on re-sub - the
            // subscriber already saw it.
            if ($existing->status !== 'active') {
                $existing->forceFill([
                    'status' => 'active',
                    'unsubscribed_at' => null,
                ])->save();

                activity('newsletter_subscription')
                    ->on($existing)
                    ->log("Resubscribed {$existing->email}.");
            }
            $subscription = $existing;
        } else {
            $subscription = NewsletterSubscription::query()->create([
                'email' => $email,
                'status' => 'active',
                'source' => $this->source,
                'unsubscribe_token' => NewsletterSubscription::makeToken(),
                'subscribed_at' => Carbon::now(),
                'ip' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 380, ''),
            ]);

            activity('newsletter_subscription')
                ->on($subscription)
                ->withProperties(['source' => $subscription->source])
                ->log("New newsletter subscriber: {$subscription->email}.");
        }

        // Welcome mail only fires on the first-ever subscription so
        // repeat submissions don't spam the inbox.
        if ($isFirstTime) {
            try {
                Mail::to($subscription->email)->queue(new NewsletterWelcomeMail($subscription));
            } catch (\Throwable $e) {
                Log::warning('Newsletter welcome mail queue failed (non-blocking)', [
                    'id' => $subscription->id, 'e' => $e->getMessage(),
                ]);
            }
        }

        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.marketing.newsletter-subscribe');
    }
}
