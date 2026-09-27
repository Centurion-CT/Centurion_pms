{{--
    Keverna landing, rounded card-stack overhaul.

    Direction adapted from the reference: olive-lime accent, pill-CTA with
    circular dot, alternating dark/cream rounded cards on a warm-paper
    page background. Each major section is its own card with generous
    border-radius; small gaps between cards show the page colour through.

    Scoped under `.lp-page`. Operator dashboard chrome (.kv-*) is untouched.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keverna · Elevated hospitality, one property at a time. Booking infrastructure for any duration.</title>
    <meta name="description" content="The booking layer for short stays, monthly memberships, workspace seats, and annual leases. Your own branded page. Paystack payouts. Tiered pricing from ₦180,000/year." />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Marketing-page-scoped mobile polish. Kept inline rather than in
         app.css because every rule below targets THIS page only, the
         operator dashboard reuses the same .lp-* tokens and we don't
         want to inherit mobile-only nav behaviour. --}}
    <style>
        /* The nav card needs to escape the .lp-card `overflow: hidden`
           rule so the mobile dropdown isn't clipped by the card's
           rounded corners, AND it needs to sit above the next card
           in the stacking order so the panel isn't painted under the
           hero. Solid background means we don't lose anything by
           dropping `overflow: hidden` here, there's no child image
           bleeding past the corners on this card. */
        .lp-page .lp-nav-card {
            overflow: visible;
            position: relative;
            z-index: 50;
        }

        /* Mobile hamburger menu, uses native <details> so it works
           without Alpine/JS and stays accessible by default. */
        .lp-mobile-nav { position: relative; }
        .lp-mobile-nav summary {
            list-style: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px; height: 36px;
            border-radius: 10px;
            background: transparent;
            color: var(--color-ink);
            border: 1px solid rgba(20, 23, 26, 0.1);
        }
        .lp-mobile-nav summary::-webkit-details-marker { display: none; }
        .lp-mobile-nav summary .icon-bars,
        .lp-mobile-nav summary .icon-close { display: block; }
        .lp-mobile-nav[open] summary .icon-bars { display: none; }
        .lp-mobile-nav summary .icon-close { display: none; }
        .lp-mobile-nav[open] summary .icon-close { display: block; }
        .lp-mobile-nav-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 220px;
            background: var(--color-card);
            border: 1px solid var(--color-line);
            border-radius: 14px;
            padding: 14px 6px;
            box-shadow: 0 24px 60px -20px rgba(20, 23, 26, 0.25);
            display: grid;
            gap: 2px;
            z-index: 100;
        }
        .lp-mobile-nav-panel a {
            display: block;
            padding: 10px 14px;
            font-size: 14px;
            color: var(--color-ink-soft);
            border-radius: 8px;
        }
        .lp-mobile-nav-panel a:hover {
            background: var(--color-card-deep);
            color: var(--color-ink);
        }

        /* Tighten card-body padding on phones, at <420px the 26px
           horizontal gutter eats too much of an already-narrow card. */
        @media (max-width: 480px) {
            .lp-page .lp-card-body { padding: 26px 18px; }
        }

        /* Hero, keep the headline from running into the photo / off
           the side on small screens. clamp() handles most of it; this
           just pins the small-end minimum. */
        @media (max-width: 480px) {
            .lp-page .lp-hero-headline { font-size: clamp(40px, 11vw, 56px) !important; }
            .lp-page .lp-hero-copy     { font-size: 14.5px !important; }
        }

        /* Featured "Dream Stay", the 480px min-height crops weirdly on
           short phones; let it size to content there. */
        @media (max-width: 640px) {
            .lp-page .lp-featured { min-height: 0 !important; }
            .lp-page .lp-featured .lp-featured-inner { min-height: 0 !important; }
        }

        /* Pricing tiers, each amount now renders at 34px inside a
           narrower tier card, so no size override is needed. The
           `From ₦1,800,000` string in Enterprise is the widest; on a
           360px viewport it still fits on one line at 34px. */

        /* Closing CTA, `min-height: 360px` plus large headline crowds
           the photo below. Release the min-height on phones. */
        @media (max-width: 640px) {
            .lp-page .lp-closing { min-height: 0 !important; }
        }

        /* Use-case + step "Do it yourself" CTA + secondary link wrap
           cleanly via flex-wrap, but the gap can be too generous -
           tighten on phones so the secondary link doesn't sit alone
           on its own row with too much air above. */
        @media (max-width: 480px) {
            .lp-page .lp-cta-row { gap: 14px !important; }
        }

        /* Step rows on mobile, the description was inheriting
           `text-align: left` from the inline mobile override, but the
           `padding-top` could push the desc tight against the title
           with very long copy. Add a touch of breathing room. */
        @media (max-width: 640px) {
            .lp-page .lp-step { padding: 14px 14px 14px 18px; }
            .lp-page .lp-step-desc { padding-top: 4px !important; }
        }
    </style>
