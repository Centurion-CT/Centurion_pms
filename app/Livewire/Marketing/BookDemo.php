<?php

declare(strict_types=1);

namespace App\Livewire\Marketing;

use App\Models\DemoRequest;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Demo-request form. Route: /demo.
 *
 * Replaces the self-serve "Start free trial" as the primary buyer
 * funnel — see project brief: hospitality operators in Nigeria
 * onboard better through concierge setup than through a 12-module
 * self-serve dashboard. Sign-up is still available for tinkerers
 * via /register.
 *
 * Rate-limited by IP so the form can't be used as a spam vector.
 * Storage is the source of truth; a WhatsApp/email nudge to the
 * sales phone/inbox is best-effort.
 */
class BookDemo extends Component
{
    /**
     * Passed through in the URL when the button was clicked from a
     * specific spot on the marketing page (hero | pricing | closing).
     * Powers attribution in the admin queue.
     */
    #[Url(as: 'src', except: '')]
    public string $source = 'landing';

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $property_name = '';
    public string $city = '';
    public string $property_size = '';
    public string $preferred_window = '';
    public string $notes = '';

    public bool $submitted = false;

    /** Simple honeypot — bots love filling every field, humans don't
     *  see this one. Non-empty on submit = drop the request. */
    public string $company_website = '';

    public function submit(GuestNotifier $notifier): void
    {
        // Drop bot fills silently — show the same happy state so the
        // bot doesn't learn we detected it.
        if ($this->company_website !== '') {
            $this->submitted = true;
            return;
        }

        $data = $this->validate([
            'name'             => ['required', 'string', 'max:120'],
            'email'            => ['required', 'email', 'max:180'],
            'phone'            => ['required', 'string', 'max:32'],
            'property_name'    => ['required', 'string', 'max:160'],
            'city'             => ['nullable', 'string', 'max:80'],
            'property_size'    => ['nullable', 'string', 'in:1-5,6-20,21-50,50+'],
            'preferred_window' => ['nullable', 'string', 'in:morning,afternoon,evening,anytime'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        // Per-IP rate limit: 3 submissions in 10 minutes is plenty for
        // a real prospect who might genuinely correct a typo.
        $key = 'demo-request:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many submissions from your network. Give it a few minutes.');
            return;
        }
        RateLimiter::hit($key, 600);

        $request = DemoRequest::query()->create([
            ...$data,
            'source'     => in_array($this->source, ['landing', 'hero', 'pricing', 'closing', 'nav', 'developers'], true)
                ? $this->source
                : 'landing',
            'status'     => 'new',
            'ip'         => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 380, ''),
        ]);

        // Best-effort sales WhatsApp ping. The whatsappUrl handoff
        // below is the primary channel (guest-driven message from
        // their own number); this template-message path just gives
        // the sales team a redundant heads-up regardless of whether
        // the guest actually taps Send.
        $salesPhone = (string) config('services.sales.whatsapp', '+2347043043061');
        if ($salesPhone !== '') {
            try {
                $notifier->sendRequestResolvedNotice(
                    $salesPhone,
                    sprintf(
                        "[Demo lead] %s from %s (%s). Phone %s · Email %s · Size %s · Wants %s.",
                        $request->name, $request->property_name,
                        $request->city ?: '?',
                        $request->phone, $request->email,
                        $request->property_size ?: '?',
                        $request->preferred_window ?: 'anytime',
                    ),
                );
            } catch (\Throwable $e) {
                Log::warning('Demo lead WhatsApp ping failed (non-blocking)', ['id' => $request->id, 'e' => $e->getMessage()]);
            }
        }

        activity('demo_request')
            ->on($request)
            ->withProperties(['source' => $request->source, 'size' => $request->property_size])
            ->log("New demo request from {$request->name} ({$request->property_name}).");

        // Build a wa.me deep link with the prospect's info pre-filled
        // so the thank-you page can open WhatsApp on the guest's own
        // device with a one-tap Send. Uses the +2347043043061 sales
        // number by default (overridable via services.sales.whatsapp).
        $this->whatsappUrl = $this->buildWhatsAppUrl($request);
        $this->submitted = true;
        $this->dispatch('demo-submitted', url: $this->whatsappUrl);
    }

    /**
     * The deep-link the thank-you screen opens. Publicly exposed so
     * the view can render a "click Send in WhatsApp" fallback button
     * in case the browser blocked the auto-open (Safari, etc).
     */
    public string $whatsappUrl = '';

    private function buildWhatsAppUrl(DemoRequest $r): string
    {
        $salesPhone = preg_replace('/\D+/', '', (string) config('services.sales.whatsapp', '+2347043043061')) ?? '';

        $lines = [
            "Hi Keverna, I'd like to book a demo.",
            '',
            "Name: {$r->name}",
            "Property: {$r->property_name}".($r->city ? ' ('.$r->city.')' : ''),
            "Phone: {$r->phone}",
            "Email: {$r->email}",
        ];
        if ($r->property_size)    $lines[] = 'Size: '.$r->property_size.' units';
        if ($r->preferred_window) $lines[] = 'Prefer: '.$r->preferred_window;
        if ($r->notes)            $lines[] = "\n".$r->notes;

        return 'https://wa.me/'.$salesPhone.'?text='.rawurlencode(implode("\n", $lines));
    }

    #[Layout('layouts.marketing')]
    public function render(): View
    {
        return view('livewire.marketing.book-demo');
    }
}
