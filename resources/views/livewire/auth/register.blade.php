@php
    $pillArrow = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h8M7.5 3.5L11 7l-3.5 3.5"/></svg>';
    $isEnterprise = ! $derivedPlan->isSelfServe();
    [$rangeMin, $rangeMax] = $derivedPlan->unitRange();
    $rangeLabel = $rangeMax === null ? "{$rangeMin}+ units" : "{$rangeMin} – {$rangeMax} units";
@endphp

{{-- ─── Register redesign ────────────────────────────────────────
     Same visual language as /login: warm-ink canvas with a slow-
     drifting olive aurora, grain, vignette, editorial mono chrome
     top and bottom, hero display on the left, form card on the
     right.

     Difference: the form is a two-step flow.
       Step 1 — About you (name, email, phone, password)
       Step 2 — Your property (property name, unit count → tier
                chip, licence code)
     Step indicator lives at the top of the card so the operator
     always knows where they are. Enterprise (>=51 units) flips
     the step-2 primary action to a mailto so the flow doesn't
     dead-end at a "not self-serve" wall. ---------------------- --}}
<div class="kv-auth">
<style>
    .kv-auth {
        --ink:        #14110C;
        --ink-deep:   #0B0906;
        --ink-soft:   #4B4A46;
        --ink-mute:   #6E6C66;
        --cream:      #F7F3EA;
        --cream-2:    #EFE9DC;
        --paper:      #FFFFFF;
        --line:       rgba(20, 17, 12, 0.08);
        --line-2:     rgba(20, 17, 12, 0.14);
        --olive:      #ADCB52;
        --olive-d:    #8AAE3A;
        --olive-in:   #4B5C22;
        --olive-soft: #C9DE8A;
        --olive-deep: #2F3A18;

        margin: -14px;
        min-height: calc(100vh + 28px);
        background: var(--ink-deep);
        color: var(--cream);
        font-family: 'Inter', system-ui, sans-serif;
        position: relative;
        overflow: hidden;
    }
    @media (min-width: 640px) {
        .kv-auth { margin: -18px; min-height: calc(100vh + 36px); }
    }

    /* Aurora */
    .kv-auth-aurora {
        position: absolute; inset: 0; pointer-events: none;
        overflow: hidden; z-index: 0;
    }
    .kv-auth-aurora span {
        position: absolute; width: 720px; height: 720px;
        border-radius: 50%; filter: blur(120px);
        mix-blend-mode: screen; will-change: transform;
    }
    .kv-auth-aurora .a1 {
        background: radial-gradient(circle, var(--olive) 0%, transparent 65%);
        top: -220px; left: -180px;
        animation: kv-drift-a 22s ease-in-out infinite alternate;
        opacity: 0.55;
    }
    .kv-auth-aurora .a2 {
        background: radial-gradient(circle, var(--olive-soft) 0%, transparent 65%);
        top: 30%; right: -260px;
        animation: kv-drift-b 26s ease-in-out infinite alternate;
        opacity: 0.35;
    }
    .kv-auth-aurora .a3 {
        background: radial-gradient(circle, var(--olive-deep) 0%, transparent 60%);
        bottom: -300px; left: 20%;
        animation: kv-drift-c 30s ease-in-out infinite alternate;
        opacity: 0.7;
    }
    @keyframes kv-drift-a {
        0%   { transform: translate3d(0, 0, 0) scale(1); }
        100% { transform: translate3d(140px, 80px, 0) scale(1.15); }
    }
    @keyframes kv-drift-b {
        0%   { transform: translate3d(0, 0, 0) scale(1); }
        100% { transform: translate3d(-160px, -120px, 0) scale(1.1); }
    }
    @keyframes kv-drift-c {
        0%   { transform: translate3d(0, 0, 0) scale(1); }
        100% { transform: translate3d(120px, -160px, 0) scale(1.2); }
    }
    @media (prefers-reduced-motion: reduce) {
        .kv-auth-aurora span { animation: none; }
    }
    .kv-auth-grain {
        position: absolute; inset: 0; pointer-events: none; z-index: 1;
        opacity: 0.35;
        background-image:
            url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='140' height='140'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.96  0 0 0 0 0.95  0 0 0 0 0.92  0 0 0 0.14 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    }
    .kv-auth-vignette {
        position: absolute; inset: 0; pointer-events: none; z-index: 2;
        background:
            radial-gradient(80% 60% at 50% 100%, rgba(11, 9, 6, 0.75) 0%, transparent 55%),
            radial-gradient(70% 60% at 50% 0%,   rgba(11, 9, 6, 0.55) 0%, transparent 60%);
    }

    /* Grid */
    .kv-auth-inner {
        position: relative; z-index: 3;
        display: grid;
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr auto;
        min-height: 100vh;
        padding: 20px;
    }
    @media (min-width: 1024px) {
        .kv-auth-inner {
            padding: 32px 40px;
            grid-template-columns: 1fr 480px;
            grid-template-rows: auto 1fr auto;
            grid-template-areas:
                "top top"
                "hero form"
                "bot bot";
            column-gap: 60px;
            max-width: 1440px;
            margin: 0 auto;
        }
    }
    @media (min-width: 1200px) {
        .kv-auth-inner { padding: 44px 64px; column-gap: 80px; }
    }

    /* Editorial mono lines */
    .kv-auth-line {
        display: flex; align-items: center; justify-content: space-between;
        gap: 20px;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10.5px; letter-spacing: 0.2em; text-transform: uppercase;
        color: rgba(247, 243, 234, 0.55);
    }
    @media (min-width: 1024px) {
        .kv-auth-line--top { grid-area: top; }
        .kv-auth-line--bot { grid-area: bot; margin-top: 40px; }
    }
    .kv-auth-line--top { padding-bottom: 20px; border-bottom: 1px solid rgba(247, 243, 234, 0.08); }
    .kv-auth-line--bot { padding-top: 20px; border-top: 1px solid rgba(247, 243, 234, 0.08); }
    .kv-auth-line a { color: rgba(247, 243, 234, 0.75); text-decoration: none; }
    .kv-auth-line a:hover { color: var(--cream); }
    .kv-auth-line span { white-space: nowrap; }

    .kv-auth-brand {
        display: inline-flex; align-items: center; gap: 10px;
        font-family: 'Fraunces', Georgia, serif;
        font-size: 17px; font-weight: 600; color: var(--cream);
        text-decoration: none; letter-spacing: -0.01em;
        text-transform: none;
    }
    .kv-auth-brand-mark {
        width: 26px; height: 26px; border-radius: 7px;
        background: var(--cream); color: var(--ink);
        display: inline-flex; align-items: center; justify-content: center;
    }

    /* Hero */
    .kv-auth-hero {
        display: flex; flex-direction: column; justify-content: center;
        padding: 48px 0 0;
    }
    @media (min-width: 1024px) {
        .kv-auth-hero { grid-area: hero; padding: 40px 0; }
    }
    .kv-hero-eyebrow {
        display: inline-block;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10.5px; letter-spacing: 0.22em; text-transform: uppercase;
        color: var(--olive);
        margin-bottom: 24px;
    }
    .kv-hero-eyebrow::before {
        content: ''; display: inline-block;
        width: 6px; height: 6px; border-radius: 999px;
        background: var(--olive);
        margin-right: 10px; vertical-align: middle;
    }
    .kv-hero-title {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 500; letter-spacing: -0.038em; line-height: 0.94;
        font-size: clamp(56px, 9.5vw, 140px);
        color: var(--cream);
        margin: 0;
    }
    .kv-hero-title em {
        font-style: italic; font-weight: 500;
        color: var(--olive);
    }
    .kv-hero-sub {
        margin-top: 32px; max-width: 460px;
        font-size: 15.5px; line-height: 1.6;
        color: rgba(247, 243, 234, 0.72);
    }
    /* Bullets under the sub — visible on desktop only, keeps mobile
       lean so the form is above the fold. */
    .kv-hero-bullets {
        display: none;
    }
    @media (min-width: 1024px) {
        .kv-hero-bullets {
            display: grid; gap: 12px;
            margin-top: 32px; max-width: 460px;
            font-size: 14px; color: rgba(247, 243, 234, 0.78);
        }
    }
    .kv-hero-bullet {
        display: flex; align-items: baseline; gap: 12px;
    }
    .kv-hero-bullet-dot {
        display: inline-block; width: 6px; height: 6px; border-radius: 999px;
        background: var(--olive); flex-shrink: 0;
    }

    /* Form card */
    .kv-auth-form-wrap {
        display: flex; align-items: center; justify-content: center;
        padding: 40px 0 0;
    }
    @media (min-width: 1024px) {
        .kv-auth-form-wrap { grid-area: form; padding: 0; }
    }
    .kv-form-card {
        width: 100%; max-width: 480px;
        background: var(--cream); color: var(--ink);
        border-radius: 20px;
        padding: 34px 30px 30px;
        box-shadow:
            0 40px 100px -30px rgba(0, 0, 0, 0.6),
            0 20px 40px -20px rgba(0, 0, 0, 0.4),
            0 0 0 1px rgba(247, 243, 234, 0.06);
    }
    @media (min-width: 1024px) {
        .kv-form-card { padding: 38px 36px 32px; }
    }

    /* Step indicator: two dots in a row, filled state = olive. */
    .kv-step-strip {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; margin-bottom: 18px;
    }
    .kv-step-dots {
        display: flex; align-items: center; gap: 8px;
    }
    .kv-step-dot {
        width: 22px; height: 4px; border-radius: 999px;
        background: var(--line-2); transition: background 200ms;
    }
    .kv-step-dot.is-current { background: var(--olive-in); }
    .kv-step-count {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10px; letter-spacing: 0.18em; text-transform: uppercase;
        color: var(--ink-mute);
    }

    .kv-form-eyebrow {
        display: inline-block;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10px; letter-spacing: 0.2em; text-transform: uppercase;
        color: var(--ink-mute);
    }
    .kv-form-title {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 600; letter-spacing: -0.025em; line-height: 1;
        font-size: 30px;
        color: var(--ink);
        margin-top: 6px;
    }
    .kv-form-sub {
        margin-top: 8px;
        font-size: 13.5px; line-height: 1.55;
        color: var(--ink-soft);
    }

    /* Fields */
    .kv-field { margin-top: 20px; position: relative; }
    .kv-field-label {
        display: flex; align-items: baseline; justify-content: space-between;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 9.5px; letter-spacing: 0.18em; text-transform: uppercase;
        color: var(--ink-mute);
        margin-bottom: 8px;
    }
    .kv-field-label a {
        font-family: 'Inter', system-ui, sans-serif;
        text-transform: none; letter-spacing: 0;
        color: var(--olive-in);
        font-size: 12px; font-weight: 500;
        text-decoration: none;
    }
    .kv-field-label a:hover { text-decoration: underline; text-underline-offset: 3px; }
    .kv-input {
        width: 100%; padding: 13px 14px 13px 44px;
        font-family: inherit; font-size: 14.5px;
        background: var(--paper);
        color: var(--ink);
        border: 1px solid var(--line-2);
        border-radius: 12px;
        transition: border-color 150ms, box-shadow 150ms;
    }
    .kv-input:focus {
        outline: none;
        border-color: var(--olive-d);
        box-shadow: 0 0 0 3px rgba(173, 203, 82, 0.22);
    }
    .kv-input.is-invalid {
        border-color: #C1583A;
        box-shadow: 0 0 0 3px rgba(193, 88, 58, 0.15);
    }
    .kv-input--mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        letter-spacing: 0.05em;
    }
    .kv-field-icon {
        position: absolute; left: 14px; top: 40px;
        width: 20px; height: 20px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--ink-mute);
        pointer-events: none;
    }
    .kv-field-toggle {
        position: absolute; right: 8px; top: 32px;
        width: 34px; height: 34px;
        display: inline-flex; align-items: center; justify-content: center;
        background: transparent; border: 0; cursor: pointer;
        color: var(--ink-mute); border-radius: 8px;
    }
    .kv-field-toggle:hover { background: var(--cream-2); color: var(--ink); }
    .kv-field-toggle .kv-slash { opacity: 0; transition: opacity 150ms; }
    .kv-field-toggle[aria-pressed="true"] .kv-slash { opacity: 1; }
    .kv-field-help {
        margin-top: 6px; font-size: 12px; color: var(--ink-mute);
    }
    .kv-field-help a { color: var(--ink); text-decoration: underline; text-underline-offset: 3px; }
    .kv-field-error {
        margin-top: 6px; font-size: 12px; color: #C1583A;
    }

    /* Two-column mini grid for password + confirm on step 1 */
    .kv-field-grid {
        display: grid; gap: 18px; margin-top: 20px;
    }
    @media (min-width: 520px) {
        .kv-field-grid { grid-template-columns: 1fr 1fr; }
    }
    .kv-field-grid > .kv-field { margin-top: 0; }

    /* Tier chip (step 2) */
    .kv-tier-chip {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 14px 16px;
        background: var(--cream-2);
        border: 1px solid var(--line);
        border-radius: 12px;
        margin-top: 20px;
    }
    .kv-tier-chip-name {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 17px; font-weight: 600; color: var(--ink);
        letter-spacing: -0.01em;
    }
    .kv-tier-chip-range {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase;
        color: var(--ink-mute);
        margin-top: 2px;
    }
    .kv-tier-chip-price {
        font-family: 'Fraunces', Georgia, serif;
        font-size: 17px; font-weight: 600; color: var(--ink);
        text-align: right;
    }
    .kv-tier-chip-price small {
        display: block; font-family: 'Inter', system-ui, sans-serif;
        font-size: 11px; font-weight: 400;
        color: var(--ink-mute);
        margin-top: 2px;
    }
    .kv-tier-chip-cta {
        font-size: 13px; color: var(--olive-in); font-weight: 500;
        text-decoration: none;
    }
    .kv-tier-chip-cta:hover { text-decoration: underline; text-underline-offset: 3px; }

    /* Buttons */
    .kv-btn-row {
        margin-top: 22px; display: flex; align-items: center; gap: 10px;
    }
    .kv-submit {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 12px; flex: 1;
        padding: 14px 22px;
        background: var(--ink); color: var(--cream);
        font-family: inherit; font-size: 14.5px; font-weight: 500;
        border: 0; border-radius: 999px;
        cursor: pointer;
        transition: background 150ms, transform 100ms;
    }
    .kv-submit:hover { background: #24201A; }
    .kv-submit:active { transform: translateY(1px); }
    .kv-submit:disabled { opacity: 0.7; cursor: default; }
    .kv-submit-dot {
        width: 26px; height: 26px; border-radius: 999px;
        background: var(--olive); color: var(--olive-in);
        display: inline-flex; align-items: center; justify-content: center;
    }
    .kv-back {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 12px 18px;
        background: transparent;
        color: var(--ink-soft);
        font-family: inherit; font-size: 13.5px; font-weight: 500;
        border: 1px solid var(--line-2); border-radius: 999px;
        cursor: pointer;
    }
    .kv-back:hover { color: var(--ink); border-color: var(--line-2); background: var(--cream-2); }

    .kv-spinner-dot {
        width: 12px; height: 12px;
        border: 2px solid rgba(75, 92, 34, 0.35);
        border-top-color: var(--olive-in);
        border-radius: 999px;
        animation: kv-spin 700ms linear infinite;
    }
    @keyframes kv-spin { to { transform: rotate(360deg); } }

    .kv-form-foot {
        margin-top: 22px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 20px; padding-top: 18px;
        border-top: 1px solid var(--line);
        font-size: 12px; color: var(--ink-mute);
    }
    .kv-form-foot a { color: var(--ink); font-weight: 500; text-decoration: none; }
    .kv-form-foot a:hover { text-decoration: underline; text-underline-offset: 3px; }

    /* Mobile display tuning */
    @media (max-width: 1023px) {
        .kv-auth { overflow-x: hidden; max-width: 100vw; }
        .kv-auth-inner { min-width: 0; padding: 16px; }
        .kv-hero-title { font-size: 44px; line-height: 0.98; }
        .kv-hero-eyebrow { margin-bottom: 14px; }
        .kv-hero-sub { font-size: 14px; margin-top: 20px; }
        .kv-auth-hero { padding: 24px 0 0; }
        .kv-auth-form-wrap { padding: 24px 0 0; display: block; } .kv-auth-line--top, .kv-auth-line--bot { flex-wrap: wrap; row-gap: 6px; } .kv-auth-line span { white-space: normal; } .kv-auth-line--bot { font-size: 9.5px; }
        .kv-form-card { padding: 24px 20px 22px; max-width: calc(100vw - 32px); }
        .kv-form-title { font-size: 24px; }
        .kv-form-sub { font-size: 13px; }
        .kv-hero-quote, .kv-hero-bullets, .kv-hero-tips { display: none; }
    }
    @media (max-width: 380px) {
        .kv-hero-title { font-size: 38px; }
        .kv-form-card { padding: 22px 18px 20px; }
    }
</style>

    <div class="kv-auth-aurora" aria-hidden="true">
        <span class="a1"></span><span class="a2"></span><span class="a3"></span>
    </div>
    <div class="kv-auth-grain" aria-hidden="true"></div>
    <div class="kv-auth-vignette" aria-hidden="true"></div>

    <div class="kv-auth-inner">

        <div class="kv-auth-line kv-auth-line--top">
            <a href="/" class="kv-auth-brand">
                <span class="kv-auth-brand-mark" aria-hidden="true">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                    </svg>
                </span>
                Keverna
            </a>
            <span class="kv-auth-line-right" aria-hidden="true">
                @if (Route::has('login'))
                    Already have an account? <a href="{{ route('login') }}" style="color: var(--cream); margin-left: 6px;">Sign in →</a>
                @else
                    Sign in on your property URL &middot; e.g. your-name.keverna.com
                @endif
            </span>
            @include('partials.auth-mobile-menu')
        </div>

        <div class="kv-auth-hero">
            <span class="kv-hero-eyebrow">Get started</span>
            <h1 class="kv-hero-title">
                Set&nbsp;<em>up.</em>
            </h1>
            <p class="kv-hero-sub">
                Fourteen days free. No card at the door.
            </p>
        </div>

        <div class="kv-auth-form-wrap">
            <div class="kv-form-card">

                {{-- Step strip: two dashes, filled state = olive. --}}
                <div class="kv-step-strip">
                    <div class="kv-step-dots" aria-hidden="true">
                        <span class="kv-step-dot {{ $step >= 1 ? 'is-current' : '' }}"></span>
                        <span class="kv-step-dot {{ $step >= 2 ? 'is-current' : '' }}"></span>
                    </div>
                    <span class="kv-step-count">Step {{ $step }} of 2</span>
                </div>

                @if ($step === 1)
                    {{-- ── Step 1: About you ─────────────────────────── --}}
                    <span class="kv-form-eyebrow">About you</span>
                    <h2 class="kv-form-title">Who's running the show?</h2>
                    <p class="kv-form-sub">
                        A few details about you and your work email. We'll
                        cover the property in the next step.
                    </p>

                    <form wire:submit.prevent="nextStep" data-testid="register-form-step1">

                        <div class="kv-field">
                            <label for="reg-name" class="kv-field-label"><span>Your name</span></label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="10" cy="7" r="3.5"/>
                                    <path d="M3 17c1-3.5 4-5.5 7-5.5s6 2 7 5.5"/>
                                </svg>
                            </span>
                            <input id="reg-name" type="text" wire:model="name" required autocomplete="name"
                                   class="kv-input @error('name') is-invalid @enderror"
                                   placeholder="Amara Okafor" />
                            @error('name') <p data-error="name" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="kv-field">
                            <label for="reg-email" class="kv-field-label"><span>Work email</span></label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2.5" y="4" width="15" height="12" rx="2"/>
                                    <path d="M3 5l7 6 7-6"/>
                                </svg>
                            </span>
                            <input id="reg-email" type="email" wire:model="email" required autocomplete="email"
                                   class="kv-input @error('email') is-invalid @enderror"
                                   placeholder="you@yourproperty.com" />
                            @error('email') <p data-error="email" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="kv-field">
                            <label for="reg-phone" class="kv-field-label">
                                <span>Phone</span>
                                <span style="text-transform: none; letter-spacing: 0; font-family: 'Inter', sans-serif; color: var(--ink-mute); font-size: 11.5px;">optional</span>
                            </label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M4 5c0-1 1-2 2-2h1l2 4-2 1a10 10 0 0 0 5 5l1-2 4 2v1c0 1-1 2-2 2A14 14 0 0 1 4 5z"/>
                                </svg>
                            </span>
                            <input id="reg-phone" type="tel" wire:model="phone" autocomplete="tel"
                                   class="kv-input @error('phone') is-invalid @enderror"
                                   placeholder="+234 800 000 0000" />
                            @error('phone') <p data-error="phone" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="kv-field-grid">
                            <div class="kv-field" data-password-field>
                                <label for="reg-password" class="kv-field-label"><span>Password</span></label>
                                <span class="kv-field-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="9" width="12" height="8" rx="2"/>
                                        <path d="M7 9V6a3 3 0 0 1 6 0v3"/>
                                    </svg>
                                </span>
                                <input id="reg-password" type="password" wire:model="password" required
                                       autocomplete="new-password"
                                       class="kv-input @error('password') is-invalid @enderror"
                                       placeholder="8+ characters" />
                                <button type="button" class="kv-field-toggle"
                                        data-password-toggle aria-label="Show password" aria-pressed="false">
                                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/>
                                        <circle cx="10" cy="10" r="2.5"/>
                                        <path class="kv-slash" d="M3 3l14 14"/>
                                    </svg>
                                </button>
                                @error('password') <p data-error="password" class="kv-field-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="kv-field" data-password-field>
                                <label for="reg-password-confirm" class="kv-field-label"><span>Confirm</span></label>
                                <span class="kv-field-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="9" width="12" height="8" rx="2"/>
                                        <path d="M7 9V6a3 3 0 0 1 6 0v3"/>
                                    </svg>
                                </span>
                                <input id="reg-password-confirm" type="password" wire:model="password_confirmation" required
                                       autocomplete="new-password"
                                       class="kv-input"
                                       placeholder="Repeat" />
                                <button type="button" class="kv-field-toggle"
                                        data-password-toggle aria-label="Show password" aria-pressed="false">
                                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/>
                                        <circle cx="10" cy="10" r="2.5"/>
                                        <path class="kv-slash" d="M3 3l14 14"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="kv-btn-row">
                            <button type="submit" class="kv-submit"
                                    wire:loading.attr="disabled" wire:target="nextStep">
                                <span wire:loading.remove wire:target="nextStep">Continue</span>
                                <span wire:loading wire:target="nextStep">Checking…</span>
                                <span class="kv-submit-dot" aria-hidden="true">
                                    <span wire:loading.remove wire:target="nextStep">{!! $pillArrow !!}</span>
                                    <span wire:loading wire:target="nextStep" class="kv-spinner-dot"></span>
                                </span>
                            </button>
                        </div>

                        <div class="kv-form-foot">
                            <span>
                                Already registered?
                                @if (Route::has('login'))
                                    <a href="{{ route('login') }}">Sign in →</a>
                                @else
                                    <a href="/">Back to landing →</a>
                                @endif
                            </span>
                            <span style="font-family: ui-monospace, monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase;">
                                1 / 2
                            </span>
                        </div>
                    </form>
                @else
                    {{-- ── Step 2: Your property ─────────────────────── --}}
                    <span class="kv-form-eyebrow">Your property</span>
                    <h2 class="kv-form-title">What are you setting up?</h2>
                    <p class="kv-form-sub">
                        Property name and the number of units go in here.
                        We'll pick the tier for you.
                    </p>

                    <form wire:submit="register" data-testid="register-form">

                        <div class="kv-field">
                            <label for="reg-property" class="kv-field-label"><span>Property name</span></label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 17V8l7-4 7 4v9M3 17h14M7 17v-4h6v4"/>
                                </svg>
                            </span>
                            <input id="reg-property" type="text" wire:model="property_name" required
                                   class="kv-input @error('property_name') is-invalid @enderror"
                                   placeholder="Maitama Residences" />
                            <p class="kv-field-help">This becomes the title on your booking page.</p>
                            @error('property_name') <p data-error="property_name" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="kv-field">
                            <label for="reg-units" class="kv-field-label"><span>How many units?</span></label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="4" width="14" height="12" rx="1"/>
                                    <path d="M3 8h14M7 12h6"/>
                                </svg>
                            </span>
                            <input id="reg-units" type="number" min="1" max="9999" step="1"
                                   wire:model.live.debounce.400ms="unit_count" required
                                   class="kv-input @error('unit_count') is-invalid @enderror"
                                   placeholder="e.g. 8" />
                            <p class="kv-field-help">Rooms, desks, or apartments. You can change this later.</p>
                            @error('unit_count') <p data-error="unit_count" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        {{-- Tier chip driven live off unit_count. --}}
                        <div class="kv-tier-chip" data-testid="register-tier-chip"
                             data-plan="{{ strtolower($derivedPlan->name) }}">
                            <div>
                                <div class="kv-tier-chip-name">
                                    {{ $derivedPlan->displayName() }}
                                </div>
                                <div class="kv-tier-chip-range">
                                    {{ $rangeLabel }}
                                </div>
                            </div>
                            <div>
                                @if ($isEnterprise)
                                    <div class="kv-tier-chip-price">Custom</div>
                                    <a href="mailto:hello@keverna.com?subject=Enterprise%20registration"
                                       class="kv-tier-chip-cta"
                                       style="display: block; margin-top: 4px; text-align: right;">
                                        Talk to us →
                                    </a>
                                @else
                                    <div class="kv-tier-chip-price">
                                        ₦{{ number_format($derivedPlan->amountNgn()) }}
                                        <small>/ year after trial</small>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="kv-field">
                            <label for="reg-license" class="kv-field-label">
                                <span>Licence code</span>
                            </label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M8 12l-3 3-1-1 3-3M12 8l4-4M4 13l7-7 3 3-7 7z"/>
                                </svg>
                            </span>
                            <input id="reg-license" type="text" wire:model="license_code" required
                                   class="kv-input kv-input--mono @error('license_code') is-invalid @enderror"
                                   placeholder="KV-XXXX-XXXX-XXXX"
                                   autocapitalize="characters" autocomplete="off"
                                   data-testid="register-license-code" />
                            <p class="kv-field-help">
                                Received via WhatsApp after your demo.
                                <a href="{{ route('demo') }}">Book a 15-min demo →</a>
                            </p>
                            @error('license_code') <p data-error="license_code" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="kv-btn-row">
                            <button type="button" wire:click="prevStep" class="kv-back">
                                ← Back
                            </button>
                            @if ($isEnterprise)
                                <a href="mailto:hello@keverna.com?subject=Enterprise%20registration" class="kv-submit"
                                   style="text-decoration: none;">
                                    <span>Talk to us</span>
                                    <span class="kv-submit-dot" aria-hidden="true">{!! $pillArrow !!}</span>
                                </a>
                            @else
                                <button type="submit" class="kv-submit"
                                        wire:loading.attr="disabled" wire:target="register">
                                    <span wire:loading.remove wire:target="register">Start free trial</span>
                                    <span wire:loading wire:target="register">Setting up…</span>
                                    <span class="kv-submit-dot" aria-hidden="true">
                                        <span wire:loading.remove wire:target="register">{!! $pillArrow !!}</span>
                                        <span wire:loading wire:target="register" class="kv-spinner-dot"></span>
                                    </span>
                                </button>
                            @endif
                        </div>

                        <div class="kv-form-foot">
                            <span>
                                By starting, you agree to our
                                <a href="{{ route('legal.terms') }}">Terms</a>
                                and
                                <a href="{{ route('legal.privacy') }}">Privacy</a>.
                            </span>
                            <span style="font-family: ui-monospace, monospace; font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase;">
                                2 / 2
                            </span>
                        </div>
                    </form>
                @endif

            </div>
        </div>

        <div class="kv-auth-line kv-auth-line--bot">
            <span>One property, at a time &middot; Fourteen-day free trial &middot; No card required</span>
            <span><a href="{{ route('legal.privacy') }}">Privacy</a> &middot; <a href="{{ route('legal.terms') }}">Terms</a></span>
        </div>

    </div>
</div>