</head>
<body class="lp-page">

@php
    // Small reusable arrow used inside the .lp-pill-dot accent.
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-shell">

    {{-- ─── Top navigation card ────────────────────────────────────── --}}
    <header class="lp-card lp-card-cream lp-nav-card" style="padding: 14px 18px;">
        <div class="flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5">
                <span aria-hidden class="inline-flex h-7 w-7 items-center justify-center" style="background: var(--color-ink); color: var(--color-card); border-radius: 8px;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                </span>
                <span class="lp-display text-[20px] tracking-tight" style="color: var(--color-ink); font-weight: 600;">Keverna</span>
            </a>
            <nav class="hidden md:flex items-center gap-7">
                <a href="#how"       class="text-[13.5px]" style="color: var(--color-ink-soft);">Process</a>
                <a href="#use-cases" class="text-[13.5px]" style="color: var(--color-ink-soft);">Use cases</a>
                <a href="#pricing"   class="text-[13.5px]" style="color: var(--color-ink-soft);">Pricing</a>
                @auth
                    <a href="{{ url('/dashboard') }}" class="text-[13.5px]" style="color: var(--color-ink-soft);">Dashboard</a>
                @else
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}" class="text-[13.5px]" style="color: var(--color-ink-soft);">Sign in</a>
                    @endif
                @endauth
            </nav>

            <div class="flex items-center gap-3">
                @include('partials.region-picker')
                <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                    Get started
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>

                {{-- Mobile-only menu trigger (hidden on md+ where the
                     inline nav handles it). Uses native <details> so
                     it works without JS, toggling the disclosure
                     swaps the bars/close icons via CSS. --}}
                <details class="lp-mobile-nav md:hidden">
                    <summary aria-label="Open menu">
                        <svg class="icon-bars" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
                        <svg class="icon-close" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
                    </summary>
                    <div class="lp-mobile-nav-panel">
                        <a href="#how">Process</a>
                        <a href="#use-cases">Use cases</a>
                        <a href="#pricing">Pricing</a>
                        @auth
                            <a href="{{ url('/dashboard') }}">Dashboard</a>
                        @else
                            @if (Route::has('login'))
                                <a href="{{ route('login') }}">Sign in</a>
                            @endif
                        @endauth
                    </div>
                </details>
            </div>
        </div>
    </header>

    {{-- ─── Hero card (dark) ───────────────────────────────────────── --}}
    <section class="lp-card lp-card-ink relative">
        <div class="lp-card-body relative grid items-end gap-10 lg:grid-cols-[1.15fr_1fr] lg:gap-14">
            {{-- LEFT: copy --}}
            <div>
                <span class="lp-script text-[clamp(20px,2vw,26px)]" style="color: var(--color-accent);">
                    Elevated hospitality, one property at a time,
                </span>
                <h1 class="lp-display lp-hero-headline mt-3 text-[clamp(48px,7vw,96px)] tracking-[-0.025em]" style="color: var(--color-card); font-weight: 600; line-height: 1.0;">
                    Every unit,<br>one page.
                </h1>
                <p class="lp-hero-copy mt-7 max-w-[460px] text-[15.5px] leading-[1.7]" style="color: rgba(244, 239, 230, 0.75);">
                    Run a single room, a small portfolio, or an entire building from the same branded page.
                    <span style="color: var(--color-card);">Your domain. Your brand. Payouts straight to your bank.</span>
                </p>

                {{-- Primary + secondary path inline with the headline so the
                     CTA sits inside the visual hierarchy, not just in the
                     chrome corner. --}}
                <div class="lp-cta-row mt-8 flex flex-wrap items-center gap-5">
                    <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                        Start free trial
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </a>
                    <a href="#how" class="text-[13.5px] underline" style="color: rgba(244,239,230,0.8); text-underline-offset: 4px; text-decoration-color: rgba(244,239,230,0.3);">
                        See how it works &nbsp;↓
                    </a>
                </div>
            </div>

            {{-- RIGHT: large portrait/hero photo --}}
            <div class="relative">
                <div class="lp-tile aspect-[5/6] w-full overflow-hidden" style="border-radius: 18px;">
                    @if (file_exists(public_path('img/landing/hero.jpg')))
                        <img src="/img/landing/hero.jpg" alt="" class="h-full w-full object-cover" />
                    @else
                        <div class="lp-photo-stub h-full w-full">Hero · 5:6</div>
                    @endif
                </div>
                {{-- Quote card floating over the photo --}}
                <div class="hidden md:block absolute left-[-20px] bottom-8 max-w-[260px] p-5"
                     style="background: var(--color-card); color: var(--color-ink); border-radius: 14px; box-shadow: 0 30px 60px -20px rgba(0,0,0,0.4);">
                    <p class="lp-display text-[17px] leading-[1.3]" style="font-weight: 500;">
                        &ldquo;Started with two rooms. Now we run twenty on the same page.&rdquo;
                    </p>
                    <p class="mt-3 text-[11px] uppercase tracking-[0.16em]" style="color: var(--color-ink-mute);">
                        Built for operators
                    </p>
                </div>
            </div>

        </div>

        {{-- Bottom band: durations in 4 columns --}}
        <div class="border-t" style="border-color: var(--color-line-on-dark);">
            <div class="grid grid-cols-2 md:grid-cols-4">
                @foreach ([
                    ['01', 'Nightly',  'Short stays · serviced apartments'],
                    ['02', 'Weekly',   'Executive stays · medical travel'],
                    ['03', 'Monthly',  'Workspace seats · serviced flats'],
                    ['04', 'Annually', 'Residential leases · long stays'],
                ] as $i => [$n, $t, $b])
                    <div class="px-5 py-5 sm:px-8 sm:py-7 {{ $i > 0 ? 'md:border-l' : '' }} {{ $i > 1 ? 'border-t md:border-t-0' : '' }} {{ $i == 1 ? 'border-t md:border-t-0' : '' }}"
                         style="border-color: var(--color-line-on-dark);">
                        <p class="lp-mono text-[10.5px] tracking-[0.16em]" style="color: rgba(244,239,230,0.5);">#{{ $n }}</p>
                        <p class="lp-display mt-1.5 text-[18px]" style="color: var(--color-card); font-weight: 600;">{{ $t }}</p>
                        <p class="mt-1 text-[12px]" style="color: rgba(244,239,230,0.55);">{{ $b }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ─── By hand (cream) ─────────────────────────────────────────── --}}
    <section class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <div class="grid items-start gap-10 lg:grid-cols-[5fr_7fr] lg:gap-16">
                <div class="lg:sticky lg:top-8">
                    <span class="lp-eyebrow-strip">How it runs today</span>
                    {{-- Headline + subtitle no longer compete at the same
                         scale. "By hand." is the punch, the direct answer
                         to the eyebrow, and the line beneath drops to a
                         quieter tier so the eye flows down. --}}
                    <h2 class="lp-display mt-4 text-[clamp(40px,5.5vw,68px)]" style="color: var(--color-ink); line-height: 1.0; font-weight: 600;">
                        By hand.
                    </h2>
                    <p class="lp-display mt-3 text-[clamp(18px,2vw,22px)]" style="color: var(--color-ink-mute); font-weight: 500; font-style: italic;">
                        Four moments you already know.
                    </p>

                    @if (file_exists(public_path('img/landing/current-options.jpg')))
                        <figure class="mt-9">
                            <div class="lp-tile aspect-[4/5] w-full overflow-hidden">
                                <img src="/img/landing/current-options.jpg" alt="" class="h-full w-full object-cover" />
                            </div>
                            {{-- Small italic caption gives the photo
                                 editorial purpose so it doesn't read as
                                 decoration. --}}
                            <figcaption class="lp-script mt-4 text-[14px]" style="color: var(--color-ink-mute);">
                                The room where most properties are still run from.
                            </figcaption>
                        </figure>
                    @endif
                </div>

                {{-- Push the ledger down so it begins level with the
                     image on the left, not flush with the eyebrow. Reads
                     more like an editorial spread. Mobile stays compact
                     so the rows don't sit in mid-air on small screens. --}}
                <ol class="grid mt-0 lg:mt-32">
                    @foreach ([
                        [
                            'The 11pm call.',
                            'A stranger has your number from a listing page. They want Saturday. You have to check WhatsApp, the office tab, and two reservation books to be sure. By the time you reply, they&rsquo;ve already booked someone else.',
                        ],
                        [
                            'The Saturday-night check.',
                            'Three guests have paid. Two have asked. The threads are on three different numbers. You haven&rsquo;t double-booked yet, but this is the part of every weekend where you check, then check again.',
                        ],
                        [
                            'The cheque you don&rsquo;t see.',
                            'You hosted her. Met her at the door, sent the address twice, gave her the late checkout. The booking was ₦200,000. ₦170,000 reached your account. She&rsquo;s stayed five times. You still don&rsquo;t have her email.',
                        ],
                        [
                            'Cell B7.',
                            'It was one tab when you started. It&rsquo;s six now, with four people editing and one formula nobody on the team dares touch. The whole business sits inside a spreadsheet you&rsquo;re a little afraid of.',
                        ],
                    ] as $i => [$tool, $body])
                        <li class="lp-ledger-row">
                            <span class="lp-ledger-num">{{ str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <div>
                                <p class="lp-display text-[20px] sm:text-[22px]" style="color: var(--color-ink); font-weight: 600;">{!! $tool !!}</p>
                                <p class="mt-2 text-[14.5px] leading-[1.65]" style="color: var(--color-ink-soft);">{!! $body !!}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Closing spread: accent statement on the left, CTA on the
                 right of the same row, so they read as a finished pair
                 instead of two stacked blocks. On narrow screens the CTA
                 wraps below naturally. --}}
            <div class="mt-14 border-t pt-10 grid items-end gap-8 lg:grid-cols-[1.6fr_auto] lg:gap-12" style="border-color: var(--color-line);">
                <p class="lp-display text-[clamp(22px,2.4vw,30px)] max-w-[720px]" style="color: var(--color-ink); line-height: 1.2;">
                    You built the business by hand. We built the system underneath, so the next ten years don&rsquo;t look like the last ten.
                </p>
                <a href="#how" class="lp-pill shrink-0">
                    Show me what that looks like
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>
            </div>
        </div>
    </section>

    {{-- ─── Step by step (cream) ────────────────────────────────────── --}}
    <section id="how" class="lp-card lp-card-cream lp-steps-section relative">
        <div class="lp-card-body">
            <div class="grid items-start gap-8 lg:grid-cols-[1.05fr_1fr] lg:gap-16">
                <div>
                    <span class="lp-eyebrow-strip">Step-by-Step</span>
                    <h2 class="lp-display mt-4 text-[clamp(28px,4vw,46px)]" style="color: var(--color-ink); line-height: 1.06;">
                        How Keverna works,<br>step by step.
                    </h2>
                </div>
                <p class="text-[15.5px] leading-[1.65] pt-2" style="color: var(--color-ink-soft);">
                    From the first click to your first booking. A clear, end-to-end path that
                    turns a property into a business that runs itself.
                </p>
            </div>

            {{-- All rows start inactive. Hovering one lights it up and
                 reveals the floating thumbnail beneath the cursor. Each
                 row carries a data-thumb that maps to a property photo
                 so each step gets its own image. --}}
            <ol class="lp-steps mt-14">
                @php
                    $steps = [
                        ['num'=>'01','title'=>'Sign up',    'desc'=>'Create your account and start the 14-day trial. No card required.','thumb'=>'hero.jpg'],
                        ['num'=>'02','title'=>'Configure',  'desc'=>'Tell us what you rent and set rates per duration.',               'thumb'=>'segment-serviced-apartments.jpg'],
                        ['num'=>'03','title'=>'Brand',      'desc'=>'Pick a free subdomain or point your own with one CNAME.',         'thumb'=>'segment-workspaces.jpg'],
                        ['num'=>'04','title'=>'Launch',     'desc'=>'Your booking page goes live with SSL. Share it anywhere.',        'thumb'=>'segment-boutique-hotels.jpg'],
                        ['num'=>'05','title'=>'Get paid',   'desc'=>'Guests pay by card, bank transfer, or USSD. Money lands in your account.', 'thumb'=>'segment-residential.jpg'],
                    ];
                @endphp
                @foreach ($steps as $step)
                    @php
                        $thumbPath = $step['thumb'] && file_exists(public_path("img/landing/{$step['thumb']}"))
                            ? "/img/landing/{$step['thumb']}"
                            : null;
                    @endphp
                    <li class="lp-step">
                        {{-- Per-row background image. Hidden by default;
                             CSS reveals it on hover. The dark overlay is
                             drawn inside .lp-step-bg via ::after so the
                             title/desc text stays readable. --}}
                        @if ($thumbPath)
                            <div class="lp-step-bg" style="background-image: url('{{ $thumbPath }}');"></div>
                        @endif
                        <span class="lp-step-num">{{ $step['num'] }}</span>
                        <h3 class="lp-step-title">{{ $step['title'] }}</h3>
                        <p class="lp-step-desc">{{ $step['desc'] }}</p>
                    </li>
                @endforeach
            </ol>

            {{-- After-the-process CTA. The reader just absorbed the five
                 steps; route them straight into the first one. Paired with
                 a quiet "Pricing first" secondary path for the buyer who
                 wants the number before the form. --}}
            <div class="lp-cta-row mt-12 flex flex-wrap items-center gap-5">
                <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                    Do it yourself
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>
                <a href="#pricing" class="text-[13.5px] underline" style="color: var(--color-ink-soft); text-underline-offset: 4px;">
                    See pricing first
                </a>
            </div>
        </div>
    </section>

    {{-- ─── Featured "Dream Stay" full-bleed card (dark photo) ─────── --}}
    <section class="lp-card lp-card-photo lp-featured relative" style="min-height: 480px;">
        @if (file_exists(public_path('img/landing/segment-boutique-hotels.jpg')))
            <img src="/img/landing/segment-boutique-hotels.jpg" alt="" class="absolute inset-0 h-full w-full object-cover" style="z-index: 0;" />
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(20,17,12,0.30) 0%, rgba(20,17,12,0.05) 40%, rgba(20,17,12,0.55) 100%); z-index: 1;"></div>
        @endif

        <div class="lp-featured-inner relative grid items-end gap-10 px-7 py-10 sm:px-12 sm:py-14 lg:px-16 lg:py-20 lg:grid-cols-[1.4fr_1fr]" style="z-index: 2; min-height: 480px;">
            <div>
                <span class="lp-eyebrow-strip" style="color: var(--color-accent);">
                    <span style="background: var(--color-accent);"></span>
                    Featured stay
                </span>
                <h2 class="lp-display mt-3 text-[clamp(40px,6vw,84px)]" style="color: var(--color-card); font-weight: 600; line-height: 1;">
                    Dream Stay
                </h2>
                <p class="mt-5 max-w-[540px] text-[15.5px] leading-[1.7]" style="color: rgba(244,239,230,0.85);">
                    Maitama Residences in Abuja runs five rooms through Keverna. Last quarter, 84% of
                    bookings came direct from Instagram and email. Not Airbnb. Zero commission paid.
                </p>
            </div>
            <div class="flex flex-col items-start gap-4 lg:items-end">
                <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                    Build yours
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>
                {{-- Softer alternative for the not-quite-ready browser:
                     keep walking the page rather than leave for /register. --}}
                <a href="#use-cases" class="text-[13.5px] underline" style="color: rgba(244,239,230,0.8); text-underline-offset: 4px; text-decoration-color: rgba(244,239,230,0.3);">
                    See more property types &nbsp;↓
                </a>
            </div>
        </div>
    </section>

    {{-- ─── Use cases (cream) ────────────────────────────────────────── --}}
    <section id="use-cases" class="lp-card lp-card-cream">
        <div class="lp-card-body">
            <div class="grid items-end gap-8 lg:grid-cols-[1fr_1fr] lg:gap-16">
                <div>
                    <span class="lp-eyebrow-strip">Who uses Keverna</span>
                    <h2 class="lp-display mt-4 text-[clamp(28px,4vw,46px)]" style="color: var(--color-ink); line-height: 1.06;">
                        Four kinds of operator.<br>One booking stack.
                    </h2>
                </div>
                <p class="text-[15.5px] leading-[1.65]" style="color: var(--color-ink-soft);">
                    Whether the booking is one night or one year, one room or one whole building,
                    Keverna gives you the same shape: page, calendar, payment, payout.
                </p>
            </div>

            <div class="mt-12 grid gap-6 md:grid-cols-2 lg:gap-8">
                @foreach ([
                    [
                        'serviced-apartments', 'Serviced apartments',
                        'Nightly and weekly. Multi-room properties in Lagos, Abuja, PH. Corporate stays, project teams, medical travel.',
                        ['Real-time availability', 'Multi-room rates', 'Direct Paystack payouts'],
                    ],
                    [
                        'workspaces', 'Co-working &amp; workspaces',
                        'Monthly memberships and desk reservations. Day passes optional. Renewals on autopilot via Paystack subscriptions.',
                        ['Recurring billing', 'Desk-level inventory', 'Member directory'],
                    ],
                    [
                        'boutique-hotels', 'Boutique hotels',
                        '10–50 rooms. Use Keverna as a lightweight PMS without paying ₦5M/year for the heavy ones.',
                        ['Room-level pricing', 'Operator dashboard', 'Mobile-first page'],
                    ],
                    [
                        'residential', 'Residential leases',
                        'Annual and multi-year. Display a unit, collect signed leases, schedule rent collection through one page.',
                        ['Annual rent collection', 'Lease document upload', 'Tenant self-service'],
                    ],
                ] as [$slug, $title, $body, $bullets])
                    <article class="lp-tile p-6 sm:p-7" style="background: var(--color-card-deep); border-radius: 16px;">
                        <div class="aspect-[16/10] w-full overflow-hidden" style="border-radius: 10px;">
                            @if (file_exists(public_path("img/landing/segment-{$slug}.jpg")))
                                <img src="/img/landing/segment-{{ $slug }}.jpg" alt="{!! $title !!}" class="h-full w-full object-cover" />
                            @else
                                <div class="lp-photo-stub h-full w-full">{{ strtoupper(str_replace('-', ' ', $slug)) }}</div>
                            @endif
                        </div>
                        <h3 class="lp-display mt-5 text-[22px]" style="color: var(--color-ink); font-weight: 600;">{!! $title !!}</h3>
                        <p class="mt-2 text-[14px] leading-[1.6]" style="color: var(--color-ink-soft);">{{ $body }}</p>
                        <ul class="mt-5 grid gap-1.5 text-[13px]" style="color: var(--color-ink);">
                            @foreach ($bullets as $b)
                                <li class="flex items-start gap-2.5">
                                    <span aria-hidden class="mt-[7px] inline-block h-[5px] w-[5px]" style="background: var(--color-accent-deep);"></span>
                                    <span>{{ $b }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>

            {{-- Catches the "I'm somewhere between two of these" reader. A
                 strip below the grid reads as confident, adding a 5th
                 tile would have mixed voices inside the grid. --}}
            <div class="mt-10 flex flex-col items-start justify-between gap-4 border-t pt-8 sm:flex-row sm:items-center" style="border-color: var(--color-line);">
                <p class="lp-display text-[18px] sm:text-[20px]" style="color: var(--color-ink); font-weight: 500; max-width: 560px;">
                    Not sure which one fits? Tell us about the property, we&rsquo;ll point you to the right setup.
                </p>
                <a href="mailto:hello@keverna.com?subject=Which%20setup%20fits%20my%20property%3F" class="lp-pill shrink-0">
                    Talk to us
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </a>
            </div>
        </div>
    </section>

    {{-- ─── Pricing (cream) ──────────────────────────────────────────────
         Four tiers by unit count. Same feature set across all tiers -
         price scales with portfolio size, not with capability. The
         "Growth" tier gets a subtle recommended treatment; it maps to
         the 6-20 unit band that most serious operators land in.
    --}}
    <section id="pricing" class="lp-card lp-card-cream">
        <div class="lp-card-body">

            {{-- Header row: eyebrow + headline on the left, the shared
                 explainer copy on the right so both read at a glance
                 before the eye drops into the tier grid. --}}
            <div class="grid items-end gap-10 lg:grid-cols-[1fr_1fr] lg:gap-16">
                <div>
                    <span class="lp-eyebrow-strip">Pricing</span>
                    <h2 class="lp-display mt-4 text-[clamp(28px,4vw,46px)]" style="color: var(--color-ink); line-height: 1.06;">
                        Pricing that<br>scales with you.
                    </h2>
                </div>
                <p class="text-[15px] leading-[1.7] max-w-[520px]" style="color: var(--color-ink-soft);">
                    Pay for what you run, not for a feature list. Every tier ships the complete product: branded
                    booking page, direct payouts, any length of stay on one inventory. You&rsquo;re billed by how many
                    units you list, never by which features you unlock.
                </p>
            </div>

            {{-- Three-column billing explainer strip. Answers the three
                 questions every operator asks in the first thirty seconds:
                 "how am I billed?" · "what do you take on card?" · "what
                 about cash?" Placed before the tier grid so those answers
                 frame every price the eye lands on next. --}}
            <div class="mt-10 grid gap-6 border-t pt-8 sm:grid-cols-3 sm:gap-10" style="border-color: var(--color-line);">
                @foreach ([
                    [
                        'eyebrow' => 'How you are billed',
                        'title'   => 'By units, not features',
                        'body'    => 'The price is set by how many rooms, desks, or apartments you list. Every operator runs the entire platform, from the first property to the largest portfolio.',
                    ],
                    [
                        'eyebrow' => 'Card payments',
                        'title'   => 'A flat two percent',
                        'body'    => 'When a guest pays by card through your page, Keverna takes a flat 2%. Nothing to configure, nothing hidden in the total.',
                    ],
                    [
                        'eyebrow' => 'Cash and transfers',
                        'title'   => 'No fee at all',
                        'body'    => 'Bookings you settle in person, by cash or bank transfer, stay with you in full. Keverna charges nothing on payments taken off the platform.',
                    ],
                ] as $col)
                    <div>
                        <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">{{ $col['eyebrow'] }}</p>
                        <p class="lp-display mt-2 text-[18px]" style="color: var(--color-ink); font-weight: 600;">{{ $col['title'] }}</p>
                        <p class="mt-2 text-[13.5px] leading-[1.6]" style="color: var(--color-ink-soft);">{{ $col['body'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Tier grid. 2-up on tablet, 4-up on desktop. Growth (index 1)
                 gets an accent border + "Most operators" ribbon; everything
                 else keeps the same card-deep background so the row reads
                 as a family, not a ladder of unequal cards. --}}
            @php
                // Tier data shared across the landing preview and the
                // dedicated /pricing page. Kept as a repeated array here
                // rather than a partial so this section stays legible in
                // one file; /pricing composes its own richer version.
                //
                // Each self-serve tier's CTA carries `?plan=<slug>` so
                // the Register livewire seeds unit_count to the tier's
                // mid-range default. Enterprise stays a mailto.
                $registerWithPlan = static function (string $planSlug): string {
                    $base = Route::has('register') ? route('register') : url('/register');
                    return $base.'?plan='.$planSlug;
                };
                $tiers = [
                    [
                        'eyebrow'   => 'First property',
                        'name'      => 'Starter',
                        'units'     => '1 – 5 units',
                        'price'     => '₦180,000',
                        'blurb'     => 'Built for your first property, or the operator testing the water with a small portfolio.',
                        'cta_label' => 'Start free trial',
                        'cta_href'  => $registerWithPlan('starter'),
                        'featured'  => false,
                    ],
                    [
                        'eyebrow'   => 'Most operators choose this',
                        'name'      => 'Growth',
                        'units'     => '6 – 20 units',
                        'price'     => '₦420,000',
                        'blurb'     => 'For the operator who has stopped tracking bookings on paper and started running a small team.',
                        'cta_label' => 'Start free trial',
                        'cta_href'  => $registerWithPlan('growth'),
                        'featured'  => true,
                    ],
                    [
                        'eyebrow'   => 'At real scale',
                        'name'      => 'Pro',
                        'units'     => '21 – 50 units',
                        'price'     => '₦900,000',
                        'blurb'     => 'For serviced-apartment portfolios, boutique hotels, and co-working spaces operating at real scale.',
                        'cta_label' => 'Start free trial',
                        'cta_href'  => $registerWithPlan('pro'),
                        'featured'  => false,
                    ],
                    [
                        'eyebrow'   => 'Groups and chains',
                        'name'      => 'Enterprise',
                        'units'     => '50+ units',
                        'price'     => 'From ₦1,800,000',
                        'blurb'     => 'For multi-property groups, chains, and residential operators that need custom terms.',
                        'cta_label' => 'Talk to us',
                        'cta_href'  => 'mailto:hello@keverna.com?subject=Enterprise%20pricing',
                        'featured'  => false,
                    ],
                ];
            @endphp

            <div class="lp-price-grid mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
                @foreach ($tiers as $tier)
                    <article
                        class="relative flex flex-col p-6 sm:p-7"
                        style="
                            background: var(--color-card-deep);
                            border-radius: 18px;
                            {{ $tier['featured'] ? 'outline: 2px solid var(--color-accent-deep); outline-offset: -2px;' : '' }}
                        "
                    >
                        <p class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                           style="color: {{ $tier['featured'] ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                            {{ $tier['eyebrow'] }}
                        </p>

                        <p class="lp-display mt-2 text-[22px]" style="color: var(--color-ink); font-weight: 600;">{{ $tier['name'] }}</p>
                        <p class="mt-1 lp-mono text-[11.5px] tracking-[0.14em] uppercase" style="color: var(--color-ink-mute);">{{ $tier['units'] }}</p>

                        <div class="mt-5">
                            <p class="lp-display lp-price-amount text-[34px] leading-none" style="color: var(--color-ink); font-weight: 600;">
                                {{ $tier['price'] }}
                            </p>
                            <p class="mt-1.5 text-[12.5px]" style="color: var(--color-ink-mute);">
                                / year &middot; all units on the tier
                            </p>
                        </div>

                        <p class="mt-4 text-[13.5px] leading-[1.6]" style="color: var(--color-ink-soft);">
                            {{ $tier['blurb'] }}
                        </p>

                        <a href="{{ $tier['cta_href'] }}" class="lp-pill mt-6 self-start">
                            {{ $tier['cta_label'] }}
                            <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </a>
                    </article>
                @endforeach
            </div>

            {{-- Shared "what's in every tier" strip. Beats repeating six
                 bullets across four cards; reinforces that price ≠ feature
                 gating. --}}
            <div class="mt-10 border-t pt-8" style="border-color: var(--color-line);">
                <p class="lp-mono text-[10.5px] tracking-[0.18em] uppercase" style="color: var(--color-ink-mute);">
                    Included in every tier
                </p>
                <ul class="mt-4 grid gap-2.5 text-[13.5px] sm:grid-cols-2 lg:grid-cols-3" style="color: var(--color-ink);">
                    @foreach ([
                        'Booking page on your-name.keverna.com (custom domain at ₦25,000 one-time)',
                        'Any duration, night, week, month, year, on one inventory',
                        'Room/desk/unit-level pricing, availability, and media',
                        'Direct payouts to your bank · card, transfer, USSD',
                        'Real-time dashboard, exports, email confirmations',
                        '99.5% uptime · email support · multi-tenant data isolation',
                    ] as $line)
                        <li class="flex gap-2.5 items-start">
                            <span aria-hidden class="mt-[7px] h-[5px] w-[5px] shrink-0" style="background: var(--color-accent-deep);"></span>
                            <span>{{ $line }}</span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-8 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <p class="lp-script text-[15px]" style="color: var(--color-accent-deep);">
                        14-day free trial on every tier &middot; no card needed
                    </p>
                    <div class="flex flex-wrap items-center gap-5 text-[13.5px]">
                        <a href="{{ url('/pricing') }}" style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                            See full pricing details &rarr;
                        </a>
                        <a href="mailto:hello@keverna.com?subject=Pricing%20question" style="color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px;">
                            Questions about pricing?
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ─── Big closing CTA (dark) ──────────────────────────────────── --}}
    <section class="lp-card lp-card-ink lp-closing relative" style="min-height: 360px;">
        <div class="lp-card-body relative grid items-center gap-10 lg:grid-cols-[1.3fr_1fr]">
            <div>
                <span class="lp-script text-[clamp(20px,2vw,26px)]" style="color: var(--color-accent);">Ready when you are,</span>
                <h2 class="lp-display mt-3 text-[clamp(34px,5vw,62px)]" style="color: var(--color-card); font-weight: 600; line-height: 1.04;">
                    Let&rsquo;s build the<br>booking layer for<br>your business.
                </h2>
                <p class="mt-6 max-w-[520px] text-[15.5px] leading-[1.7]" style="color: rgba(244,239,230,0.75);">
                    Fourteen days. No card. If it&rsquo;s not earning you direct bookings by week two,
                    just walk away.
                </p>
                <div class="lp-cta-row mt-8 flex flex-wrap items-center gap-4">
                    <a href="{{ Route::has('register') ? route('register') : '/register' }}" class="lp-pill">
                        Start free trial
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </a>
                    <a href="mailto:hello@keverna.com" class="text-[13.5px] underline" style="color: rgba(244,239,230,0.8); text-underline-offset: 4px;">
                        Talk to a human first
                    </a>
                </div>
            </div>

            <div class="lp-tile aspect-[4/5] w-full overflow-hidden" style="border-radius: 16px;">
                @if (file_exists(public_path('img/landing/segment-residential.jpg')))
                    <img src="/img/landing/segment-residential.jpg" alt="" class="h-full w-full object-cover" />
                @else
                    <div class="lp-photo-stub h-full w-full">Closing portrait</div>
                @endif
            </div>
        </div>
    </section>

    {{-- ─── Footer (cream) ──────────────────────────────────────────── --}}
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
                    <a href="#how"       class="hover:underline" style="text-underline-offset: 4px;">Process</a>
                    <a href="#use-cases" class="hover:underline" style="text-underline-offset: 4px;">Use cases</a>
                    <a href="#pricing"   class="hover:underline" style="text-underline-offset: 4px;">Pricing</a>
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

</body>
</html>
