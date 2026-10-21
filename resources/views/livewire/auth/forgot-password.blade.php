@php
    $pillArrow = '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h8M7.5 3.5L11 7l-3.5 3.5"/></svg>';
    $tenant = \App\Models\Tenant::current();
    $tenantDisplayName = $tenant?->name ?? 'Your property';
    $sent = $status !== '';
@endphp

{{-- ─── Property (tenant) forgot password ─────────────────────────
     Same visual language as the property /login and the central
     /password/forgot: ink canvas, brand-blue aurora, grain, vignette,
     editorial mono lines top and bottom, big Fraunces display on the
     left, cream form card on the right.

     Two states in the form card:
       - Ask for email (default)
       - "Check your inbox" success once $status has been populated by
         the Livewire component's broker call.
--}}
<div class="kv-auth">
<style>
    .kv-auth {
        --ink:        #0F151C;
        --ink-deep:   #070B11;
        --ink-soft:   #4B4A46;
        --ink-mute:   #6E6C66;
        --cream:      #F7F3EA;
        --cream-2:    #EFE9DC;
        --paper:      #FFFFFF;
        --line:       rgba(20, 17, 12, 0.08);
        --line-2:     rgba(20, 17, 12, 0.14);
        /* Brand accent (config/brand.php) — Centurion blue. */
        --accent:        #336799;
        --accent-d:      #285480;
        --accent-in:     #336799;   /* on cream: links, checks (5.5:1) */
        --accent-bright: #8DB6DE;   /* text on the dark canvas */
        --accent-soft:   #9DBFE0;
        --accent-deep:   #13304A;

        min-height: 100vh;
        background: var(--ink-deep);
        color: var(--cream);
        font-family: 'Inter', system-ui, sans-serif;
        position: relative;
        overflow: hidden;
    }

    .kv-auth-aurora { position: absolute; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
    .kv-auth-aurora span {
        position: absolute; width: 720px; height: 720px;
        border-radius: 50%; filter: blur(120px);
        mix-blend-mode: screen; will-change: transform;
    }
    .kv-auth-aurora .a1 { background: radial-gradient(circle, var(--accent) 0%, transparent 65%); top: -220px; left: -180px; animation: kv-drift-a 22s ease-in-out infinite alternate; opacity: 0.55; }
    .kv-auth-aurora .a2 { background: radial-gradient(circle, var(--accent-soft) 0%, transparent 65%); top: 30%; right: -260px; animation: kv-drift-b 26s ease-in-out infinite alternate; opacity: 0.35; }
    .kv-auth-aurora .a3 { background: radial-gradient(circle, var(--accent-deep) 0%, transparent 60%); bottom: -300px; left: 20%; animation: kv-drift-c 30s ease-in-out infinite alternate; opacity: 0.7; }
    @keyframes kv-drift-a { 0% { transform: translate3d(0,0,0) scale(1); } 100% { transform: translate3d(140px,80px,0) scale(1.15); } }
    @keyframes kv-drift-b { 0% { transform: translate3d(0,0,0) scale(1); } 100% { transform: translate3d(-160px,-120px,0) scale(1.1); } }
    @keyframes kv-drift-c { 0% { transform: translate3d(0,0,0) scale(1); } 100% { transform: translate3d(120px,-160px,0) scale(1.2); } }
    @media (prefers-reduced-motion: reduce) { .kv-auth-aurora span { animation: none; } }
    .kv-auth-grain {
        position: absolute; inset: 0; pointer-events: none; z-index: 1; opacity: 0.35;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='140' height='140'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.96  0 0 0 0 0.95  0 0 0 0 0.92  0 0 0 0.14 0'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    }
    .kv-auth-vignette {
        position: absolute; inset: 0; pointer-events: none; z-index: 2;
        background:
            radial-gradient(80% 60% at 50% 100%, rgba(7, 11, 17, 0.75) 0%, transparent 55%),
            radial-gradient(70% 60% at 50% 0%,   rgba(7, 11, 17, 0.55) 0%, transparent 60%);
    }

    .kv-auth-inner {
        position: relative; z-index: 3;
        display: grid; grid-template-columns: 1fr; grid-template-rows: auto 1fr auto;
        min-height: 100vh; padding: 20px;
    }
    @media (min-width: 1024px) {
        .kv-auth-inner {
            padding: 32px 40px;
            grid-template-columns: 1fr 460px;
            grid-template-areas: "top top" "hero form" "bot bot";
            column-gap: 60px; max-width: 1440px; margin: 0 auto;
        }
    }
    @media (min-width: 1200px) { .kv-auth-inner { padding: 44px 64px; column-gap: 80px; } }

    .kv-auth-line {
        display: flex; align-items: center; justify-content: space-between; gap: 20px;
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
        text-decoration: none; letter-spacing: -0.01em; text-transform: none;
        min-width: 0;
    }
    .kv-auth-brand-mark {
        width: 26px; height: 26px; border-radius: 7px;
        background: var(--cream); color: var(--ink);
        display: inline-flex; align-items: center; justify-content: center;
        padding: 3px;
        flex-shrink: 0;
    }
    .kv-auth-brand-name {
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        max-width: 320px;
    }

    .kv-auth-hero { display: flex; flex-direction: column; justify-content: center; padding: 48px 0 0; }
    @media (min-width: 1024px) { .kv-auth-hero { grid-area: hero; padding: 40px 0; } }
    .kv-hero-eyebrow {
        display: inline-block;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10.5px; letter-spacing: 0.22em; text-transform: uppercase;
        color: var(--accent-bright); margin-bottom: 24px;
    }
    .kv-hero-eyebrow::before {
        content: ''; display: inline-block;
        width: 6px; height: 6px; border-radius: 999px;
        background: var(--accent);
        margin-right: 10px; vertical-align: middle;
    }
    .kv-hero-title {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 500; letter-spacing: -0.038em; line-height: 0.94;
        font-size: clamp(56px, 9.5vw, 140px);
        color: var(--cream); margin: 0;
    }
    .kv-hero-title em { font-style: italic; font-weight: 500; color: var(--accent-bright); }
    .kv-hero-sub { margin-top: 32px; max-width: 460px; font-size: 15.5px; line-height: 1.6; color: rgba(247, 243, 234, 0.72); }
    .kv-hero-note {
        margin-top: 28px; padding: 16px 18px; max-width: 460px;
        background: rgba(51, 103, 153, 0.08);
        border: 1px solid rgba(51, 103, 153, 0.22);
        border-radius: 12px;
        font-size: 13.5px; color: rgba(247, 243, 234, 0.85); line-height: 1.55;
    }

    .kv-auth-form-wrap { display: flex; align-items: center; justify-content: center; padding: 40px 0 0; }
    @media (min-width: 1024px) { .kv-auth-form-wrap { grid-area: form; padding: 0; } }
    .kv-form-card {
        width: 100%; max-width: 460px;
        background: var(--cream); color: var(--ink);
        border-radius: 20px; padding: 34px 30px 30px;
        box-shadow:
            0 40px 100px -30px rgba(0, 0, 0, 0.6),
            0 20px 40px -20px rgba(0, 0, 0, 0.4),
            0 0 0 1px rgba(247, 243, 234, 0.06);
    }
    @media (min-width: 1024px) { .kv-form-card { padding: 38px 36px 32px; } }

    .kv-form-eyebrow {
        display: inline-block;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 10px; letter-spacing: 0.2em; text-transform: uppercase;
        color: var(--ink-mute);
    }
    .kv-form-title {
        font-family: 'Fraunces', Georgia, serif;
        font-weight: 600; letter-spacing: -0.025em; line-height: 1;
        font-size: 30px; color: var(--ink); margin-top: 6px;
    }
    .kv-form-sub { margin-top: 8px; font-size: 13.5px; line-height: 1.55; color: var(--ink-soft); }

    .kv-field { margin-top: 22px; position: relative; }
    .kv-field-label {
        display: flex; align-items: baseline; justify-content: space-between;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 9.5px; letter-spacing: 0.18em; text-transform: uppercase;
        color: var(--ink-mute); margin-bottom: 8px;
    }
    .kv-input {
        width: 100%; padding: 13px 14px 13px 44px;
        font-family: inherit; font-size: 14.5px;
        background: var(--paper); color: var(--ink);
        border: 1px solid var(--line-2); border-radius: 12px;
        transition: border-color 150ms, box-shadow 150ms;
    }
    .kv-input:focus {
        outline: none; border-color: var(--accent-d);
        box-shadow: 0 0 0 3px rgba(51, 103, 153, 0.22);
    }
    .kv-input.is-invalid {
        border-color: #C1583A;
        box-shadow: 0 0 0 3px rgba(193, 88, 58, 0.15);
    }
    .kv-field-icon {
        position: absolute; left: 14px; top: 40px; width: 20px; height: 20px;
        display: inline-flex; align-items: center; justify-content: center;
        color: var(--ink-mute); pointer-events: none;
    }
    .kv-field-error { margin-top: 6px; font-size: 12px; color: #C1583A; }

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
        background: var(--accent); color: #FFFFFF;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .kv-spinner-dot {
        width: 12px; height: 12px;
        border: 2px solid rgba(255, 255, 255, 0.35);
        border-top-color: #FFFFFF;
        border-radius: 999px;
        animation: kv-spin 700ms linear infinite;
    }
    @keyframes kv-spin { to { transform: rotate(360deg); } }

    .kv-form-foot {
        margin-top: 24px;
        display: flex; align-items: center; justify-content: space-between;
        gap: 20px; padding-top: 20px;
        border-top: 1px solid var(--line);
        font-size: 12.5px; color: var(--ink-mute);
    }
    .kv-form-foot a { color: var(--ink); font-weight: 500; text-decoration: none; }
    .kv-form-foot a:hover { text-decoration: underline; text-underline-offset: 3px; }

    /* Success state */
    .kv-sent-icon {
        width: 60px; height: 60px; border-radius: 50%;
        background: rgba(51, 103, 153, 0.15);
        color: var(--accent-in);
        display: inline-flex; align-items: center; justify-content: center;
        margin-bottom: 18px;
    }
    .kv-sent-chip {
        display: inline-flex; align-items: center; gap: 8px;
        font-family: ui-monospace, monospace; font-size: 12px;
        color: var(--ink); background: var(--cream-2);
        padding: 6px 12px; border-radius: 999px; margin-top: 4px;
    }

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
        .kv-hero-quote, .kv-hero-bullets, .kv-hero-tips, .kv-hero-note { display: none; }
        .kv-auth-brand-name { max-width: 180px; }
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
            <span class="kv-auth-brand">
                <span class="kv-auth-brand-mark" aria-hidden="true">
                    <img src="{{ asset(config('brand.logo')) }}" alt="" width="18" height="21">
                </span>
                <span class="kv-auth-brand-name">{{ $tenantDisplayName }}</span>
            </span>
            <span class="kv-auth-line-right">Remembered it? <a href="{{ route('tenant.login') }}" style="color: var(--cream); margin-left: 6px;">Sign in →</a></span>
            @include('partials.auth-mobile-menu')
        </div>

        <div class="kv-auth-hero">
            <span class="kv-hero-eyebrow">Password reset</span>
            <h1 class="kv-hero-title">
                Lost&nbsp;<em>it?</em>
            </h1>
        </div>

        <div class="kv-auth-form-wrap">
            <div class="kv-form-card">
                @if ($sent)
                    {{-- ── "Check your inbox" state ─────────────────── --}}
                    <div style="text-align: center; padding: 4px 0 8px;">
                        <span class="kv-sent-icon" aria-hidden="true">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                <path d="M3 7l9 6 9-6"/>
                                <path d="M8 15l2 2 6-6" stroke="var(--accent-d)"/>
                            </svg>
                        </span>
                        <span class="kv-form-eyebrow">Check your inbox</span>
                        <h2 class="kv-form-title" style="margin-top: 6px;">Link sent.</h2>
                        <p class="kv-form-sub">
                            We've sent a reset link to
                            <span class="kv-sent-chip">{{ $email }}</span>.
                            Follow it to set a new password on <strong style="color: var(--ink);">{{ $tenantDisplayName }}</strong>.
                        </p>

                        <div class="kv-form-foot" style="justify-content: center; text-align: center;">
                            <span>
                                Didn't arrive?
                                <button type="button" wire:click="$set('status', '')"
                                        style="background: transparent; border: 0; padding: 0; cursor: pointer;
                                               color: var(--ink); font-weight: 500; text-decoration: underline;
                                               text-underline-offset: 3px;">
                                    Try again
                                </button>
                            </span>
                        </div>
                    </div>
                @else
                    {{-- ── Ask-for-email state ──────────────────────── --}}
                    <h2 class="kv-form-title">Enter your email.</h2>
                    <p class="kv-form-sub">
                        We'll send a reset link for <strong style="color: var(--ink);">{{ $tenantDisplayName }}</strong>.
                    </p>

                    <form wire:submit="sendResetLink" data-testid="forgot-form">

                        <div class="kv-field">
                            <label for="forgot-email" class="kv-field-label"><span>Email</span></label>
                            <span class="kv-field-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="2.5" y="4" width="15" height="12" rx="2"/>
                                    <path d="M3 5l7 6 7-6"/>
                                </svg>
                            </span>
                            <input id="forgot-email" type="email" wire:model="email" required autocomplete="email"
                                   class="kv-input @error('email') is-invalid @enderror"
                                   placeholder="you@yourproperty.com" autofocus />
                            @error('email') <p data-error="email" class="kv-field-error">{{ $message }}</p> @enderror
                        </div>

                        <button type="submit" class="kv-submit"
                                wire:loading.attr="disabled" wire:target="sendResetLink">
                            <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
                            <span wire:loading wire:target="sendResetLink">Sending…</span>
                            <span class="kv-submit-dot" aria-hidden="true">
                                <span wire:loading.remove wire:target="sendResetLink">{!! $pillArrow !!}</span>
                                <span wire:loading wire:target="sendResetLink" class="kv-spinner-dot"></span>
                            </span>
                        </button>

                        <div class="kv-form-foot">
                            <span>Just remembered? <a href="{{ route('tenant.login') }}">Sign in →</a></span>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <div class="kv-auth-line kv-auth-line--bot">
            <span>© {{ date('Y') }} {{ config('brand.name') }}</span>
            <span>Powered by @if(config('brand.url'))<a href="{{ config('brand.url') }}" style="color: var(--cream); margin-left: 4px;">{{ config('brand.name') }}</a>@else{{ config('brand.name') }}@endif</span>
        </div>

    </div>
</div>
