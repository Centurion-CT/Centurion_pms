@php
    $pillArrow = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h8M7.5 3.5L11 7l-3.5 3.5"/></svg>';
@endphp

{{-- ─── Super admin sign-in ───────────────────────────────────────
     Same Keverna auth chrome as the tenant + central login pages
     (warm ink + aurora + grain + vignette + Fraunces hero + cream
     form card), but swaps the olive accent for the amber that
     signals "you are on the admin surface" everywhere else in the
     admin panel (see partials/notify + admin/layout topbar dot).
     Component + auth pipeline are untouched. -------------------- --}}
<div class="kv-auth kv-auth--admin">
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
        /* Admin surface takes amber where every other auth page takes
           olive. Kept the variable names the same so the rest of the
           file reads exactly like the operator surfaces. */
        --olive:      #FBBF24;   /* amber */
        --olive-d:    #D97706;   /* amber deep */
        --olive-in:   #7A4A0B;   /* amber ink (dark on cream) */
        --olive-soft: #FDE68A;   /* pale amber highlight */
        --olive-deep: #3B2A0B;   /* deep amber wash */

        min-height: 100vh;
        background: var(--ink-deep);
        color: var(--cream);
        font-family: 'Inter', system-ui, sans-serif;
        position: relative;
        overflow-x: hidden;
        overflow-y: auto;
    }

    /* Aurora, grain, vignette — same recipe as the operator auth
       pages, just recoloured through the --olive-* variables above. */
    .kv-auth-aurora {
        position: absolute; inset: 0; pointer-events: none;
        overflow: hidden; z-index: 0;
    }
    .kv-auth-aurora span {
        position: absolute;
        width: 720px; height: 720px;
        border-radius: 50%;
        filter: blur(120px);
        opacity: 0.6;
        mix-blend-mode: screen;
        will-change: transform;
    }
    .kv-auth-aurora .a1 {
        background: radial-gradient(circle, var(--olive) 0%, transparent 65%);
        top: -220px; left: -180px;
        animation: kv-drift-a 22s ease-in-out infinite alternate;
        opacity: 0.42;
    }
    .kv-auth-aurora .a2 {
        background: radial-gradient(circle, var(--olive-soft) 0%, transparent 65%);
        top: 30%; right: -260px;
        animation: kv-drift-b 26s ease-in-out infinite alternate;
        opacity: 0.28;
    }
    .kv-auth-aurora .a3 {
        background: radial-gradient(circle, var(--olive-deep) 0%, transparent 60%);
        bottom: -300px; left: 20%;
        animation: kv-drift-c 30s ease-in-out infinite alternate;
        opacity: 0.65;
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

    /* Layout scaffold */
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
            grid-template-columns: 1fr 460px;
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

    /* Editorial mono top / bottom strips */
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
    .kv-auth-line span { white-space: nowrap; }
    .kv-auth-line--top { padding-bottom: 20px; border-bottom: 1px solid rgba(247, 243, 234, 0.08); }
    .kv-auth-line--bot { padding-top: 20px; border-top: 1px solid rgba(247, 243, 234, 0.08); }
    .kv-auth-line a { color: rgba(247, 243, 234, 0.75); text-decoration: none; }
    .kv-auth-line a:hover { color: var(--cream); }

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
    .kv-auth-brand-tag {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10.5px; letter-spacing: 0.18em; text-transform: uppercase;
        color: rgba(247, 243, 234, 0.55);
        margin-left: 4px;
    }

    /* Amber LED right of the top strip. Signals "admin surface" the
       same way the sidebar dot signals it once you're inside. */
    .kv-auth-led {
        display: inline-flex; align-items: center; gap: 8px;
    }
    .kv-auth-led-dot {
        position: relative;
        width: 8px; height: 8px; border-radius: 999px;
        background: var(--olive);
        box-shadow: 0 0 12px rgba(251, 191, 36, 0.55);
    }
    .kv-auth-led-dot::before {
        content: ''; position: absolute; inset: -4px;
        border-radius: 999px;
        background: rgba(251, 191, 36, 0.28);
        animation: kv-admin-pulse 2200ms ease-in-out infinite;
    }
    @keyframes kv-admin-pulse {
        0%, 100% { transform: scale(1); opacity: 0.55; }
        50%      { transform: scale(1.7); opacity: 0; }
    }
    @media (prefers-reduced-motion: reduce) {
        .kv-auth-led-dot::before { animation: none; opacity: 0.35; }
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
        font-weight: 500; letter-spacing: -0.035em; line-height: 0.98;
        font-size: clamp(52px, 6.4vw, 108px);
        color: var(--cream);
        margin: 0;
        max-width: 12ch;
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

    /* Form card */
    .kv-auth-form-wrap {
        display: flex; align-items: center; justify-content: center;
        padding: 40px 0 0;
    }
    @media (min-width: 1024px) {
        .kv-auth-form-wrap { grid-area: form; padding: 0; }
    }
    .kv-form-card {
        width: 100%; max-width: 460px;
        background: var(--cream);
        color: var(--ink);
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
    .kv-form-eyebrow {
        display: inline-block;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10px; letter-spacing: 0.2em; text-transform: uppercase;
        color: var(--ink-mute);
    }
    .kv-form-title {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 600; letter-spacing: -0.025em; line-height: 1;
        font-size: 34px;
        color: var(--ink);
        margin-top: 8px;
    }
    .kv-form-sub {
        margin-top: 8px;
        font-size: 13.5px; line-height: 1.55;
        color: var(--ink-soft);
    }

    .kv-field { margin-top: 22px; position: relative; }
    .kv-field-label {
        display: flex; align-items: baseline; justify-content: space-between;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 9.5px; letter-spacing: 0.18em; text-transform: uppercase;
        color: var(--ink-mute);
        margin-bottom: 8px;
    }
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
        box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.22);
    }
    .kv-input.is-invalid {
        border-color: #C1583A;
        box-shadow: 0 0 0 3px rgba(193, 88, 58, 0.15);
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
    .kv-field-error {
        margin-top: 6px; font-size: 12px; color: #C1583A;
    }

    .kv-check-row {
        margin-top: 18px;
        display: flex; align-items: center; gap: 10px;
        font-size: 13px; color: var(--ink-soft);
        cursor: pointer;
    }
    .kv-check {
        width: 16px; height: 16px; border-radius: 5px;
        border: 1.5px solid var(--line-2); background: var(--paper);
        appearance: none;
        cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .kv-check:checked {
        background: var(--olive-in); border-color: var(--olive-in);
    }
    .kv-check:checked::after {
        content: '✓'; color: var(--cream); font-size: 12px; line-height: 1;
    }

    .kv-submit {
        margin-top: 22px;
        display: inline-flex; align-items: center; justify-content: center;
        gap: 12px; width: 100%;
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
    .kv-spinner-dot {
        width: 12px; height: 12px;
        border: 2px solid rgba(122, 74, 11, 0.35);
        border-top-color: var(--olive-in);
        border-radius: 999px;
        animation: kv-spin 700ms linear infinite;
    }
    @keyframes kv-spin { to { transform: rotate(360deg); } }

    /* Mobile polish, same recipe as central login. */
    @media (max-width: 1023px) {
        .kv-auth { overflow-x: hidden; max-width: 100vw; }
        .kv-auth-inner { min-width: 0; padding: 16px; }
        .kv-hero-title { font-size: 44px; line-height: 0.98; }
        .kv-hero-eyebrow { margin-bottom: 14px; }
        .kv-hero-sub { font-size: 14px; margin-top: 20px; }
        .kv-auth-hero { padding: 24px 0 0; }
        .kv-auth-form-wrap { padding: 24px 0 0; display: block; }
        .kv-auth-line--top, .kv-auth-line--bot { flex-wrap: wrap; row-gap: 6px; }
        .kv-auth-line span { white-space: normal; }
        .kv-auth-line--bot { font-size: 9.5px; }
        .kv-form-card { padding: 24px 20px 22px; max-width: calc(100vw - 32px); }
        .kv-form-title { font-size: 24px; }
        .kv-form-sub { font-size: 13px; }
        .kv-auth-brand-tag { display: none; }
    }
    @media (max-width: 380px) {
        .kv-hero-title { font-size: 38px; }
        .kv-form-card { padding: 22px 18px 20px; }
    }
</style>

    <div class="kv-auth-aurora" aria-hidden="true">
        <span class="a1"></span>
        <span class="a2"></span>
        <span class="a3"></span>
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
                Keverna<span class="kv-auth-brand-tag">Admin</span>
            </a>
            <span class="kv-auth-led" aria-hidden="true">
                <span class="kv-auth-led-dot"></span>
                <span>Restricted</span>
            </span>
        </div>

        <div class="kv-auth-hero">
            <span class="kv-hero-eyebrow">Platform team</span>
            <h1 class="kv-hero-title">
                Admin,&nbsp;<em>sign in.</em>
            </h1>
            <p class="kv-hero-sub">
                FregateLab platform surface. Tenants, subscriptions,
                revenue and governance live here. Everything you touch
                is signed and logged.
            </p>
        </div>

        <div class="kv-auth-form-wrap" data-testid="admin-login">
            <div class="kv-form-card">
                <span class="kv-form-eyebrow">Sign in</span>
                <h2 class="kv-form-title">Enter your details.</h2>
                <p class="kv-form-sub">
                    We keep sessions per device, TOTP-enrolled admins get prompted next.
                </p>

                <form wire:submit="authenticate">

                    <div class="kv-field">
                        <label for="admin-email" class="kv-field-label">
                            <span>Email</span>
                        </label>
                        <span class="kv-field-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2.5" y="4" width="15" height="12" rx="2"/>
                                <path d="M3 5l7 6 7-6"/>
                            </svg>
                        </span>
                        <input id="admin-email" type="email" wire:model="email" required autofocus
                               autocomplete="email"
                               data-testid="admin-email"
                               class="kv-input @error('email') is-invalid @enderror"
                               placeholder="you@fregatelab.com" />
                        @error('email')
                            <p data-error="email" class="kv-field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="kv-field" data-password-field>
                        <label for="admin-password" class="kv-field-label">
                            <span>Password</span>
                        </label>
                        <span class="kv-field-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="9" width="12" height="8" rx="2"/>
                                <path d="M7 9V6a3 3 0 0 1 6 0v3"/>
                            </svg>
                        </span>
                        <input id="admin-password" type="password" wire:model="password" required
                               autocomplete="current-password"
                               data-testid="admin-password"
                               class="kv-input @error('password') is-invalid @enderror" />
                        <button type="button" class="kv-field-toggle"
                                data-password-toggle aria-label="Show password" aria-pressed="false">
                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/>
                                <circle cx="10" cy="10" r="2.5"/>
                                <path class="kv-slash" d="M3 3l14 14"/>
                            </svg>
                        </button>
                        @error('password')
                            <p data-error="password" class="kv-field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <label class="kv-check-row">
                        <input type="checkbox" wire:model="remember" class="kv-check" />
                        Remember this device
                    </label>

                    <button type="submit" class="kv-submit"
                            data-testid="admin-login-submit"
                            wire:loading.attr="disabled" wire:target="authenticate">
                        <span wire:loading.remove wire:target="authenticate">Continue</span>
                        <span wire:loading wire:target="authenticate">Signing in…</span>
                        <span class="kv-submit-dot" aria-hidden="true">
                            <span wire:loading.remove wire:target="authenticate">{!! $pillArrow !!}</span>
                            <span wire:loading wire:target="authenticate" class="kv-spinner-dot"></span>
                        </span>
                    </button>
                </form>
            </div>
        </div>

        <div class="kv-auth-line kv-auth-line--bot">
            <span>Rate-limited &middot; IP + email throttle &middot; Session · 30m idle</span>
            <span>© {{ date('Y') }} Keverna</span>
        </div>

    </div>
</div>
