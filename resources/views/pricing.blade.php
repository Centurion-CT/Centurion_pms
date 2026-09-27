{{--
    Dedicated pricing page. Content mirrors the operator-facing pricing
    memo (Keverna-Pricing.pdf) with full per-tier bullets. Landing carries
    a compact preview + a link here for the detailed read.

    Reuses the `.lp-*` design tokens from welcome.blade.php so both surfaces
    read as one product.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keverna · Elevated hospitality, one property at a time. Pricing that scales with you.</title>
    <meta name="description" content="Elevated hospitality, one property at a time. Pay for what you run, not for a feature list. Every tier ships the complete Keverna platform. Nigeria: Starter ₦180,000, Growth ₦420,000, Pro ₦900,000, Enterprise from ₦1,800,000 / yr. International: $125, $290, $620, from $1,250 / yr." />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('partials.platform-pulse')
</head>
<body class="lp-page">

@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';

    // Helper closure so each tier CTA carries `?plan=<slug>`, the
    // Register livewire reads it via the #[Url] attribute and seeds
    // the unit_count default. Enterprise doesn't use this because its
    // CTA is a mailto, not a register link.
    $registerWithPlan = static function (string $planSlug): string {
        $base = Route::has('register') ? route('register') : url('/register');

        return $base.'?plan='.$planSlug;
    };

    $tiers = [
        [
            'eyebrow' => 'First property',
            'name' => 'Starter',
            'units' => '1 – 5 units',
            'price_ngn' => '₦180,000',
            'price_usd' => '$125',
            'price_prefix' => '',
            'blurb' => 'Built for your first property, or for the operator testing the water with a small portfolio.',
            'bullets' => [
                'Every feature of the platform, with nothing held back for higher tiers',
                'Your own branded booking page from the first day',
                'List up to five rooms, desks, or apartments on one inventory',
                'Direct payouts to your own bank account',
                'The same dashboard and tools the largest operators use',
                'A fourteen-day free trial, with no card needed to begin',
            ],
            'cta_label' => 'Start free trial',
            'cta_href' => $registerWithPlan('starter'),
            'featured' => false,
        ],
        [
            'eyebrow' => 'Most operators choose this',
            'name' => 'Growth',
            'units' => '6 – 20 units',
            'price_ngn' => '₦420,000',
            'price_usd' => '$290',
            'price_prefix' => '',
            'blurb' => 'For the operator who has stopped tracking bookings on paper and started running a small team.',
            'bullets' => [
                'Room to grow across several properties without changing how you work',
                'One inventory covering nightly, weekly, monthly, and yearly stays',
                'Unit-level pricing, availability, and photos for up to twenty units',
                'Real-time dashboard with exports and automatic email confirmations',
                'Card, bank transfer, and USSD payments, settled directly to you',
                'A fourteen-day free trial, with no card needed to begin',
            ],
            'cta_label' => 'Start free trial',
            'cta_href' => $registerWithPlan('growth'),
            'featured' => true,
        ],
        [
            'eyebrow' => 'At real scale',
            'name' => 'Pro',
            'units' => '21 – 50 units',
            'price_ngn' => '₦900,000',
            'price_usd' => '$620',
            'price_prefix' => '',
            'blurb' => 'For serviced-apartment portfolios, boutique hotels, and co-working spaces operating at real scale.',
            'bullets' => [
                'Everything in Growth, sized and priced for a larger, busier inventory',
                'Up to fifty units managed on a single platform and a single bill',
                'Full booking history and data exports for your finance team',
                'A flat 2% on card bookings, and nothing on cash or transfers',
                'Reliable service with 99.5% uptime and email support',
                'A fourteen-day free trial, with no card needed to begin',
            ],
            'cta_label' => 'Start free trial',
            'cta_href' => $registerWithPlan('pro'),
            'featured' => false,
        ],
        [
            'eyebrow' => 'Groups and chains',
            'name' => 'Enterprise',
            'units' => '50 or more units',
            'price_ngn' => '₦1,800,000',
            'price_usd' => '$1,250',
            'price_prefix' => 'From',
            'blurb' => 'For multi-property groups, chains, and residential operators that need custom terms.',
            'bullets' => [
                'Every unit across every property managed under one agreement',
                'Custom terms shaped around how your group actually operates',
                'Dedicated onboarding for your team at each location',
                'A direct line to our team whenever you need it',
                'Multi-tenant data isolation keeping every property&rsquo;s records separate',
                'Pricing agreed with you, starting from ₦1,800,000 per year (₦-based operators) or $1,250 (international operators)',
            ],
            'cta_label' => 'Talk to us',
            'cta_href' => 'mailto:hello@keverna.com?subject=Enterprise%20pricing',
            'featured' => false,
        ],
    ];

    $included = [
        [
            'title' => 'Your own booking page',
            'body_ngn' => 'Hosted at your-name.keverna.com. A custom domain is available for a one-time fee of ₦25,000.',
            'body_usd' => 'Hosted at your-name.keverna.com. A custom domain is available for a one-time fee of $20.',
        ],
        [
            'title' => 'Any length of stay',
            'body' => 'Take bookings for a single night, a week, a month, or a full year, all on one inventory.',
        ],
        [
            'title' => 'Unit-level control',
            'body' => 'Set pricing, availability, and photos for each individual room, desk, or unit you list.',
        ],
        [
            'title' => 'Direct payouts',
            'body' => 'Money lands in your own bank account, with card, bank transfer, and USSD all supported.',
        ],
        [
            'title' => 'A real-time dashboard',
            'body' => 'See every booking as it happens, export your data, and send automatic email confirmations.',
        ],
        [
            'title' => 'Reliable and private',
            'body' => '99.5% uptime, email support, and fully isolated data for every operator on the platform.',
        ],
    ];
@endphp

<div class="lp-shell">

    {{-- Top nav card, same shape as the landing, minus the mobile-nav
         details toggle because this page's nav is short enough not to
         need one. --}}
    <header class="lp-card lp-card-cream" style="padding: 14px 18px;">
        <div class="flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5">
                <span aria-hidden class="inline-flex h-7 w-7 items-center justify-center" style="background: var(--color-ink); color: var(--color-card); border-radius: 8px;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                </span>
                <span class="lp-display text-[20px] tracking-tight" style="color: var(--color-ink); font-weight: 600;">Keverna</span>
            </a>
            <nav class="hidden md:flex items-center gap-7">
                <a href="/#how"       class="text-[13.5px]" style="color: var(--color-ink-soft);">Process</a>
                <a href="/#use-cases" class="text-[13.5px]" style="color: var(--color-ink-soft);">Use cases</a>
                <a href="{{ url('/pricing') }}" class="text-[13.5px]" style="color: var(--color-ink); font-weight: 500;">Pricing</a>
                @auth
                    <a href="{{ url('/dashboard') }}" class="text-[13.5px]" style="color: var(--color-ink-soft);">Dashboard</a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="text-[13.5px]" style="color: var(--color-ink-soft);">Sign in</a>
                    @endif
                @endauth
            </nav>
            <div style="display: inline-flex; align-items: center; gap: 12px;">
                @include('partials.region-picker')
                <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                    Get started
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>
            </div>
        </div>
    </header>

    {{-- ─── Headline card ────────────────────────────────────────────── --}}
    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <div class="grid items-end gap-10 lg:grid-cols-[1.1fr_1fr] lg:gap-16">
                <div>
                    <span class="lp-eyebrow-strip">Pricing</span>
                    <h1 class="lp-display mt-4 text-[clamp(36px,5vw,68px)]" style="color: var(--color-ink); line-height: 1.02; font-weight: 600;">
                        Pricing that<br>scales with you.
                    </h1>
                    <p class="lp-display mt-5 text-[clamp(18px,2vw,22px)]" style="color: var(--color-ink-mute); font-weight: 500; font-style: italic;">
                        Elevated hospitality, one property
                        <span style="color: var(--color-accent-deep);">at a time.</span>
                        Pay for what you run, not for a feature list.
                    </p>
                </div>
                <p class="text-[15.5px] leading-[1.75] max-w-[540px]" style="color: var(--color-ink-soft);">
                    Every tier ships the complete Keverna platform. You get your own branded booking page,
                    direct payouts to your bank, and support for any length of stay across a single inventory.
                    You are billed by how many units you list, never by which features you unlock.
                </p>
            </div>
        </div>
    </section>

    {{-- ─── How you are billed (3 columns) ───────────────────────────── --}}
    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <div class="grid gap-8 sm:grid-cols-3 sm:gap-10">
                @foreach ([
                    [
                        'eyebrow' => 'How you are billed',
                        'title'   => 'By units, not features',
                        'body'    => 'The price is set by how many rooms, desks, or apartments you list. Every operator runs the entire platform, from the first property to the largest portfolio.',
                    ],
                    [
                        'eyebrow' => 'Card payments',
                        'title'   => 'A flat two percent',
                        'body'    => 'When a guest pays by card through your page, Keverna takes a flat 2%. Nothing to configure, and nothing hidden in the total.',
                    ],
                    [
                        'eyebrow' => 'Cash and transfers',
                        'title'   => 'No fee at all',
                        'body'    => 'Bookings you settle in person, by cash or bank transfer, stay with you in full. Keverna charges nothing on payments taken off the platform.',
                    ],
                ] as $col)
                    <div>
                        <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">{{ $col['eyebrow'] }}</p>
                        <p class="lp-display mt-2 text-[20px]" style="color: var(--color-ink); font-weight: 600;">{{ $col['title'] }}</p>
                        <p class="mt-3 text-[14.5px] leading-[1.65]" style="color: var(--color-ink-soft);">{{ $col['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ─── Multi-currency band ──────────────────────────────────────── --}}
    <section class="lp-card lp-card-cream" data-testid="multi-currency-band">
        <div class="lp-card-body"
             x-data
             style="display: grid; gap: 24px; align-items: center; grid-template-columns: 1fr;">
            <div>
                <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">
                    Multi-currency
                </p>
                <p class="lp-display mt-3 text-[clamp(22px,2.4vw,30px)]" style="color: var(--color-ink); font-weight: 600; line-height: 1.15;">
                    Prices in your currency, wherever you run.
                </p>
                <p class="mt-4 text-[15px] leading-[1.7] max-w-[720px]" style="color: var(--color-ink-soft);">
                    Nigerian operators run Keverna in naira. Operators elsewhere run in
                    US dollars for now, with Ghanaian cedi, Kenyan shilling, Rand, CFA,
                    Egyptian pound, and every other African currency arriving as we roll
                    out FX support. Inside the app, the pick you make in Settings drives
                    every invoice, receipt, dashboard tile, and POS ticket. No currency
                    conversion between branches; each branch operates in its own money.
                </p>
                <p class="mt-4 text-[13.5px]" style="color: var(--color-ink-mute);">
                    <span x-show="$store.kv?.region === 'NG'" x-cloak>
                        You're currently seeing prices in <strong style="color: var(--color-ink);">Nigerian naira</strong>.
                        Change from the country picker at the top of the page.
                    </span>
                    <span x-show="$store.kv?.region === 'INTL'" x-cloak>
                        You're currently seeing prices in <strong style="color: var(--color-ink);">US dollars</strong>.
                        Change from the country picker at the top of the page.
                    </span>
                    <span x-show="!$store.kv?.region" x-cloak>
                        Pick your region from the country picker at the top of the page to see prices in your currency.
                    </span>
                </p>
            </div>
        </div>
    </section>

    {{-- ─── Tier grid (full detail) ──────────────────────────────────── --}}
    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <div class="grid gap-6 md:grid-cols-2 lg:gap-8">
                @foreach ($tiers as $tier)
                    <article
                        class="relative flex flex-col p-7 sm:p-9"
                        style="
                            background: var(--color-card-deep);
                            border-radius: 20px;
                            {{ $tier['featured'] ? 'outline: 2px solid var(--color-accent-deep); outline-offset: -2px;' : '' }}
                        "
                    >
                        <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase"
                           style="color: {{ $tier['featured'] ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                            {{ $tier['eyebrow'] }}
                        </p>

                        <p class="lp-display mt-3 text-[28px]" style="color: var(--color-ink); font-weight: 600;">
                            {{ $tier['name'] }}
                        </p>
                        <p class="mt-1 text-[13px]" style="color: var(--color-ink-mute);">
                            {{ $tier['units'] }}
                        </p>

                        {{-- Dual currency: NGN for Nigerian operators,
                             USD for everywhere else. The visible one is
                             chosen client-side from Alpine's `kv` store
                             so switching regions from the header dropdown
                             flips prices without a page reload. --}}
                        <div class="mt-6 flex items-baseline gap-2" x-data>
                            @if ($tier['price_prefix'])
                                <span class="lp-display text-[16px]" style="color: var(--color-ink-mute); font-weight: 500;">
                                    {{ $tier['price_prefix'] }}
                                </span>
                            @endif
                            <span x-show="$store.kv?.region !== 'INTL'"
                                  data-testid="price-ngn-{{ Str::slug($tier['name']) }}"
                                  class="lp-display text-[38px] leading-none" style="color: var(--color-ink); font-weight: 600;">
                                {{ $tier['price_ngn'] }}
                            </span>
                            <span x-show="$store.kv?.region === 'INTL'"
                                  x-cloak
                                  data-testid="price-usd-{{ Str::slug($tier['name']) }}"
                                  class="lp-display text-[38px] leading-none" style="color: var(--color-ink); font-weight: 600;">
                                {{ $tier['price_usd'] }}
                            </span>
                        </div>
                        <p class="mt-1.5 text-[13px]" style="color: var(--color-ink-mute);">
                            per year, covering every unit on the tier
                        </p>

                        <hr class="mt-6" style="border-color: var(--color-line);" />

                        <p class="mt-5 text-[14.5px] leading-[1.65]" style="color: var(--color-ink); font-style: italic; font-weight: 500;">
                            {{ $tier['blurb'] }}
                        </p>

                        <ul class="mt-5 grid gap-2.5 text-[14px]" style="color: var(--color-ink);">
                            @foreach ($tier['bullets'] as $b)
                                <li class="flex items-start gap-2.5">
                                    <span aria-hidden class="mt-[7px] inline-block h-[5px] w-[5px] shrink-0" style="background: var(--color-accent-deep);"></span>
                                    <span>{!! $b !!}</span>
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ $tier['cta_href'] }}" class="lp-pill mt-7 self-start">
                            {{ $tier['cta_label'] }}
                            <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ─── Included in every tier ───────────────────────────────────── --}}
    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <div class="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-baseline">
                <h2 class="lp-display text-[clamp(24px,3vw,34px)]" style="color: var(--color-ink); font-weight: 600;">
                    Included in every tier.
                </h2>
                <p class="lp-display text-[15px] sm:text-[16px]" style="color: var(--color-ink-mute); font-style: italic; font-weight: 500;">
                    Nothing here is gated behind a higher plan.
                </p>
            </div>

            <hr class="mt-6" style="border-color: var(--color-line);" />

            <ul class="mt-8 grid gap-8 sm:grid-cols-2 sm:gap-x-16 sm:gap-y-9">
                @foreach ($included as $item)
                    <li>
                        <p class="lp-display text-[17px]" style="color: var(--color-ink); font-weight: 600;">
                            {!! $item['title'] !!}
                        </p>
                        @if (isset($item['body']))
                            <p class="mt-2 text-[14.5px] leading-[1.65]" style="color: var(--color-ink-soft);">
                                {!! $item['body'] !!}
                            </p>
                        @else
                            {{-- Dual-currency copy — same body, differing only
                                 in the local-currency figure. Toggled by region. --}}
                            <p x-data x-show="$store.kv?.region !== 'INTL'"
                               class="mt-2 text-[14.5px] leading-[1.65]" style="color: var(--color-ink-soft);">
                                {!! $item['body_ngn'] !!}
                            </p>
                            <p x-data x-show="$store.kv?.region === 'INTL'" x-cloak
                               class="mt-2 text-[14.5px] leading-[1.65]" style="color: var(--color-ink-soft);">
                                {!! $item['body_usd'] !!}
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ─── Closing CTA (dark) ───────────────────────────────────────── --}}
    <section class="lp-card lp-card-ink">
        <div class="lp-card-body">
            <div class="grid items-center gap-8 lg:grid-cols-[1.5fr_1fr]">
                <div>
                    <h2 class="lp-display text-[clamp(26px,4vw,44px)]" style="color: var(--color-card); font-weight: 600; line-height: 1.08;">
                        Every tier starts with a<br>fourteen-day free trial.
                    </h2>
                    <p class="mt-4 text-[15px]" style="color: rgba(244,239,230,0.75);">
                        No card needed to begin.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-4 lg:justify-end">
                    <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                        Start free trial
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </a>
                    <a href="mailto:hello@keverna.com?subject=Pricing%20question" class="text-[13.5px] underline" style="color: rgba(244,239,230,0.8); text-underline-offset: 4px; text-decoration-color: rgba(244,239,230,0.3);">
                        Talk to us
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ─── Footer (matches welcome) ─────────────────────────────────── --}}
    <footer class="lp-card lp-card-cream">
        <div class="px-7 sm:px-10 py-10 sm:py-12">
            <div class="flex flex-col items-start justify-between gap-5 sm:flex-row">
                <div>
                    <p class="lp-display text-[22px]" style="color: var(--color-ink); font-weight: 600;">Keverna</p>
                    <p class="mt-2 text-[12.5px]" style="color: var(--color-ink-mute);">
                        Booking infrastructure for African operators.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-6 text-[13px]" style="color: var(--color-ink-soft);">
                    <a href="/#how" class="hover:underline" style="text-underline-offset: 4px;">Process</a>
                    <a href="/#use-cases" class="hover:underline" style="text-underline-offset: 4px;">Use cases</a>
                    <a href="{{ url('/pricing') }}" class="hover:underline" style="text-underline-offset: 4px;">Pricing</a>
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="hover:underline" style="text-underline-offset: 4px;">Sign in</a>
                    @endif
                    <a href="mailto:hello@keverna.com" class="hover:underline" style="text-underline-offset: 4px;">hello@keverna.com</a>
                </div>
            </div>
            <div class="border-t mt-8 pt-5 text-[12px] flex flex-col sm:flex-row gap-2 justify-between" style="border-color: var(--color-line-soft); color: var(--color-ink-mute);">
                <span>© {{ date('Y') }} FregateLab Limited. All rights reserved.</span>
                <span>Abuja · Lagos · Port Harcourt</span>
            </div>
        </div>
    </footer>

</div>

@include('partials.whatsapp-float')
@include('partials.platform-pulse-toast')

</body>
</html>
