@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Page header ────────────────────────────────────────────── --}}
    <div>
        <span class="lp-eyebrow-strip">Business</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Subscription
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Your plan, status, and renewal.
        </p>
    </div>

    {{-- ── Current plan ─────────────────────────────────────────── --}}
    @if ($currentSubscription)
        @php
            $statusValue = $currentSubscription->status->value;
            $statusClass = match ($statusValue) {
                'active'   => 'lp-tag--confirmed',
                'trialing' => 'lp-tag--pending',
                'expired'  => 'lp-tag--cancelled',
                'past_due' => 'lp-tag--on-hold',
                default    => '',
            };
            $statusLabel = ucwords(str_replace('_', ' ', $statusValue));
            $planLabel   = ucfirst($currentSubscription->plan->value);
        @endphp

        <section data-testid="current-sub" class="lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Current plan</span>
                <span class="lp-tag {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>

            <div class="grid gap-6 sm:grid-cols-3">
                <div>
                    <p class="lp-form-label">Plan</p>
                    <p class="lp-stat-figure" style="font-size: clamp(28px, 3vw, 36px);">
                        {{ $planLabel }}
                    </p>
                </div>
                <div>
                    <p class="lp-form-label">Status</p>
                    <p class="lp-display mt-1" style="font-size: 20px; font-weight: 600;">
                        {{ $statusLabel }}
                    </p>
                </div>
                <div>
                    <p class="lp-form-label">Expires</p>
                    <p class="lp-display mt-1" style="font-size: 16px; font-weight: 500;">
                        {{ $currentSubscription->expires_at->toFormattedDateString() }}
                    </p>
                    <p class="text-[12px] mt-1" style="color: var(--color-ink-mute);">
                        {{ $currentSubscription->expires_at->toDayDateTimeString() }}
                    </p>
                </div>
            </div>
        </section>
    @else
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-sub" class="text-[18px]"
               style="color: var(--color-ink); font-weight: 500;">
                No active subscription.
            </p>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                Start an annual plan to keep your booking page live.
            </p>
        </section>
    @endif

    {{-- ── Upgrade CTA ──────────────────────────────────────────── --}}
    @if (! $currentSubscription || $currentSubscription->plan === \App\Enums\SubscriptionPlan::Trial)
        <section data-testid="upgrade-cta" class="lp-card lp-card-ink"
                 style="padding: 36px 32px; border-radius: 22px; overflow: hidden;">
            <div class="grid gap-8 lg:grid-cols-[1.2fr_1fr] items-center">

                <div>
                    <span class="lp-eyebrow-strip" style="color: var(--color-accent);">Annual plan</span>
                    <h2 class="lp-display mt-3 text-[clamp(28px,3.6vw,40px)]"
                        style="color: var(--color-card); font-weight: 600; line-height: 1.04;">
                        Keep your booking page live for a year.
                    </h2>
                    <p class="mt-4 text-[14.5px]" style="color: rgba(244,239,230,0.72); max-width: 480px; line-height: 1.55;">
                        Unlimited bookings, your own custom domain, fully branded landing page,
                        same-day Paystack payouts. One price, billed yearly and no per-booking cuts.
                    </p>

                    <ul class="grid gap-2.5 mt-6 text-[13.5px]" style="color: rgba(244,239,230,0.82); list-style: none; padding: 0;">
                        @foreach ([
                            'Unlimited bookings &middot; unlimited rooms',
                            'Custom domain (reservations.yourbrand.com)',
                            'Same-day Paystack payouts',
                            'Operator dashboard, audit log, guest reviews',
                            'Email + SMS confirmations, balance reminders',
                        ] as $line)
                            <li class="flex items-start gap-2.5">
                                <span aria-hidden style="margin-top: 7px; display: inline-block; width: 5px; height: 5px; background: var(--color-accent); flex-shrink: 0;"></span>
                                <span>{!! $line !!}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div style="background: rgba(244,239,230,0.05); border: 1px solid var(--color-line-on-dark); border-radius: 18px; padding: 28px 26px; display: grid; gap: 16px;">
                    <p class="text-[11px]" style="color: rgba(244,239,230,0.55); letter-spacing: 0.18em; text-transform: uppercase;">
                        Annual subscription
                    </p>
                    <p class="lp-display" style="font-size: clamp(40px, 5vw, 56px); font-weight: 600; color: var(--color-card); line-height: 1;">
                        ₦{{ number_format($annualPriceNgn) }}
                        <span class="lp-display" style="font-size: 16px; font-weight: 500; color: rgba(244,239,230,0.55); letter-spacing: 0;">
                            / year
                        </span>
                    </p>
                    <p class="text-[12.5px]" style="color: rgba(244,239,230,0.65);">
                        Billed once. No card-on-file. Renews on your anniversary or you decide each year.
                    </p>
                    <button wire:click="upgrade" data-testid="upgrade-btn" data-billing-safe class="lp-pill" style="justify-content: space-between;">
                        <span wire:loading.remove wire:target="upgrade">Upgrade now</span>
                        <span wire:loading wire:target="upgrade">Redirecting to Paystack…</span>
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                    <p class="text-[11px]" style="color: rgba(244,239,230,0.45); text-align: center;">
                        Paid securely via Paystack &middot; cards, transfer, USSD
                    </p>
                </div>
            </div>
        </section>
    @endif
</div>
