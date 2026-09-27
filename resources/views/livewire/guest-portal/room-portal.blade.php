<div class="gp-wrap"
     x-data="{ toast: @js(session('portal_flash') ?? '') }"
     x-init="if (toast) setTimeout(() => toast = '', 4200)"
     x-on:portal-flash.window="toast = $event.detail?.message ?? ''; setTimeout(() => toast = '', 3200)">
    <style>
        /* Guest self-service portal - mobile-first design system.
           Palette follows Keverna's warm ink + cream brand rather
           than a generic blue. Tap targets are >=44px per iOS HIG;
           safe-area padding respects the iPhone notch + home
           indicator. */
        :root {
            --gp-paper:      #FCFAF5;           /* app background   */
            --gp-card:       #FFFFFF;           /* raised surfaces  */
            --gp-card-deep:  #F5F1E8;           /* nested surfaces  */
            --gp-ink:        #14171A;
            --gp-ink-soft:   #3D434A;
            --gp-ink-mute:   #7A7F87;
            --gp-line:       rgba(20, 23, 26, 0.08);
            --gp-line-soft:  rgba(20, 23, 26, 0.05);
            --gp-primary:    #14171A;
            --gp-accent:     #B6D97E;           /* Keverna olive-lime */
            --gp-accent-deep:#7DAE3F;
            --gp-danger:     #B85C42;
            --gp-ok:         #4C956C;
            --gp-warn:       #A8650A;

            --gp-safe-top:    env(safe-area-inset-top, 0px);
            --gp-safe-bottom: env(safe-area-inset-bottom, 0px);
            --gp-shadow-lift: 0 12px 34px -20px rgba(20, 17, 12, 0.28);
            --gp-shadow-card: 0 4px 14px -6px rgba(20, 17, 12, 0.10);
        }

        /* Warm-dark palette for the 2am test: a guest waking to a
           blinding cream card is a UX failure. All tokens flip in one
           media query; downstream rules that read var(--gp-*) inherit
           the flip without touching a single component style. Kept
           warm (near-black ink #12100D, cream text #F5F1E8) instead of
           pure black-on-white-inverted so the brand read stays
           Keverna, not "generic dark app". */
        @media (prefers-color-scheme: dark) {
            :root {
                --gp-paper:      #12100D;
                --gp-card:       #1B1814;
                --gp-card-deep:  #22201B;
                --gp-ink:        #F5F1E8;
                --gp-ink-soft:   #CFC9BC;
                --gp-ink-mute:   #928C7F;
                --gp-line:       rgba(245, 241, 232, 0.10);
                --gp-line-soft:  rgba(245, 241, 232, 0.06);
                --gp-primary:    #F5F1E8;
                /* Olive + status colours stay identical — they read
                   correctly against both cream and warm-ink surfaces. */
                --gp-shadow-lift: 0 12px 34px -18px rgba(0, 0, 0, 0.6);
                --gp-shadow-card: 0 4px 14px -6px rgba(0, 0, 0, 0.35);
            }
        }

        /* Page shell: fills the viewport, painted paper. */
        .gp-wrap {
            min-height: 100vh; min-height: 100dvh;
            background: var(--gp-paper);
            color: var(--gp-ink);
            font-family: system-ui, -apple-system, 'SF Pro Text', Segoe UI, sans-serif;
            padding-bottom: calc(24px + var(--gp-safe-bottom));
            -webkit-font-smoothing: antialiased;
        }
        .gp-shell {
            max-width: 480px; margin: 0 auto;
            padding: 0 18px;
        }

        /* Sticky app-bar - full-width backdrop (so the blur feels
           edge-to-edge like a real mobile app) with a max-width
           inner container so the brand + actions don't stretch on
           desktop. Blurs the paper behind it as the user scrolls
           for a native-app feel. */
        .gp-appbar {
            position: sticky; top: 0; z-index: 30;
            padding-top: var(--gp-safe-top);
            background: color-mix(in srgb, var(--gp-paper) 82%, transparent);
            -webkit-backdrop-filter: saturate(180%) blur(18px);
            backdrop-filter: saturate(180%) blur(18px);
            border-bottom: 1px solid var(--gp-line-soft);
        }
        .gp-appbar-inner {
            max-width: 480px;
            margin: 0 auto;
            padding: 10px 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            min-height: 56px;
        }
        .gp-appbar-brand {
            display: inline-flex; align-items: center; gap: 10px;
            min-width: 0;
        }
        .gp-appbar-brand .brand-text { min-width: 0; }
        .gp-appbar-brand .name {
            display: block;
            font-size: 15px; font-weight: 600; color: var(--gp-ink);
            letter-spacing: -0.01em;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            max-width: 220px;
        }
        .gp-appbar-brand .kicker {
            display: block;
            font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--gp-ink-mute); font-weight: 600;
            margin-bottom: 1px;
        }
        .gp-appbar-mark {
            width: 36px; height: 36px; border-radius: 11px;
            background: var(--gp-ink); color: var(--gp-paper);
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 14px -6px rgba(20, 17, 12, 0.35);
        }
        .gp-appbar-mark svg { width: 18px; height: 18px; }
        .gp-icon-btn {
            width: 40px; height: 40px; border-radius: 999px;
            background: var(--gp-card); border: 1px solid var(--gp-line);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; color: var(--gp-ink-soft);
            transition: transform 120ms, border-color 140ms, color 140ms;
        }
        .gp-icon-btn:hover { border-color: var(--gp-ink); color: var(--gp-ink); }
        .gp-icon-btn:active { transform: scale(0.94); }
        .gp-icon-btn svg { width: 17px; height: 17px; }

        /* Pre-auth "back to property" pill lives in the app-bar so a
           guest who tapped through from the property page can bail
           out without hunting for the browser back button. Post-auth
           we drop it: the guest is inside a secure flow + the logout
           icon is the correct exit. */
        .gp-appbar-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px 6px 10px;
            background: var(--gp-card);
            border: 1px solid var(--gp-line);
            border-radius: 999px;
            font-size: 12px; font-weight: 600;
            color: var(--gp-ink-soft);
            transition: border-color 140ms, color 140ms;
        }
        .gp-appbar-back:hover { border-color: var(--gp-ink); color: var(--gp-ink); }
        .gp-appbar-back svg { width: 12px; height: 12px; }

        /* Hero welcome card - full-bleed on mobile, warm ink
           surface with the guest's name centre-stage. */
        .gp-hero {
            margin: 8px 0 18px;
            padding: 22px 20px 20px;
            background: linear-gradient(135deg, var(--gp-ink) 0%, var(--gp-ink) 60%, #2A241F 100%);
            color: var(--gp-paper);
            border-radius: 22px;
            box-shadow: var(--gp-shadow-lift);
            position: relative; overflow: hidden;
        }
        .gp-hero::after {
            /* Subtle accent glow in the corner. */
            content: ''; position: absolute; right: -40px; top: -40px;
            width: 140px; height: 140px; border-radius: 50%;
            background: radial-gradient(circle, color-mix(in srgb, var(--gp-accent) 55%, transparent) 0%, transparent 70%);
            pointer-events: none;
        }
        .gp-hero .eyebrow {
            font-size: 10.5px; letter-spacing: 0.22em; text-transform: uppercase;
            color: color-mix(in srgb, var(--gp-paper) 60%, transparent);
            font-weight: 600;
        }
        .gp-hero .h1 {
            margin: 6px 0 4px; font-size: 26px; font-weight: 600;
            letter-spacing: -0.02em; line-height: 1.15;
            font-family: var(--display-font, ui-serif, Georgia, serif);
        }
        .gp-hero .meta {
            font-size: 13px; color: color-mix(in srgb, var(--gp-paper) 75%, transparent);
        }
        .gp-hero-strip {
            margin-top: 16px; padding: 12px 14px;
            background: color-mix(in srgb, var(--gp-paper) 12%, transparent);
            border-radius: 14px;
            display: flex; gap: 14px; align-items: center;
        }
        .gp-hero-strip .stat { flex: 1; min-width: 0; }
        .gp-hero-strip .stat .k {
            font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase;
            color: color-mix(in srgb, var(--gp-paper) 55%, transparent);
            font-weight: 600;
        }
        .gp-hero-strip .stat .v {
            margin-top: 3px; font-size: 15px; font-weight: 600;
            color: var(--gp-paper);
            font-variant-numeric: tabular-nums;
        }
        .gp-hero-strip .stat.overdue .v { color: #F5B942; }
        .gp-hero-strip .divider {
            width: 1px; align-self: stretch;
            background: color-mix(in srgb, var(--gp-paper) 20%, transparent);
        }

        /* Section header - quiet label above tile groups. */
        .gp-section-title {
            font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--gp-ink-mute); font-weight: 600;
            margin: 20px 4px 10px;
        }

        /* Card surface (used by auth screens + tile forms). */
        .gp-card {
            background: var(--gp-card);
            border-radius: 18px;
            padding: 20px;
            box-shadow: var(--gp-shadow-card);
        }
        .gp-card + .gp-card { margin-top: 12px; }
        .gp-card--inset {
            background: var(--gp-card-deep);
            box-shadow: none;
        }

        /* Forms */
        .gp-form-label {
            display: block; font-size: 12px; letter-spacing: 0.06em;
            text-transform: uppercase; color: var(--gp-ink-mute);
            font-weight: 600; margin-bottom: 6px;
        }
        .gp-input, .gp-textarea, .gp-select {
            width: 100%; padding: 14px 16px;
            background: var(--gp-card-deep);
            border: 1px solid transparent;
            border-radius: 14px;
            font-size: 16px; color: var(--gp-ink); font-family: inherit;
            appearance: none; -webkit-appearance: none;
        }
        .gp-input:focus, .gp-textarea:focus, .gp-select:focus {
            outline: 0;
            border-color: var(--gp-ink);
            background: var(--gp-card);
        }
        .gp-help {
            font-size: 12.5px; color: var(--gp-ink-mute);
            margin-top: 6px; line-height: 1.5;
        }
        .gp-error {
            padding: 12px 14px;
            background: color-mix(in srgb, var(--gp-danger) 8%, transparent);
            border: 1px solid color-mix(in srgb, var(--gp-danger) 25%, transparent);
            border-radius: 12px;
            color: var(--gp-danger);
            font-size: 13px; line-height: 1.5;
        }

        /* Buttons */
        .gp-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 14px 20px;
            border-radius: 999px; border: 0; cursor: pointer;
            font: inherit; font-size: 14.5px; font-weight: 600;
            transition: transform 120ms, opacity 120ms;
            min-height: 48px;
        }
        .gp-btn:active { transform: scale(0.98); }
        .gp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .gp-btn--primary {
            background: var(--gp-ink); color: var(--gp-paper);
            width: 100%;
        }
        .gp-btn--accent {
            background: var(--gp-accent); color: var(--gp-ink);
            width: 100%;
        }
        .gp-btn--ghost {
            background: transparent; color: var(--gp-ink-soft);
            border: 1px solid var(--gp-line);
        }
        .gp-btn--danger {
            background: var(--gp-danger); color: var(--gp-paper);
            width: 100%;
        }
        .gp-btn--danger-ghost {
            background: transparent; color: var(--gp-danger);
            border: 1px solid color-mix(in srgb, var(--gp-danger) 35%, transparent);
        }

        /* PIN entry row */
        .gp-pin-row { display: flex; gap: 10px; }
        .gp-pin-row .gp-input {
            text-align: center; font-size: 22px; letter-spacing: 0.6em;
            padding: 16px 12px;
            font-family: 'SF Mono', 'JetBrains Mono', monospace;
        }

        /* Home tile grid - bigger, tactile, brand-warm */
        .gp-tiles {
            display: grid; gap: 10px;
            grid-template-columns: 1fr 1fr;
        }
        .gp-tile {
            display: flex; flex-direction: column; gap: 10px;
            padding: 18px 16px 16px;
            background: var(--gp-card);
            border: 0;
            border-radius: 18px;
            text-align: left;
            cursor: pointer;
            box-shadow: var(--gp-shadow-card);
            transition: transform 140ms, box-shadow 140ms;
            font: inherit;
            color: var(--gp-ink);
            min-height: 108px;
        }
        .gp-tile:active {
            transform: scale(0.98);
            box-shadow: 0 2px 8px -4px rgba(20, 17, 12, 0.10);
        }
        .gp-tile-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: var(--gp-card-deep);
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--gp-ink);
        }
        .gp-tile-icon svg { width: 20px; height: 20px; }
        .gp-tile .name {
            font-size: 14.5px; font-weight: 600;
            color: var(--gp-ink);
            letter-spacing: -0.005em;
        }
        .gp-tile .sub {
            font-size: 12px; color: var(--gp-ink-mute);
            line-height: 1.4;
        }
        /* Coloured icon variants - group tiles by intent. */
        .gp-tile--money .gp-tile-icon { background: #DCFCE7; color: #166534; }
        .gp-tile--room  .gp-tile-icon { background: #DBEAFE; color: #1E40AF; }
        .gp-tile--help  .gp-tile-icon { background: #FEF3C7; color: #A16207; }
        .gp-tile--flag  .gp-tile-icon { background: #FEE2E2; color: #B91C1C; }
        .gp-tile--nav   .gp-tile-icon { background: #E9E5DF; color: #14171A; }

        /* Bottom sticky action bar (used inside tile screens for
           primary CTAs). Adds safe-area padding for the home indicator. */
        .gp-sticky-cta {
            position: sticky; bottom: 0;
            margin: 20px -18px 0;
            padding: 12px 18px calc(12px + var(--gp-safe-bottom));
            background: color-mix(in srgb, var(--gp-paper) 92%, transparent);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            backdrop-filter: saturate(180%) blur(14px);
            border-top: 1px solid var(--gp-line-soft);
        }

        /* Back link inside a tile screen */
        .gp-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 12px 8px 8px;
            background: transparent; border: 0;
            color: var(--gp-ink-soft); cursor: pointer;
            font: inherit; font-size: 13px;
            margin: 4px 0 12px -8px;
            border-radius: 999px;
            min-height: 36px;
        }
        .gp-back:active { background: var(--gp-card-deep); }

        /* Toast */
        .gp-toast {
            position: fixed; left: 50%; bottom: calc(24px + var(--gp-safe-bottom));
            transform: translateX(-50%);
            padding: 12px 18px;
            background: var(--gp-ink); color: var(--gp-paper);
            border-radius: 999px;
            font-size: 13.5px; font-weight: 500;
            box-shadow: 0 12px 32px rgba(20, 17, 12, 0.35);
            z-index: 40;
            max-width: calc(100vw - 32px);
        }

        /* Stars (Rate tile) */
        .gp-stars { display: flex; gap: 6px; margin-top: 8px; }
        .gp-star {
            padding: 6px 8px;
            background: transparent; border: 0;
            cursor: pointer; font-size: 28px;
            color: var(--gp-ink-mute);
            min-height: 44px;
        }
        .gp-star.is-on { color: #E9B44C; }

        /* Footer brand */
        .gp-brand {
            text-align: center;
            margin: 24px 0 0;
            color: var(--gp-ink-mute);
        }

        @media (min-width: 520px) {
            .gp-shell { padding: 0 24px; }
        }
    </style>

    {{-- Sticky app-bar - full-width backdrop with a max-width inner
         container so the brand + actions stay comfortably centred on
         desktop. Kicker line above the property name gives it the
         two-line hierarchy real hospitality apps use (Guest portal /
         Property name), and a room chip on the right when the guest
         is verified so they always know where they are. --}}
    @php
        $isAuthed = $step !== 'auth.phone' && $step !== 'auth.pin_set'
                    && $step !== 'auth.pin_enter' && $step !== 'auth.pin_reset';
    @endphp
    <header class="gp-appbar">
        <div class="gp-appbar-inner">
            <div class="gp-appbar-brand">
                <span class="gp-appbar-mark" aria-hidden>
                    <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="square">
                        <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5"/>
                    </svg>
                </span>
                <span class="brand-text">
                    <span class="kicker">Guest portal</span>
                    <span class="name">{{ $property?->name ?? 'Your room' }}</span>
                </span>
            </div>
            @if (! $isAuthed)
                <a href="/" class="gp-appbar-back" data-testid="portal-appbar-back" aria-label="Back to property page">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4 6 8l4 4"/></svg>
                    Property
                </a>
            @endif
            @if ($isAuthed)
                <div style="display: inline-flex; align-items: center; gap: 8px;">
                    @if ($booking?->room?->room_number)
                        <span style="display: inline-flex; align-items: center; gap: 5px;
                                     padding: 6px 10px; border-radius: 999px;
                                     background: var(--gp-card-deep);
                                     font-size: 12px; font-weight: 600; color: var(--gp-ink);"
                              data-testid="portal-appbar-room">
                            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"
                                 stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 6l5-3 5 3v7H3z"/><path d="M6 13v-3h4v3"/>
                            </svg>
                            Rm {{ $booking->room->room_number }}
                        </span>
                    @endif
                    <button type="button" wire:click="logout" class="gp-icon-btn"
                            data-testid="portal-appbar-lock" aria-label="Lock portal">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="9" width="12" height="8" rx="2"/>
                            <path d="M7 9V6a3 3 0 0 1 6 0v3"/>
                        </svg>
                    </button>
                </div>
            @endif
        </div>
    </header>

    <div class="gp-shell">
        {{-- Pre-auth intro card - replaces the terse brand header. --}}
        @if ($step === 'auth.phone' || $step === 'auth.pin_set' || $step === 'auth.pin_enter' || $step === 'auth.pin_reset')
            <div style="padding: 30px 4px 20px;">
                <p style="font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase; color: var(--gp-ink-mute); font-weight: 600; margin: 0;">
                    Guest portal
                </p>
                <h1 style="margin: 8px 0 6px; font-size: 26px; font-weight: 600; letter-spacing: -0.02em; line-height: 1.15; font-family: var(--display-font, ui-serif, Georgia, serif);">
                    {{ $room?->name ?? ($property?->name ?? 'Your room') }}
                </h1>
                <p style="font-size: 14px; color: var(--gp-ink-soft); margin: 0; line-height: 1.5;">
                    Sign in with your phone to extend your stay, order in, request help, and check out.
                </p>
            </div>
        @endif

        {{-- ── AUTH: phone (+ room number when not pre-filled) ── --}}
        @if ($step === 'auth.phone')
            <div class="gp-card" data-testid="portal-step-phone">
                @unless ($prefillRoomId)
                    <label for="room-number" class="gp-form-label">Room number</label>
                    <input id="room-number" type="text" wire:model="roomNumber"
                           placeholder="e.g. 205" inputmode="numeric"
                           data-testid="portal-room-number-input" class="gp-input">
                    <p class="gp-help" style="margin-bottom: 14px;">The number on your room door.</p>
                @endunless

                <label for="phone" class="gp-form-label">Phone number</label>
                <input id="phone" type="tel" wire:model="phone"
                       placeholder="e.g. 0801 234 5678"
                       autocomplete="tel" inputmode="tel"
                       data-testid="portal-phone-input" class="gp-input">
                <p class="gp-help">
                    @if ($prefillRoomId)
                        Use the number you booked with. We match it to your current reservation on this room.
                    @else
                        Use the number you booked with. We check it against the current reservation on that room.
                    @endif
                </p>
                @if ($authError !== '')
                    <div class="gp-error" style="margin-top: 12px;" data-testid="portal-auth-error">{{ $authError }}</div>
                @endif
                <div style="margin-top: 16px;">
                    <button type="button" wire:click="submitPhone" class="gp-btn gp-btn--primary" data-testid="portal-phone-submit"
                            wire:loading.attr="disabled" wire:target="submitPhone">
                        <span wire:loading.remove wire:target="submitPhone">Continue</span>
                        <span wire:loading wire:target="submitPhone">Checking…</span>
                    </button>
                </div>
            </div>
        @endif

        {{-- ── AUTH: PIN set ──────────────────────────────────── --}}
        @if ($step === 'auth.pin_set')
            <div class="gp-card" data-testid="portal-step-pin-set">
                <p style="font-size: 13.5px; color: var(--gp-ink-soft); margin: 0 0 14px; line-height: 1.5;">
                    First time on a Keverna property - set a 4-digit PIN.
                    You'll use the same PIN at every Keverna property you visit.
                </p>
                <label class="gp-form-label">4-digit PIN</label>
                <x-gp-otp wire-model="pin" :length="4" testid="portal-pin-set-input" :autofocus="true" />
                <label class="gp-form-label" style="margin-top: 16px;">Confirm PIN</label>
                <x-gp-otp wire-model="pinConfirm" :length="4" testid="portal-pin-confirm-input" />
                @if ($authError !== '')
                    <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                @endif
                <button type="button" wire:click="submitPinSet" class="gp-btn gp-btn--primary" data-testid="portal-pin-set-submit"
                        style="margin-top: 16px;">Set PIN &amp; enter</button>
            </div>
        @endif

        {{-- ── AUTH: PIN enter ────────────────────────────────── --}}
        @if ($step === 'auth.pin_enter')
            <div class="gp-card" data-testid="portal-step-pin-enter">
                <p style="font-size: 13.5px; color: var(--gp-ink-soft); margin: 0 0 14px; line-height: 1.5;">
                    Welcome back, enter your 4-digit PIN.
                </p>
                <x-gp-otp wire-model="pin" :length="4" testid="portal-pin-enter-input" :autofocus="true" />
                @if ($authError !== '')
                    <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                @endif
                <button type="button" wire:click="submitPinEnter" class="gp-btn gp-btn--primary" data-testid="portal-pin-enter-submit"
                        style="margin-top: 16px;">Unlock</button>
                <div style="text-align: center; margin-top: 12px;">
                    <button type="button" wire:click="requestPinReset"
                            data-testid="portal-forgot-pin"
                            style="background: transparent; border: 0; color: var(--gp-ink-soft); cursor: pointer; font: inherit; font-size: 13px; text-decoration: underline; text-underline-offset: 3px;"
                            wire:loading.attr="disabled" wire:target="requestPinReset">
                        <span wire:loading.remove wire:target="requestPinReset">Forgot your PIN?</span>
                        <span wire:loading wire:target="requestPinReset">Sending code…</span>
                    </button>
                </div>
            </div>
        @endif

        {{-- ── AUTH: PIN reset (enter OTP from WhatsApp) ──────── --}}
        @if ($step === 'auth.pin_reset')
            <div class="gp-card" data-testid="portal-step-pin-reset">
                <p style="font-size: 13.5px; color: var(--gp-ink-soft); margin: 0 0 14px; line-height: 1.5;">
                    We sent a 6-digit code to your WhatsApp. Enter it below to set a new PIN.
                </p>
                <x-gp-otp wire-model="resetOtp" :length="6" testid="portal-pin-reset-input"
                          :autofocus="true" autocomplete="one-time-code" />
                @if ($authError !== '')
                    <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                @endif
                <button type="button" wire:click="submitPinReset" class="gp-btn gp-btn--primary"
                        data-testid="portal-pin-reset-submit"
                        style="margin-top: 16px;">Verify code</button>
                <div style="text-align: center; margin-top: 10px;">
                    <button type="button" wire:click="requestPinReset"
                            data-testid="portal-pin-reset-resend"
                            style="background: transparent; border: 0; color: var(--gp-ink-mute); cursor: pointer; font: inherit; font-size: 12px;">
                        Didn't get it? Resend
                    </button>
                </div>
            </div>
        @endif

        {{-- ── HOME: hero + tile grid + primary checkout CTA ── --}}
        @if ($step === 'home' && $booking)
            @php
                $firstName = explode(' ', trim($booking->guest_name))[0] ?? 'Guest';
                $nightsLeft = $booking->checkout_date
                    ? max(0, (int) now()->startOfDay()->diffInDays($booking->checkout_date->copy()->startOfDay(), absolute: false))
                    : 0;
                $outstanding = (int) $booking->outstandingTotal();
                $projectedLate = (int) ($lateProjection['amount'] ?? 0);
                $displayBalance = $outstanding + $projectedLate;
            @endphp

            {{-- ── Redesigned home. Cream + olive editorial palette,
                 same tokens as the auth surfaces. Big Fraunces welcome,
                 "Your stay" card (Room · Check-out · Balance), then a
                 clean tile grid. --}}
            <style>
                .gp-hv2 {
                    font-family: 'Inter', system-ui, -apple-system, sans-serif;
                    color: var(--gp-ink);
                    padding-top: 6px;
                }
                .gp-hv2-hello {
                    font-family: 'Fraunces', Georgia, serif;
                    font-weight: 500;
                    letter-spacing: -0.025em;
                    line-height: 1.02;
                    font-size: clamp(30px, 8vw, 40px);
                    color: var(--gp-ink);
                    margin: 0;
                }
                .gp-hv2-hello em {
                    font-style: italic; font-weight: 500;
                    color: var(--gp-accent-deep);
                }
                .gp-hv2-eyebrow {
                    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                    font-size: 10.5px; letter-spacing: 0.18em; text-transform: uppercase;
                    color: var(--gp-ink-mute);
                    margin: 0 0 6px;
                }

                /* Stay card */
                .gp-hv2-stay {
                    margin-top: 20px;
                    background: linear-gradient(135deg, #182014 0%, #0F1310 100%);
                    color: #F7F3EA;
                    border-radius: 20px;
                    padding: 20px 22px 22px;
                    position: relative; overflow: hidden;
                    box-shadow: 0 20px 40px -18px rgba(20, 17, 12, 0.28);
                }
                .gp-hv2-stay::after {
                    content: ''; position: absolute;
                    top: -30%; right: -20%;
                    width: 300px; height: 300px;
                    background: radial-gradient(circle, rgba(173, 203, 82, 0.32) 0%, transparent 60%);
                    filter: blur(30px);
                    pointer-events: none;
                }
                .gp-hv2-stay-eye {
                    font-family: ui-monospace, monospace;
                    font-size: 10.5px; letter-spacing: 0.2em; text-transform: uppercase;
                    color: rgba(247, 243, 234, 0.55);
                }
                .gp-hv2-stay-ref {
                    float: right; font-family: ui-monospace, monospace;
                    font-size: 10.5px; letter-spacing: 0.08em;
                    color: rgba(247, 243, 234, 0.55);
                }
                .gp-hv2-stay-title {
                    font-family: 'Fraunces', Georgia, serif;
                    font-size: 22px; font-weight: 600;
                    letter-spacing: -0.015em;
                    color: #F7F3EA;
                    margin: 6px 0 16px;
                    position: relative; z-index: 1;
                }
                .gp-hv2-stay-grid {
                    display: grid; grid-template-columns: 1fr 1fr 1fr;
                    gap: 12px; position: relative; z-index: 1;
                    padding-top: 14px;
                    border-top: 1px solid rgba(247, 243, 234, 0.12);
                }
                .gp-hv2-stay-cell .k {
                    font-family: ui-monospace, monospace;
                    font-size: 9.5px; letter-spacing: 0.18em; text-transform: uppercase;
                    color: rgba(247, 243, 234, 0.6); margin-bottom: 4px;
                }
                .gp-hv2-stay-cell .v {
                    font-family: 'Fraunces', Georgia, serif;
                    font-size: 20px; font-weight: 600; color: #F7F3EA;
                    letter-spacing: -0.01em;
                    line-height: 1.15;
                }
                .gp-hv2-stay-cell.overdue .v { color: #E9B26B; }
                .gp-hv2-stay-cell .sub {
                    font-size: 10.5px; color: rgba(233, 178, 107, 0.9); margin-top: 3px;
                }
                .gp-hv2-stay-cell .paid-chip {
                    display: inline-flex; align-items: center; gap: 4px;
                    padding: 2px 8px; border-radius: 999px;
                    background: rgba(173, 203, 82, 0.18);
                    color: #C9DE8A;
                    font-family: 'Inter', system-ui, sans-serif;
                    font-size: 12px; font-weight: 500;
                    letter-spacing: 0;
                }

                /* Section title */
                .gp-hv2-sec {
                    margin: 26px 0 12px;
                    font-family: 'Fraunces', Georgia, serif;
                    font-size: 18px; font-weight: 600;
                    letter-spacing: -0.015em;
                    color: var(--gp-ink);
                }
                .gp-hv2-sec-sub {
                    font-family: 'Inter', system-ui, sans-serif;
                    font-size: 13px; font-weight: 400;
                    color: var(--gp-ink-mute);
                    margin-left: 8px;
                }

                /* Tile grid */
                .gp-hv2-tiles {
                    display: grid; grid-template-columns: 1fr 1fr;
                    gap: 10px;
                }
                @media (min-width: 480px) {
                    .gp-hv2-tiles { grid-template-columns: 1fr 1fr 1fr; }
                }
                .gp-hv2-tile {
                    display: flex; flex-direction: column;
                    align-items: flex-start; text-align: left;
                    gap: 10px;
                    padding: 14px 14px 14px;
                    background: var(--gp-card);
                    border: 1px solid var(--gp-line);
                    border-radius: 14px;
                    cursor: pointer;
                    font: inherit; color: var(--gp-ink);
                    transition: transform 120ms, border-color 120ms, box-shadow 120ms;
                    min-height: 96px;
                }
                .gp-hv2-tile:hover,
                .gp-hv2-tile:focus-visible {
                    outline: none;
                    transform: translateY(-1px);
                    border-color: rgba(20, 17, 12, 0.18);
                    box-shadow: 0 8px 24px -14px rgba(20, 17, 12, 0.2);
                }
                .gp-hv2-tile:active { transform: translateY(0); }
                .gp-hv2-tile-emoji {
                    font-size: 22px; line-height: 1;
                    width: 38px; height: 38px;
                    display: inline-flex; align-items: center; justify-content: center;
                    background: var(--gp-card-deep);
                    border-radius: 10px;
                }
                .gp-hv2-tile-name {
                    font-family: 'Inter', system-ui, sans-serif;
                    font-size: 13.5px; font-weight: 600;
                    color: var(--gp-ink);
                    line-height: 1.25;
                }
                .gp-hv2-tile-sub {
                    font-size: 11px; color: var(--gp-ink-mute);
                    line-height: 1.35;
                }

                /* Foot line */
                .gp-hv2-foot {
                    margin-top: 32px;
                    padding-top: 18px;
                    border-top: 1px solid var(--gp-line);
                    display: flex; align-items: center; justify-content: space-between;
                    gap: 12px;
                    font-family: ui-monospace, monospace;
                    font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase;
                    color: var(--gp-ink-mute);
                }
                .gp-hv2-foot-mark {
                    display: inline-flex; align-items: center; gap: 6px;
                }
            </style>

            <div data-testid="portal-step-home" class="gp-hv2"
                 @if (($lateProjection['kind'] ?? 'none') !== 'none') wire:poll.60s @endif>

                {{-- Hello --}}
                <p class="gp-hv2-eyebrow">Welcome to {{ $property?->name ?? 'the property' }}</p>
                <h1 class="gp-hv2-hello">Hi, <em>{{ $firstName }}</em> 👋</h1>

                {{-- Your stay card --}}
                <section class="gp-hv2-stay" data-testid="portal-home-strip">
                    <span class="gp-hv2-stay-eye">Your stay</span>
                    <span class="gp-hv2-stay-ref">{{ $booking->booking_ref }}</span>
                    <h2 class="gp-hv2-stay-title">
                        @if ($nightsLeft > 0)
                            {{ $nightsLeft }} night{{ $nightsLeft === 1 ? '' : 's' }} to go
                        @else
                            Last day
                        @endif
                    </h2>
                    <div class="gp-hv2-stay-grid">
                        <div class="gp-hv2-stay-cell">
                            <div class="k">Room</div>
                            <div class="v">{{ $room?->room_number ?? '-' }}</div>
                        </div>
                        <div class="gp-hv2-stay-cell">
                            <div class="k">Check-out</div>
                            <div class="v">{{ $booking->checkout_date?->format('j M') ?? '-' }}</div>
                        </div>
                        <div class="gp-hv2-stay-cell {{ $displayBalance > 0 ? 'overdue' : '' }}">
                            <div class="k">Balance</div>
                            <div class="v">
                                @if ($displayBalance > 0)
                                    ₦{{ number_format($displayBalance) }}
                                @else
                                    <span class="paid-chip">✓ Paid</span>
                                @endif
                            </div>
                            @if ($projectedLate > 0)
                                <div class="sub">incl. ₦{{ number_format($projectedLate) }} late fee</div>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Late-checkout fee notice - same-day past cutoff.
                     Shows the accruing amount so the guest isn't
                     surprised at check-out. Polls every 60s (see
                     wire:poll above) so the number ticks up as they sit. --}}
                @if (($lateProjection['kind'] ?? 'none') !== 'none' && $lateProjection['amount'] > 0)
                    <section class="gp-late-notice" data-testid="portal-late-notice"
                             style="margin-top: 14px; padding: 14px 16px;
                                    background: linear-gradient(135deg, rgba(224, 122, 95, 0.10), rgba(224, 122, 95, 0.03));
                                    border: 1px solid rgba(224, 122, 95, 0.30);
                                    border-radius: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                            <div style="flex: 1; min-width: 0;">
                                <p style="margin: 0; font-size: 11px; font-weight: 700;
                                          letter-spacing: 0.08em; text-transform: uppercase;
                                          color: #B85C42;">
                                    Late check-out fee accruing
                                </p>
                                <p style="margin: 4px 0 0; font-size: 13px; color: var(--gp-ink); line-height: 1.5;">
                                    You're
                                    <strong>{{ $lateProjection['hours_late'] }} hour{{ $lateProjection['hours_late'] === 1 ? '' : 's' }}</strong>
                                    past your check-out time.
                                    @if (($lateProjection['kind'] ?? '') === 'full_day')
                                        A full extra night has been triggered.
                                    @else
                                        This adds to your bill each started hour until you check out.
                                    @endif
                                </p>
                            </div>
                            <div style="text-align: right; flex-shrink: 0;">
                                <div class="lp-mono" style="font-size: 18px; font-weight: 600; color: #B85C42;">
                                    +₦{{ number_format((int) $lateProjection['amount']) }}
                                </div>
                                <div style="font-size: 10.5px; color: var(--gp-ink-mute); margin-top: 2px;">
                                    projected
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                            <button type="button" wire:click="openTile('extend')"
                                    class="gp-btn gp-btn--ghost gp-btn--sm"
                                    style="font-size: 12px; padding: 6px 12px;"
                                    data-testid="portal-late-extend">
                                Extend instead →
                            </button>
                            <button type="button" wire:click="openTile('checkout')"
                                    class="gp-btn gp-btn--sm"
                                    style="font-size: 12px; padding: 6px 12px;"
                                    data-testid="portal-late-checkout">
                                Check out now
                            </button>
                        </div>
                    </section>
                @endif

                {{-- Quick actions — the 9 redesigned tiles. Each is a
                     plain <button> firing openTile(). --}}
                <h2 class="gp-hv2-sec">
                    Quick actions
                    <span class="gp-hv2-sec-sub">No app to download.</span>
                </h2>

                <div class="gp-hv2-tiles">
                    <button type="button" wire:click="openTile('complaint')" class="gp-hv2-tile" data-testid="tile-complaint">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🛎️</span>
                        <span>
                            <span class="gp-hv2-tile-name">Request service</span>
                            <span class="gp-hv2-tile-sub">Tell us anything's off.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('order')" class="gp-hv2-tile" data-testid="tile-order">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🍽️</span>
                        <span>
                            <span class="gp-hv2-tile-name">Order food</span>
                            <span class="gp-hv2-tile-sub">From the kitchen.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('laundry')" class="gp-hv2-tile" data-testid="tile-laundry">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🧺</span>
                        <span>
                            <span class="gp-hv2-tile-name">Laundry</span>
                            <span class="gp-hv2-tile-sub">Pickup + return.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('taxi')" class="gp-hv2-tile" data-testid="tile-taxi">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🚗</span>
                        <span>
                            <span class="gp-hv2-tile-name">Transport</span>
                            <span class="gp-hv2-tile-sub">Front desk arranges.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('housekeeping')" class="gp-hv2-tile" data-testid="tile-housekeeping">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🧹</span>
                        <span>
                            <span class="gp-hv2-tile-name">Housekeeping</span>
                            <span class="gp-hv2-tile-sub">Fresh room, please.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('bill')" class="gp-hv2-tile" data-testid="tile-bill">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🧾</span>
                        <span>
                            <span class="gp-hv2-tile-name">View bill</span>
                            <span class="gp-hv2-tile-sub">Folio + charges.</span>
                        </span>
                    </button>
                    @if ($displayBalance > 0)
                        <button type="button" wire:click="openTile('bill')" class="gp-hv2-tile" data-testid="tile-pay"
                                style="background: linear-gradient(135deg, rgba(173, 203, 82, 0.14), rgba(173, 203, 82, 0.04));
                                       border-color: rgba(173, 203, 82, 0.35);">
                            <span class="gp-hv2-tile-emoji" aria-hidden="true"
                                  style="background: rgba(173, 203, 82, 0.22);">💳</span>
                            <span>
                                <span class="gp-hv2-tile-name">Pay balance</span>
                                <span class="gp-hv2-tile-sub">₦{{ number_format($displayBalance) }} due.</span>
                            </span>
                        </button>
                    @endif
                    <button type="button" wire:click="openTile('extend')" class="gp-hv2-tile" data-testid="tile-extend">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">📅</span>
                        <span>
                            <span class="gp-hv2-tile-name">Extend stay</span>
                            <span class="gp-hv2-tile-sub">Stay another night.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('info')" class="gp-hv2-tile" data-testid="tile-info">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">📍</span>
                        <span>
                            <span class="gp-hv2-tile-name">Property info</span>
                            <span class="gp-hv2-tile-sub">WiFi, breakfast, rules.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('contact')" class="gp-hv2-tile" data-testid="tile-contact">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">📞</span>
                        <span>
                            <span class="gp-hv2-tile-name">Contact reception</span>
                            <span class="gp-hv2-tile-sub">Call or WhatsApp.</span>
                        </span>
                    </button>
                </div>

                {{-- Secondary tiles (keep existing functionality accessible,
                     but visually quieter). --}}
                <h2 class="gp-hv2-sec">
                    More
                    <span class="gp-hv2-sec-sub">Requests, transfers, feedback.</span>
                </h2>
                <div class="gp-hv2-tiles">
                    <button type="button" wire:click="openTile('reserve')" class="gp-hv2-tile" data-testid="tile-reserve">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🛏️</span>
                        <span>
                            <span class="gp-hv2-tile-name">Reserve room</span>
                            <span class="gp-hv2-tile-sub">Another for a friend.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('transfer')" class="gp-hv2-tile" data-testid="tile-transfer">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🔀</span>
                        <span>
                            <span class="gp-hv2-tile-name">Change room</span>
                            <span class="gp-hv2-tile-sub">Move to a different unit.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('rate')" class="gp-hv2-tile" data-testid="tile-rate">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">⭐</span>
                        <span>
                            <span class="gp-hv2-tile-name">Rate stay</span>
                            <span class="gp-hv2-tile-sub">One tap. It helps.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('requests')" class="gp-hv2-tile" data-testid="tile-requests">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">📋</span>
                        <span>
                            <span class="gp-hv2-tile-name">My requests</span>
                            <span class="gp-hv2-tile-sub">See replies + cancel.</span>
                        </span>
                    </button>
                    <button type="button" wire:click="openTile('checkout')" class="gp-hv2-tile" data-testid="tile-checkout">
                        <span class="gp-hv2-tile-emoji" aria-hidden="true">🔑</span>
                        <span>
                            <span class="gp-hv2-tile-name">Check out</span>
                            <span class="gp-hv2-tile-sub">Ready to leave.</span>
                        </span>
                    </button>
                </div>

                {{-- ── Where we are ────────────────────────────────
                     Shown only when the operator has captured the
                     property's coordinates + at least a street/city
                     address, so a half-configured property doesn't
                     leak the "Not set yet" copy to guests. --}}
                @if ($property && ($property->hasCoordinates() || trim($property->fullAddress()) !== ''))
                    @php
                        $addr = $property->fullAddress();
                        $lat  = (float) ($property->latitude ?? 0);
                        $lng  = (float) ($property->longitude ?? 0);
                        $hasCoords = $property->hasCoordinates();
                        // OpenStreetMap embed - no API key, marker pinned
                        // at exactly the property coords, ~1km viewport.
                        $delta = 0.005;
                        $bbox = ($lng - $delta).','.($lat - $delta).','.($lng + $delta).','.($lat + $delta);
                        $osmSrc = 'https://www.openstreetmap.org/export/embed.html'
                                  .'?bbox='.urlencode($bbox)
                                  .'&layer=mapnik&marker='.$lat.','.$lng;
                        // Universal "open in maps" link - geo: URI hands
                        // off to Apple/Google Maps on the guest's phone.
                        $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='
                                   .($hasCoords ? $lat.','.$lng : urlencode($addr));
                    @endphp
                    <p class="gp-section-title">Where we are</p>
                    <div class="gp-card" data-testid="portal-location"
                         x-data="{
                            addr: @js($addr),
                            mapsUrl: @js($mapsUrl),
                            copied: false,
                            async copy() {
                                try {
                                    await navigator.clipboard.writeText(this.addr);
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 1600);
                                } catch (_) {
                                    // Fallback: select the hidden field so the user can copy manually.
                                    const t = document.createElement('textarea');
                                    t.value = this.addr; document.body.appendChild(t);
                                    t.select(); document.execCommand('copy');
                                    t.remove();
                                    this.copied = true;
                                    setTimeout(() => this.copied = false, 1600);
                                }
                            },
                            async share() {
                                if (navigator.share) {
                                    try {
                                        await navigator.share({
                                            title: 'Where I\'m staying',
                                            text: this.addr,
                                            url: this.mapsUrl,
                                        });
                                    } catch (_) { /* user cancelled */ }
                                } else {
                                    this.copy();
                                }
                            },
                         }">
                        @if ($hasCoords)
                            <div style="border-radius: 14px; overflow: hidden; margin: -4px -4px 12px; border: 1px solid var(--gp-line);">
                                <iframe
                                    src="{{ $osmSrc }}"
                                    loading="lazy" title="Map"
                                    data-testid="portal-map-embed"
                                    style="width: 100%; height: 200px; border: 0; display: block;"
                                    referrerpolicy="no-referrer-when-downgrade"></iframe>
                            </div>
                        @endif

                        @if ($addr !== '')
                            <p style="font-size: 14px; color: var(--gp-ink); line-height: 1.5; margin: 0;">
                                {{ $addr }}
                            </p>
                        @endif

                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
                            <a href="{{ $mapsUrl }}" target="_blank" rel="noopener"
                               data-testid="portal-location-open"
                               style="background: var(--gp-ink); color: var(--gp-paper);
                                      padding: 10px 16px; border-radius: 999px;
                                      font-size: 13px; font-weight: 600;
                                      text-decoration: none;
                                      display: inline-flex; align-items: center; gap: 6px;">
                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none"
                                     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10 2C6.5 2 4 4.5 4 8c0 4 6 10 6 10s6-6 6-10c0-3.5-2.5-6-6-6z"/>
                                    <circle cx="10" cy="8" r="2"/>
                                </svg>
                                Open in maps
                            </a>
                            @if ($addr !== '')
                                <button type="button" @click="copy()"
                                        data-testid="portal-location-copy"
                                        style="background: var(--gp-card-deep); color: var(--gp-ink);
                                               border: 0; padding: 10px 16px; border-radius: 999px;
                                               font: inherit; font-size: 13px; font-weight: 500; cursor: pointer;
                                               display: inline-flex; align-items: center; gap: 6px;">
                                    <template x-if="!copied">
                                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"
                                                 stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="6" y="6" width="10" height="12" rx="2"/>
                                                <path d="M4 14V4a2 2 0 0 1 2-2h8"/>
                                            </svg>
                                            Copy
                                        </span>
                                    </template>
                                    <template x-if="copied">
                                        <span style="display: inline-flex; align-items: center; gap: 6px; color: var(--gp-ok);">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"
                                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M4 10l4 4 8-8"/>
                                            </svg>
                                            Copied
                                        </span>
                                    </template>
                                </button>
                                <button type="button" @click="share()"
                                        data-testid="portal-location-share"
                                        style="background: var(--gp-card-deep); color: var(--gp-ink);
                                               border: 0; padding: 10px 16px; border-radius: 999px;
                                               font: inherit; font-size: 13px; font-weight: 500; cursor: pointer;
                                               display: inline-flex; align-items: center; gap: 6px;">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"
                                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="6" cy="10" r="2"/>
                                        <circle cx="14" cy="5" r="2"/>
                                        <circle cx="14" cy="15" r="2"/>
                                        <path d="M7.7 9L12.3 6M7.7 11L12.3 14"/>
                                    </svg>
                                    Share
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Departure section - check-out prominent, lock discreet. --}}
                <p class="gp-section-title">Ready to leave?</p>
                <button type="button" wire:click="openTile('checkout')"
                        data-testid="tile-checkout"
                        class="gp-btn gp-btn--danger">
                    Check out
                </button>
                <div style="text-align: center; margin-top: 14px;">
                    <button type="button" wire:click="logout"
                            data-testid="portal-logout"
                            style="background: transparent; border: 0; color: var(--gp-ink-mute); cursor: pointer; font: inherit; font-size: 13px; padding: 8px 12px;">
                        Lock portal
                    </button>
                </div>
            </div>
        @endif

        {{-- ── TILE: Extend stay ──────────────────────────────── --}}
        @if ($step === 'tile.extend' && $booking)
            <div class="gp-card" data-testid="portal-tile-extend">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">Extend your stay</h2>
                <p class="gp-help" style="margin: 0 0 14px;">
                    Currently booked through {{ $booking->checkout_date?->format('l, F j, Y') ?? '-' }}. Pick a new check-out date.
                </p>
                @php
                    // Ceiling - the room is booked from this date
                    // onwards for the next guest. Empty when the room
                    // is free-forward, in which case no max is set.
                    $extendMax = $booking->maxExtendableCheckoutDate();
                    $extendMaxStr = $extendMax?->toDateString();
                    // "Extension impossible" - the room is booked
                    // back-to-back so there's no free night to extend
                    // into. Front the guest with a room-change CTA
                    // rather than a datepicker that can't produce a
                    // valid pick.
                    $extendBlocked = $extendMax !== null
                        && $booking->checkout_date !== null
                        && $extendMax->lte($booking->checkout_date);
                @endphp

                @if ($extendBlocked)
                    <div style="padding: 14px; background: #FFF4E5; border-radius: 10px;
                                border: 1px solid #F5D9A8;" data-testid="extend-blocked-notice">
                        <p style="margin: 0; color: var(--gp-ink); font-size: 13.5px; line-height: 1.55;">
                            Another guest is arriving on
                            <strong>{{ $extendMax->format('D, d M') }}</strong>, so we can't extend
                            this room. If you'd like to stay longer, we can move you to another
                            available room.
                        </p>
                        <button type="button" wire:click="openTile('transfer')"
                                class="gp-btn gp-btn--primary"
                                style="margin-top: 12px; width: 100%;"
                                data-testid="extend-blocked-transfer">
                            Ask for a room change &rarr;
                        </button>
                    </div>
                @else
                <label class="gp-form-label">New check-out date</label>
                <input type="date" wire:model.live="extendCheckoutDate"
                       min="{{ $booking->checkout_date?->copy()->addDay()->toDateString() ?? '' }}"
                       @if ($extendMaxStr) max="{{ $extendMaxStr }}" @endif
                       data-testid="extend-date-input" class="gp-input">
                @if ($extendMax)
                    <p class="gp-help" style="margin: 6px 0 0;" data-testid="extend-max-hint">
                        Room is booked from <strong>{{ $extendMax->format('D, d M') }}</strong>
                        onwards - you can extend up to that day.
                    </p>
                @endif

                @php
                    // Breakfast toggle only shows when this room's
                    // category offers the alternative tier. Seeded
                    // from booking->breakfast_included so the guest
                    // isn't silently downgraded on extend.
                    $category = $room?->category;
                    $hasBreakfast = $category?->hasBreakfastOption() ?? false;
                    $baseRate = (int) ($category?->base_rate ?? $room?->base_rate ?? 0);
                    $breakfastRate = (int) ($category?->breakfast_included_rate ?? 0);
                    $extendRate = ($extendBreakfastIncluded && $breakfastRate > 0)
                        ? $breakfastRate
                        : $baseRate;
                @endphp

                @if ($hasBreakfast)
                    <div style="margin-top: 12px; padding: 12px 14px;
                                border: 1px solid var(--gp-line-soft); border-radius: 10px;
                                display: flex; justify-content: space-between; align-items: center; gap: 12px;"
                         data-testid="extend-breakfast-row">
                        <div>
                            <div style="font-size: 13px; color: var(--gp-ink); font-weight: 500;">
                                Include breakfast
                            </div>
                            <div style="font-size: 11.5px; color: var(--gp-ink-mute); margin-top: 3px;">
                                @if ($extendBreakfastIncluded)
                                    Extra nights at ₦{{ number_format($breakfastRate) }}/night.
                                @else
                                    Extra nights at ₦{{ number_format($baseRate) }}/night.
                                @endif
                            </div>
                        </div>
                        <button type="button"
                                wire:click="$toggle('extendBreakfastIncluded')"
                                aria-pressed="{{ $extendBreakfastIncluded ? 'true' : 'false' }}"
                                data-testid="extend-breakfast-toggle"
                                style="width: 42px; height: 24px; border: 0; border-radius: 999px;
                                       position: relative; cursor: pointer; flex-shrink: 0;
                                       transition: background 160ms;
                                       background: {{ $extendBreakfastIncluded ? 'var(--gp-accent, #22C55E)' : '#D6D3D1' }};">
                            <span style="position: absolute; top: 2px; height: 20px; width: 20px;
                                         border-radius: 999px; background: #fff;
                                         transition: left 160ms;
                                         left: {{ $extendBreakfastIncluded ? '20px' : '2px' }};"></span>
                        </button>
                    </div>
                @endif

                @if ($authError !== '')
                    <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                @endif

                @php
                    $extendPreviewAmount = 0;
                    if ($booking->checkout_date && $extendCheckoutDate !== '') {
                        try {
                            $extendPreviewNew = \Illuminate\Support\Carbon::parse($extendCheckoutDate);
                            if ($extendPreviewNew->gt($booking->checkout_date)) {
                                $extendPreviewAmount = (int) $booking->checkout_date->diffInDays($extendPreviewNew)
                                    * $extendRate;
                            }
                        } catch (\Throwable) {}
                    }
                @endphp
                @if ($extendPreviewAmount > 0)
                    <div style="margin-top: 12px; padding: 10px 12px; background: var(--gp-paper); border-radius: 10px; font-size: 13px; color: var(--gp-ink-soft); display: flex; justify-content: space-between; align-items: center;">
                        <span>Extra to pay</span>
                        <span style="font-family: 'SF Mono', monospace; color: var(--gp-ink); font-weight: 600;">
                            ₦{{ number_format($extendPreviewAmount) }}
                        </span>
                    </div>
                @endif

                <div style="display: grid; gap: 8px; margin-top: 16px;">
                    <button type="button" wire:click="submitExtendPayByCard" class="gp-btn gp-btn--primary"
                            data-testid="extend-pay-card"
                            wire:loading.attr="disabled" wire:target="submitExtendPayByCard">
                        <span wire:loading.remove wire:target="submitExtendPayByCard">Pay now with card</span>
                        <span wire:loading wire:target="submitExtendPayByCard">Redirecting to Paystack…</span>
                    </button>
                    <button type="button" wire:click="submitExtend" class="gp-btn gp-btn--ghost"
                            data-testid="extend-submit">
                        Charge to my room (pay at check-out)
                    </button>
                </div>
                @endif {{-- /extend-blocked --}}
            </div>
        @endif

        {{-- ── TILE: Check out ────────────────────────────────── --}}
        @if ($step === 'tile.checkout' && $booking)
            <div class="gp-card" data-testid="portal-tile-checkout">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px; color: var(--gp-danger);">Check out</h2>
                <p class="gp-help" style="margin: 0 0 14px;">
                    Any pending balance must be settled before we can complete check-out. If there's money owed, please see the front desk.
                </p>
                <label class="gp-form-label">Type <span class="num" style="font-family: 'SF Mono', monospace; letter-spacing: 0.06em;">check out</span> to confirm</label>
                <input type="text" wire:model="checkoutConfirm" data-testid="checkout-confirm-input" class="gp-input" placeholder="check out">
                @if ($authError !== '')
                    <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                @endif
                <button type="button" wire:click="submitCheckout" class="gp-btn gp-btn--danger" data-testid="checkout-submit"
                        style="margin-top: 16px;">Complete check-out</button>
            </div>
        @endif

        @if ($step === 'checkout.done')
            <div class="gp-card" data-testid="portal-checkout-done" style="text-align: center;">
                <h2 style="margin: 0 0 6px; font-size: 20px;">You're checked out</h2>
                <p style="font-size: 14px; color: var(--gp-ink-soft); line-height: 1.5;">
                    Safe travels - we hope to see you again.
                </p>
            </div>
        @endif

        {{-- ── TILE: Rate stay ────────────────────────────────── --}}
        @if ($step === 'tile.rate' && $booking)
            <div class="gp-card" data-testid="portal-tile-rate">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">How was your stay?</h2>
                <div class="gp-stars" data-testid="rating-stars">
                    @for ($i = 1; $i <= 5; $i++)
                        <button type="button"
                                wire:click="$set('ratingStars', {{ $i }})"
                                class="gp-star {{ $ratingStars >= $i ? 'is-on' : '' }}"
                                data-testid="rating-star-{{ $i }}">★</button>
                    @endfor
                </div>
                <label class="gp-form-label" style="margin-top: 12px;">A quick note (optional)</label>
                <textarea wire:model="ratingBody" rows="3" maxlength="500"
                          data-testid="rating-body-input" class="gp-textarea"
                          placeholder="What went well? What could be better?"></textarea>
                <button type="button" wire:click="submitRating" class="gp-btn gp-btn--primary" data-testid="rating-submit"
                        style="margin-top: 16px;">Send rating</button>
            </div>
        @endif

        {{-- ── TILE: Complaint (creates a GuestRequest) ───────── --}}
        @if ($step === 'tile.complaint' && $booking)
            <div class="gp-card" data-testid="portal-tile-complaint">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">Make a complaint</h2>
                <p class="gp-help" style="margin: 0 0 14px;">
                    Sent straight to the front desk - the manager will get a notification.
                </p>
                <textarea wire:model="requestNotes" rows="4" maxlength="1000"
                          data-testid="complaint-body-input" class="gp-textarea"
                          placeholder="Tell us what's wrong - the more detail the better."></textarea>
                <button type="button" wire:click="submitRequest('complaint')" class="gp-btn gp-btn--primary"
                        data-testid="complaint-submit" style="margin-top: 16px;">Send complaint</button>
            </div>
        @endif

        {{-- ── TILE: Taxi ─────────────────────────────────────── --}}
        @if ($step === 'tile.taxi' && $booking)
            <div class="gp-card" data-testid="portal-tile-taxi">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">Request a taxi</h2>
                <label class="gp-form-label" style="margin-top: 8px;">When</label>
                <input type="text" wire:model="taxiWhen" data-testid="taxi-when-input" class="gp-input"
                       placeholder="e.g. Right now, in 30 minutes, tomorrow 9am">
                <label class="gp-form-label" style="margin-top: 12px;">Where to</label>
                <input type="text" wire:model="taxiDestination" data-testid="taxi-destination-input" class="gp-input"
                       placeholder="e.g. Murtala Muhammed Airport">
                <label class="gp-form-label" style="margin-top: 12px;">Notes (optional)</label>
                <textarea wire:model="requestNotes" rows="2" maxlength="500"
                          data-testid="taxi-notes-input" class="gp-textarea"
                          placeholder="e.g. Prefer a saloon car, need child seat"></textarea>
                <button type="button" wire:click="submitRequest('taxi')" class="gp-btn gp-btn--primary"
                        data-testid="taxi-submit" style="margin-top: 16px;">Request taxi</button>
            </div>
        @endif

        {{-- ── TILE: Transfer to another room ─────────────────── --}}
        @if ($step === 'tile.transfer' && $booking)
            <div class="gp-card" data-testid="portal-tile-transfer">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">Change room</h2>
                <p class="gp-help" style="margin: 0 0 14px;">
                    Front desk will confirm availability + quote any price difference before moving your booking.
                </p>
                @if ($categories->count() > 0)
                    <label class="gp-form-label">Preferred category</label>
                    <select wire:model="transferTargetCategoryId" data-testid="transfer-category-select" class="gp-select">
                        <option value="0">- any available -</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }} · ₦{{ number_format((int) $cat->base_rate) }}/night</option>
                        @endforeach
                    </select>
                @endif
                <label class="gp-form-label" style="margin-top: 12px;">Why (optional)</label>
                <textarea wire:model="requestNotes" rows="3" maxlength="500"
                          data-testid="transfer-notes-input" class="gp-textarea"
                          placeholder="e.g. Room is too noisy, prefer a higher floor"></textarea>
                <button type="button" wire:click="submitRequest('transfer')" class="gp-btn gp-btn--primary"
                        data-testid="transfer-submit" style="margin-top: 16px;">Send request</button>
            </div>
        @endif

        {{-- ── TILE: Order (POS-backed) ─────────────────────────
             The outlet dropdown lists every outlet on this branch
             that has active products. Products render as +/- steppers
             so the cart shape stays simple even with lots of items.
             Charge-to-room uses PosSale tender=room (folio at check-
             out); Pay-by-card kicks off Paystack via the same
             plumbing extend + reserve use. --}}
        @if ($step === 'tile.order' && $booking)
            <div class="gp-card" data-testid="portal-tile-order">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">Order</h2>

                @if ($outlets->isEmpty())
                    <p class="gp-help">
                        No outlets are set up here yet. Ask the front desk directly.
                    </p>
                @else
                    <label class="gp-form-label">Outlet</label>
                    <select class="gp-select" wire:change="switchOrderOutlet($event.target.value)"
                            data-testid="order-outlet-select">
                        @foreach ($outlets as $out)
                            <option value="{{ $out->id }}" @selected($orderOutletId === $out->id)>{{ $out->name }}</option>
                        @endforeach
                    </select>

                    <div style="margin-top: 14px; display: grid; gap: 8px;" data-testid="order-menu">
                        @foreach ($products as $p)
                            @php $qty = (int) ($orderCart[$p->id] ?? 0); @endphp
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--gp-line); border-radius: 12px; background: var(--gp-card);"
                                 data-testid="order-product-{{ $p->id }}">
                                <div style="flex: 1;">
                                    <div style="font-size: 14px; font-weight: 500; color: var(--gp-ink);">{{ $p->name }}</div>
                                    <div class="gp-help" style="font-size: 11.5px; margin-top: 2px;">
                                        ₦{{ number_format((int) $p->price) }}
                                        @if ($p->description) · {{ \Illuminate\Support\Str::limit($p->description, 60) }} @endif
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <button type="button" wire:click="bumpCart({{ $p->id }}, -1)"
                                            style="width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--gp-line); background: transparent; cursor: pointer; font-size: 16px; color: var(--gp-ink);"
                                            @disabled($qty <= 0)
                                            data-testid="order-dec-{{ $p->id }}">−</button>
                                    <span style="min-width: 20px; text-align: center; font-family: 'SF Mono', monospace; font-size: 14px;"
                                          data-testid="order-qty-{{ $p->id }}">{{ $qty }}</span>
                                    <button type="button" wire:click="bumpCart({{ $p->id }}, 1)"
                                            style="width: 30px; height: 30px; border-radius: 50%; border: 1px solid var(--gp-line); background: var(--gp-paper); cursor: pointer; font-size: 16px; color: var(--gp-ink);"
                                            data-testid="order-inc-{{ $p->id }}">+</button>
                                </div>
                            </div>
                        @endforeach
                        @if ($products->isEmpty())
                            <p class="gp-help">No items listed for this outlet.</p>
                        @endif
                    </div>

                    <label class="gp-form-label" style="margin-top: 14px;">Notes (allergies, prep)</label>
                    <textarea wire:model="orderNotes" rows="2" maxlength="500"
                              data-testid="order-notes-input" class="gp-textarea"
                              placeholder="e.g. no onions, well done"></textarea>

                    @php $orderTotal = $this->orderCartTotal(); @endphp
                    @if ($orderTotal > 0)
                        <div style="margin-top: 12px; padding: 10px 12px; background: var(--gp-paper); border-radius: 10px; font-size: 13px; color: var(--gp-ink-soft); display: flex; justify-content: space-between; align-items: center;">
                            <span>Cart total</span>
                            <span style="font-family: 'SF Mono', monospace; color: var(--gp-ink); font-weight: 600;">
                                ₦{{ number_format($orderTotal) }}
                            </span>
                        </div>
                    @endif

                    @if ($authError !== '')
                        <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                    @endif

                    <div style="display: grid; gap: 8px; margin-top: 16px;">
                        <button type="button" wire:click="submitOrderPayByCard"
                                class="gp-btn gp-btn--primary" data-testid="order-pay-card"
                                @disabled($orderTotal === 0)
                                wire:loading.attr="disabled" wire:target="submitOrderPayByCard">
                            <span wire:loading.remove wire:target="submitOrderPayByCard">Pay now with card</span>
                            <span wire:loading wire:target="submitOrderPayByCard">Redirecting to Paystack…</span>
                        </button>
                        <button type="button" wire:click="submitOrderChargeToRoom"
                                class="gp-btn gp-btn--ghost" data-testid="order-charge-room"
                                @disabled($orderTotal === 0)>
                            Charge to my room (pay at check-out)
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- ── TILE: Reserve another room ─────────────────────── --}}
        @if ($step === 'tile.reserve' && $booking)
            <div class="gp-card" data-testid="portal-tile-reserve">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">Reserve another room</h2>
                <p class="gp-help" style="margin: 0 0 14px;">
                    Book a room right now, pay by card. We'll link it to your name so
                    the front desk knows it's from you.
                </p>

                @if ($categories->isEmpty())
                    <p class="gp-help" style="margin: 0;">
                        No room categories to book here right now - ask the front desk.
                    </p>
                @else
                    <label class="gp-form-label">Category</label>
                    <select wire:model.live="reserveCategoryId" data-testid="reserve-category-select" class="gp-select">
                        <option value="0">- pick a category -</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">
                                {{ $cat->name }} · ₦{{ number_format((int) $cat->base_rate) }}/night · sleeps {{ (int) $cat->capacity_adults }}
                            </option>
                        @endforeach
                    </select>

                    <div style="display: grid; gap: 8px; margin-top: 12px; grid-template-columns: 1fr 1fr;">
                        <div>
                            <label class="gp-form-label">Check-in</label>
                            <input type="date" wire:model.live="reserveCheckin"
                                   min="{{ \Illuminate\Support\Carbon::today()->toDateString() }}"
                                   data-testid="reserve-checkin-input" class="gp-input">
                        </div>
                        <div>
                            <label class="gp-form-label">Check-out</label>
                            <input type="date" wire:model.live="reserveCheckout"
                                   min="{{ \Illuminate\Support\Carbon::tomorrow()->toDateString() }}"
                                   data-testid="reserve-checkout-input" class="gp-input">
                        </div>
                        <div>
                            <label class="gp-form-label">Adults</label>
                            <input type="number" min="1" max="8" wire:model="reserveAdults"
                                   data-testid="reserve-adults-input" class="gp-input">
                        </div>
                        <div>
                            <label class="gp-form-label">Children</label>
                            <input type="number" min="0" max="8" wire:model="reserveChildren"
                                   data-testid="reserve-children-input" class="gp-input">
                        </div>
                    </div>

                    @php $reservePreview = $this->reservePreviewAmount(); @endphp
                    @if ($reservePreview > 0)
                        <div style="margin-top: 12px; padding: 10px 12px; background: var(--gp-paper); border-radius: 10px; font-size: 13px; color: var(--gp-ink-soft); display: flex; justify-content: space-between; align-items: center;">
                            <span>Total to pay</span>
                            <span style="font-family: 'SF Mono', monospace; color: var(--gp-ink); font-weight: 600;">
                                ₦{{ number_format($reservePreview) }}
                            </span>
                        </div>
                    @endif

                    @if ($authError !== '')
                        <div class="gp-error" style="margin-top: 12px;">{{ $authError }}</div>
                    @endif

                    <button type="button" wire:click="submitReservePayByCard"
                            class="gp-btn gp-btn--primary" data-testid="reserve-pay-card"
                            style="margin-top: 16px;"
                            wire:loading.attr="disabled" wire:target="submitReservePayByCard">
                        <span wire:loading.remove wire:target="submitReservePayByCard">Book & pay by card</span>
                        <span wire:loading wire:target="submitReservePayByCard">Redirecting to Paystack…</span>
                    </button>
                @endif
            </div>
        @endif

        {{-- ── TILE: My requests ──────────────────────────────── --}}
        @if ($step === 'tile.requests' && $booking)
            <div class="gp-card" data-testid="portal-tile-requests">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 style="margin: 0 0 6px; font-size: 18px;">My requests</h2>
                <p class="gp-help" style="margin: 0 0 14px;">
                    Everything you've sent from this portal on stay {{ $booking->booking_ref }}.
                    Cancel anything the front desk hasn't picked up yet.
                </p>

                @if ($myRequests->isEmpty())
                    <div style="padding: 20px 12px; text-align: center; color: var(--gp-ink-mute); font-size: 13px;"
                         data-testid="portal-requests-empty">
                        You haven't sent any requests yet.
                    </div>
                @else
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px;">
                        @foreach ($myRequests as $r)
                            @php
                                $statusLabel = match ($r->status) {
                                    'resolved'  => 'Resolved',
                                    'cancelled' => 'Cancelled',
                                    default     => 'Open',
                                };
                                $statusColor = match ($r->status) {
                                    'resolved'  => '#3D7B4F',
                                    'cancelled' => 'var(--gp-ink-mute)',
                                    default     => '#A8650A',
                                };
                            @endphp
                            <li style="border: 1px solid var(--gp-line-soft); border-radius: 10px; padding: 12px 14px;"
                                data-testid="portal-request-{{ $r->id }}">
                                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 12px;">
                                    <div style="min-width: 0;">
                                        <div style="font-size: 13px; font-weight: 600; color: var(--gp-ink);">
                                            {{ $r->kind->label() }}
                                        </div>
                                        @if ($r->notes)
                                            <div style="font-size: 12px; color: var(--gp-ink-soft); margin-top: 3px; white-space: pre-line;">
                                                {{ $r->notes }}
                                            </div>
                                        @endif
                                        <div style="font-size: 11px; color: var(--gp-ink-mute); margin-top: 4px;">
                                            {{ $r->created_at?->diffForHumans() }}
                                        </div>
                                    </div>
                                    <span style="font-size: 11px; font-weight: 600; color: {{ $statusColor }}; white-space: nowrap;">
                                        {{ $statusLabel }}
                                    </span>
                                </div>
                                @if ($r->messages->isNotEmpty())
                                    <div style="margin-top: 10px; padding-top: 10px;
                                                border-top: 1px dashed var(--gp-line-soft);
                                                display: flex; flex-direction: column; gap: 6px;"
                                         data-testid="portal-request-messages-{{ $r->id }}">
                                        @foreach ($r->messages as $m)
                                            <div style="{{ $m->isFromOperator()
                                                            ? 'align-self: flex-start; max-width: 90%;'
                                                            : 'align-self: flex-end; max-width: 90%;' }}">
                                                <div style="{{ $m->isFromOperator()
                                                                ? 'background: var(--gp-card-deep, #F5F1E8); color: var(--gp-ink);'
                                                                : 'background: var(--gp-ink, #14171A); color: var(--gp-paper, #FFF);' }}
                                                            padding: 8px 12px; border-radius: 12px;
                                                            font-size: 13px; line-height: 1.5; white-space: pre-line;"
                                                     data-testid="portal-message-{{ $m->id }}">
                                                    {{ $m->message }}
                                                </div>
                                                <div style="font-size: 10.5px; color: var(--gp-ink-mute); margin-top: 2px;">
                                                    {{ $m->isFromOperator() ? 'Front desk' : 'You' }}
                                                    · {{ $m->created_at?->diffForHumans() }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($r->status === 'open')
                                    <button type="button"
                                            wire:click="cancelRequest({{ $r->id }})"
                                            wire:confirm="Cancel this request? The front desk won't act on it."
                                            data-testid="portal-request-cancel-{{ $r->id }}"
                                            style="margin-top: 10px; background: transparent;
                                                   color: var(--gp-danger, #B85C42);
                                                   border: 1px solid var(--gp-danger, #B85C42);
                                                   padding: 6px 14px; border-radius: 999px;
                                                   font-family: inherit; font-size: 12px; font-weight: 500;
                                                   cursor: pointer;">
                                        Cancel this request
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- ─── Housekeeping tile ────────────────────────────────
             Simple request form. Optional notes; submit lands as a
             GuestRequest with kind=Custom + body.subkind=housekeeping
             so the operator queue can label it correctly. --}}
        @if ($step === 'tile.housekeeping' && $booking)
            <div class="gp-card" data-testid="portal-step-housekeeping">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 class="gp-h2" style="margin-top: 8px;">
                    <span aria-hidden="true">🧹</span> Housekeeping visit
                </h2>
                <p class="gp-lead">
                    We'll send someone up as soon as the shift's free. Anything
                    specific we should know? (e.g. "just the sheets", "please
                    skip the bathroom today")
                </p>
                <form wire:submit.prevent="submitCustomRequest('housekeeping')" class="gp-form">
                    <label for="hk-notes" class="gp-form-label">Notes <span class="gp-form-opt">optional</span></label>
                    <textarea id="hk-notes" wire:model="requestNotes" rows="3"
                              placeholder="e.g. only towels and a new tea kettle, please."
                              class="gp-input" style="font-size: 14px;"></textarea>
                    <button type="submit" class="gp-btn" data-testid="hk-submit"
                            wire:loading.attr="disabled" wire:target="submitCustomRequest">
                        <span wire:loading.remove wire:target="submitCustomRequest">Send request</span>
                        <span wire:loading wire:target="submitCustomRequest">Sending…</span>
                    </button>
                </form>
            </div>
        @endif

        {{-- ─── Laundry tile ─────────────────────────────────────
             Same shape as housekeeping. GuestRequest kind=Custom +
             body.subkind=laundry. Copy nudges the guest to spell out
             pickup timing / special-care items in the notes. --}}
        @if ($step === 'tile.laundry' && $booking)
            <div class="gp-card" data-testid="portal-step-laundry">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 class="gp-h2" style="margin-top: 8px;">
                    <span aria-hidden="true">🧺</span> Laundry pickup
                </h2>
                <p class="gp-lead">
                    Same-day return where possible. Bag your clothes and we'll
                    swing by. Charged to your room folio at collection.
                </p>
                <form wire:submit.prevent="submitCustomRequest('laundry')" class="gp-form">
                    <label for="ln-notes" class="gp-form-label">Notes <span class="gp-form-opt">optional</span></label>
                    <textarea id="ln-notes" wire:model="requestNotes" rows="3"
                              placeholder="e.g. 3 shirts + 2 trousers, dry-clean the blazer, need by 6pm."
                              class="gp-input" style="font-size: 14px;"></textarea>
                    <button type="submit" class="gp-btn" data-testid="ln-submit"
                            wire:loading.attr="disabled" wire:target="submitCustomRequest">
                        <span wire:loading.remove wire:target="submitCustomRequest">Send request</span>
                        <span wire:loading wire:target="submitCustomRequest">Sending…</span>
                    </button>
                </form>
            </div>
        @endif

        {{-- ─── View bill / pay ───────────────────────────────────
             Read-only folio view + a Paystack "Pay balance" button
             when there's an outstanding total. Uses the same
             InitiatePortalPayment path the extend-stay tile drives. --}}
        @if ($step === 'tile.bill' && $booking)
            @php
                $billOutstanding = (int) $booking->outstandingTotal();
                $billCharges = $booking->charges()->latest('id')->get();
            @endphp
            <div class="gp-card" data-testid="portal-step-bill">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 class="gp-h2" style="margin-top: 8px;">
                    <span aria-hidden="true">🧾</span> Your bill
                </h2>
                <p class="gp-lead">
                    Room, extras, service charges. What's owed adds up on the
                    balance below. Pay any time with the card on file.
                </p>

                <div style="margin-top: 16px; padding: 16px 18px;
                            background: var(--gp-card-deep); border-radius: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline;">
                        <span style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                     letter-spacing: 0.16em; text-transform: uppercase;
                                     color: var(--gp-ink-mute);">
                            Room
                        </span>
                        <span class="gp-num" style="font-size: 14px; color: var(--gp-ink);">
                            ₦{{ number_format((int) $booking->room_total) }}
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between;
                                align-items: baseline; margin-top: 6px;">
                        <span style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                     letter-spacing: 0.16em; text-transform: uppercase;
                                     color: var(--gp-ink-mute);">
                            Nights &middot; rate
                        </span>
                        <span style="font-size: 13.5px; color: var(--gp-ink-soft);">
                            {{ $booking->nights }} &times; ₦{{ number_format(intdiv((int) $booking->room_total, max(1, (int) $booking->nights))) }}
                        </span>
                    </div>
                </div>

                @if ($billCharges->isNotEmpty())
                    <h3 style="margin-top: 20px; margin-bottom: 8px;
                               font-family: 'Fraunces', Georgia, serif;
                               font-size: 15px; font-weight: 600; color: var(--gp-ink);">
                        Extras &amp; charges
                    </h3>
                    <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 8px;">
                        @foreach ($billCharges as $charge)
                            <li style="display: flex; justify-content: space-between; align-items: baseline;
                                       gap: 12px; padding: 10px 12px;
                                       background: var(--gp-card-deep); border-radius: 10px;
                                       font-size: 13px;">
                                <div>
                                    <div style="color: var(--gp-ink); font-weight: 500;">
                                        {{ $charge->description }}
                                    </div>
                                    <div style="font-size: 11px; color: var(--gp-ink-mute); margin-top: 2px;">
                                        {{ $charge->created_at?->format('j M · g:i A') }}
                                        {{ $charge->settled ? '· settled' : '· open' }}
                                    </div>
                                </div>
                                <span class="gp-num" style="color: var(--gp-ink);">
                                    ₦{{ number_format((int) $charge->amount) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div style="margin-top: 22px; padding: 16px 18px;
                            background: {{ $billOutstanding > 0 ? 'rgba(184, 92, 66, 0.06)' : 'rgba(76, 149, 108, 0.08)' }};
                            border: 1px solid {{ $billOutstanding > 0 ? 'rgba(184, 92, 66, 0.28)' : 'rgba(76, 149, 108, 0.32)' }};
                            border-radius: 12px;
                            display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <div>
                        <div style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                    letter-spacing: 0.16em; text-transform: uppercase;
                                    color: var(--gp-ink-mute);">
                            Balance owed
                        </div>
                        <div style="font-family: 'Fraunces', Georgia, serif; font-size: 22px;
                                    font-weight: 600; color: {{ $billOutstanding > 0 ? '#B85C42' : '#4C956C' }};
                                    margin-top: 2px; letter-spacing: -0.01em;">
                            @if ($billOutstanding > 0)
                                ₦{{ number_format($billOutstanding) }}
                            @else
                                ✓ Cleared
                            @endif
                        </div>
                    </div>
                    @if ($billOutstanding > 0)
                        {{-- Dedicated PURPOSE_BALANCE flow: creates a portal
                             intent for the exact outstanding amount + hands
                             the guest off to Paystack. Resolver has no
                             follow-up action, marking the Payment succeeded
                             is what drops the outstanding total. --}}
                        <button type="button" wire:click="submitBillPayByCard" class="gp-btn"
                                data-testid="portal-pay-balance"
                                wire:loading.attr="disabled"
                                wire:loading.class="is-loading"
                                style="background: var(--gp-ink); color: var(--gp-paper);
                                       padding: 12px 18px;">
                            <span wire:loading.remove wire:target="submitBillPayByCard">
                                💳 Pay ₦{{ number_format($billOutstanding) }}
                            </span>
                            <span wire:loading wire:target="submitBillPayByCard">
                                Redirecting to Paystack…
                            </span>
                        </button>
                    @endif
                </div>
            </div>
        @endif

        {{-- ─── Property info ─────────────────────────────────────
             Static info sheet driven off Property model fields.
             Missing fields silently hide their rows so a half-set
             property doesn't leak "not set" placeholders. --}}
        @if ($step === 'tile.info' && $property)
            @php
                $rules = is_array($property->house_rules) ? $property->house_rules : [];
                $wifi = $rules['wifi'] ?? null;
                $wifiPass = $rules['wifi_password'] ?? null;
                $breakfastFrom = $rules['check_in_from'] ?? null;
                $breakfastTo = $rules['check_in_to'] ?? null;
                $checkoutBy = $rules['check_out_to'] ?? null;
                $quietHours = $rules['quiet_hours'] ?? null;
                $smoking = $rules['smoking_policy'] ?? null;
                $pets = $rules['pets_policy'] ?? null;
                $children = $rules['children_policy'] ?? null;
            @endphp
            <div class="gp-card" data-testid="portal-step-info">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 class="gp-h2" style="margin-top: 8px;">
                    <span aria-hidden="true">📍</span> Property info
                </h2>
                <p class="gp-lead">
                    Everything you might need to know while you're here.
                </p>

                <div style="display: grid; gap: 10px; margin-top: 16px;">
                    @if ($wifi || $wifiPass)
                        <div style="padding: 14px 16px; background: var(--gp-card-deep); border-radius: 12px;">
                            <div style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                        letter-spacing: 0.16em; text-transform: uppercase;
                                        color: var(--gp-ink-mute);">
                                📶 WiFi
                            </div>
                            @if ($wifi)
                                <div style="font-size: 14.5px; color: var(--gp-ink); margin-top: 4px;">
                                    Network: <strong>{{ $wifi }}</strong>
                                </div>
                            @endif
                            @if ($wifiPass)
                                <div style="font-family: ui-monospace, monospace; font-size: 14.5px;
                                            color: var(--gp-ink); margin-top: 2px;">
                                    Password: <strong>{{ $wifiPass }}</strong>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($checkoutBy)
                        <div style="padding: 14px 16px; background: var(--gp-card-deep); border-radius: 12px;">
                            <div style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                        letter-spacing: 0.16em; text-transform: uppercase;
                                        color: var(--gp-ink-mute);">
                                🔑 Check-out
                            </div>
                            <div style="font-size: 14.5px; color: var(--gp-ink); margin-top: 4px;">
                                By <strong>{{ $checkoutBy }}</strong>
                                @if (! empty($rules['check_out_from']))
                                    <span style="color: var(--gp-ink-mute);">
                                        (earliest {{ $rules['check_out_from'] }})
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($quietHours)
                        <div style="padding: 14px 16px; background: var(--gp-card-deep); border-radius: 12px;">
                            <div style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                        letter-spacing: 0.16em; text-transform: uppercase;
                                        color: var(--gp-ink-mute);">
                                🌙 Quiet hours
                            </div>
                            <div style="font-size: 14.5px; color: var(--gp-ink); margin-top: 4px;">
                                {{ $quietHours }}
                            </div>
                        </div>
                    @endif

                    @foreach ([
                        ['🚭', 'Smoking', $smoking],
                        ['🐾', 'Pets', $pets],
                        ['👶', 'Children', $children],
                    ] as [$emoji, $label, $policy])
                        @if ($policy)
                            <div style="padding: 14px 16px; background: var(--gp-card-deep); border-radius: 12px;">
                                <div style="font-family: ui-monospace, monospace; font-size: 10.5px;
                                            letter-spacing: 0.16em; text-transform: uppercase;
                                            color: var(--gp-ink-mute);">
                                    {{ $emoji }} {{ $label }}
                                </div>
                                <div style="font-size: 13.5px; color: var(--gp-ink); margin-top: 4px; line-height: 1.5;">
                                    {{ $policy }}
                                </div>
                            </div>
                        @endif
                    @endforeach

                    @if (empty($wifi) && empty($checkoutBy) && empty($quietHours) &&
                         empty($smoking) && empty($pets) && empty($children))
                        <p style="color: var(--gp-ink-mute); text-align: center; padding: 20px;">
                            The front desk hasn't published property info yet.
                            Give reception a call. They'll answer anything.
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- ─── Contact reception ─────────────────────────────────
             Call button (tel:) + WhatsApp deep-link when the
             property has a WhatsApp number configured. --}}
        @if ($step === 'tile.contact' && $property)
            @php
                $phone = $property->contact_phone ?? $property->tenant?->phone ?? null;
                $waPhone = preg_replace('/\D/', '', (string) $phone);
                $waLink = $phone
                    ? 'https://wa.me/'.ltrim($waPhone, '0')
                    : null;
            @endphp
            <div class="gp-card" data-testid="portal-step-contact">
                <button type="button" wire:click="backToHome" class="gp-back">&larr; back</button>
                <h2 class="gp-h2" style="margin-top: 8px;">
                    <span aria-hidden="true">📞</span> Contact reception
                </h2>
                <p class="gp-lead">
                    Prefer to talk to a human? Tap to call or WhatsApp the
                    front desk directly.
                </p>

                @if ($phone)
                    <div style="display: grid; gap: 10px; margin-top: 18px;">
                        <a href="tel:{{ $phone }}"
                           data-testid="portal-contact-call"
                           style="display: flex; align-items: center; justify-content: space-between;
                                  padding: 16px 20px;
                                  background: var(--gp-ink); color: var(--gp-paper);
                                  border-radius: 14px; text-decoration: none;
                                  font-size: 15px; font-weight: 500;">
                            <span style="display: inline-flex; align-items: center; gap: 12px;">
                                <span style="font-size: 22px;" aria-hidden="true">📞</span>
                                <span>
                                    Call reception
                                    <span style="display: block; font-family: ui-monospace, monospace;
                                                 font-size: 12px; font-weight: 400;
                                                 color: rgba(247, 243, 234, 0.7); margin-top: 2px;">
                                        {{ $phone }}
                                    </span>
                                </span>
                            </span>
                            <span aria-hidden="true">→</span>
                        </a>

                        @if ($waLink)
                            <a href="{{ $waLink }}" target="_blank" rel="noopener"
                               data-testid="portal-contact-whatsapp"
                               style="display: flex; align-items: center; justify-content: space-between;
                                      padding: 16px 20px;
                                      background: #25D366; color: #FFFFFF;
                                      border-radius: 14px; text-decoration: none;
                                      font-size: 15px; font-weight: 500;">
                                <span style="display: inline-flex; align-items: center; gap: 12px;">
                                    <span style="font-size: 22px;" aria-hidden="true">💬</span>
                                    <span>
                                        WhatsApp reception
                                        <span style="display: block; font-size: 12px; font-weight: 400;
                                                     color: rgba(255, 255, 255, 0.85); margin-top: 2px;">
                                            Faster for a quick question
                                        </span>
                                    </span>
                                </span>
                                <span aria-hidden="true">→</span>
                            </a>
                        @endif
                    </div>
                @else
                    <p style="color: var(--gp-ink-mute); text-align: center; padding: 20px;
                              margin-top: 12px;">
                        Reception hasn't published a contact number yet.
                        Try the front desk directly.
                    </p>
                @endif

                <div style="margin-top: 22px; padding: 14px 16px;
                            background: var(--gp-card-deep); border-radius: 10px;
                            font-size: 12.5px; color: var(--gp-ink-mute); line-height: 1.55;">
                    Reception hours vary. If nobody answers, use the
                    <button type="button" wire:click="openTile('complaint')"
                            style="background: transparent; border: 0; padding: 0; cursor: pointer;
                                   color: var(--gp-ink); font-weight: 500; text-decoration: underline;
                                   font: inherit; font-size: 12.5px;">
                        Request service
                    </button>
                    tile, the on-duty manager gets a live notification.
                </div>
            </div>
        @endif

        <div class="gp-brand">
            <p style="font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase; margin: 0;">
                Powered by
                <a href="https://keverna.com" style="color: var(--gp-ink-soft); text-decoration: underline; text-underline-offset: 3px;">Keverna</a>
            </p>
        </div>

        <template x-if="toast">
            <div class="gp-toast" data-testid="portal-toast" x-text="toast"></div>
        </template>
    </div>
</div>
