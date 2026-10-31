{{--
    Floating WhatsApp button to the software's support line, on the staff
    sign-in pages. The number is SUPPORT_WHATSAPP (config
    keverna.support_whatsapp, digits with country code, no plus); with no
    number set the button isn't shown. It used to point at Keverna's
    support numbers (docs/MULTITENANCY.md, step 6).

    Positioning avoids stacking on top of the cookie-consent banner
    (bottom-right on desktop, full width on mobile) by lifting a hair
    higher and adding a wider safe-area margin.
--}}
@php
    $waNumber = preg_replace('/\D+/', '', (string) config('keverna.support_whatsapp'));
    $brand = (string) config('brand.name');
    $context = $context ?? 'auth';
    $waMessage = match ($context) {
        'portal' => "Hi {$brand} support, I have a question about the corporate portal.",
        default => "Hi {$brand} support, I'm having trouble signing in and need a hand.",
    };
    $waHref = 'https://wa.me/'.$waNumber.'?text='.rawurlencode($waMessage);
@endphp
@if ($waNumber !== '')

<style>
    .kv-wa-float {
        position: fixed;
        bottom: max(24px, env(safe-area-inset-bottom, 24px));
        right: 24px;
        z-index: 90;
        display: inline-flex; align-items: center; justify-content: center;
        width: 60px; height: 60px; border-radius: 50%;
        /* Brand-blue gradient (Centurion), not WhatsApp green — the
           glyph carries the meaning. */
        background: linear-gradient(140deg, #3F78AE 0%, #285480 100%);
        color: #FFFFFF;
        box-shadow:
            0 12px 32px -10px rgba(40, 84, 128, 0.42),
            0 4px 12px -4px rgba(20, 17, 12, 0.18),
            inset 0 0 0 1px rgba(255, 255, 255, 0.14);
        text-decoration: none;
        transition: transform 200ms cubic-bezier(.2, .8, .2, 1),
                    box-shadow 200ms;
    }
    .kv-wa-float:hover,
    .kv-wa-float:focus-visible {
        outline: none;
        transform: translateY(-2px) scale(1.03);
        box-shadow:
            0 18px 40px -12px rgba(75, 92, 34, 0.55),
            0 8px 18px -6px rgba(20, 17, 12, 0.22),
            inset 0 0 0 1px rgba(255, 255, 255, 0.18);
    }
    .kv-wa-float:active { transform: translateY(0) scale(0.98); }
    .kv-wa-float svg { width: 30px; height: 30px; }

    /* Soft, breathing halo underneath the rings for depth. */
    .kv-wa-float::before {
        content: ''; position: absolute; inset: -6px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(51, 103, 153, 0.35) 0%, transparent 60%);
        z-index: -1;
        animation: kv-wa-pulse 2600ms ease-in-out infinite;
    }
    @keyframes kv-wa-pulse {
        0%, 100% { transform: scale(1); opacity: 0.55; }
        50%      { transform: scale(1.15); opacity: 0.28; }
    }

    /* Expanding rings — two staggered concentric olive rings that
       ripple outward from the button. Positioned absolutely and
       centered on the anchor. Pointer-events off so they don't
       eat the tap target. */
    .kv-wa-ring {
        position: absolute; inset: 0;
        border-radius: 50%;
        border: 2px solid #336799;
        z-index: -1;
        opacity: 0;
        pointer-events: none;
        animation: kv-wa-ring 2400ms cubic-bezier(0.16, 0.84, 0.44, 1) infinite;
    }
    .kv-wa-ring--delay { animation-delay: 1200ms; }
    @keyframes kv-wa-ring {
        0%   { transform: scale(1);    opacity: 0.7; border-width: 2px; }
        70%  { opacity: 0.15; border-width: 1px; }
        100% { transform: scale(1.85); opacity: 0;   border-width: 0.5px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .kv-wa-float::before { animation: none; opacity: 0.35; }
        .kv-wa-ring { animation: none; opacity: 0; }
        .kv-wa-float:hover { transform: none; }
    }

    /* Compact hover label — desktop-only, hidden on touch to avoid the
       double-tap dance. */
    .kv-wa-float-label {
        position: absolute; right: 72px; top: 50%;
        transform: translateY(-50%) translateX(6px);
        padding: 8px 14px;
        background: #14110C; color: #F7F3EA;
        border-radius: 999px;
        font-family: 'Inter', system-ui, sans-serif;
        font-size: 12.5px; font-weight: 500;
        white-space: nowrap;
        opacity: 0; pointer-events: none;
        transition: opacity 180ms, transform 180ms;
        box-shadow: 0 8px 20px -8px rgba(20, 17, 12, 0.35);
    }
    .kv-wa-float:hover .kv-wa-float-label,
    .kv-wa-float:focus-visible .kv-wa-float-label {
        opacity: 1;
        transform: translateY(-50%) translateX(0);
    }
    @media (hover: none) { .kv-wa-float-label { display: none; } }

    /* Mobile: shrink slightly and drop close to the bottom edge —
       previously nudged up to bottom: 96px to avoid the cookie
       banner and page CTAs, but that put it awkwardly high above
       the fold. Sits at bottom: 20px now, iOS safe-area respected. */
    @media (max-width: 640px) {
        .kv-wa-float {
            width: 54px; height: 54px;
            right: 16px;
            bottom: max(20px, env(safe-area-inset-bottom, 20px));
        }
        .kv-wa-float svg { width: 26px; height: 26px; }
    }
</style>

<a href="{{ $waHref }}"
   target="_blank" rel="noopener noreferrer"
   class="kv-wa-float"
   data-testid="whatsapp-float"
   aria-label="Chat with {{ $brand }} support on WhatsApp">
    {{-- Two rippling olive rings behind the button. Purely decorative. --}}
    <span class="kv-wa-ring" aria-hidden="true"></span>
    <span class="kv-wa-ring kv-wa-ring--delay" aria-hidden="true"></span>
    <span class="kv-wa-float-label" aria-hidden="true">Chat with us</span>
    <svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true">
        {{-- Official WhatsApp glyph (single-colour, MIT-licensed shape). --}}
        <path d="M16.03 4C9.42 4 4.06 9.35 4.06 15.96c0 2.11.55 4.17 1.61 5.98L4 28l6.24-1.63a11.9 11.9 0 0 0 5.79 1.47h.01c6.6 0 11.96-5.35 11.96-11.95 0-3.19-1.24-6.19-3.5-8.44a11.9 11.9 0 0 0-8.47-3.45zm0 21.8h-.01a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-3.7.97.99-3.6-.24-.37a9.83 9.83 0 0 1-1.5-5.25c0-5.44 4.42-9.86 9.86-9.86 2.63 0 5.1 1.03 6.96 2.9a9.79 9.79 0 0 1 2.89 6.98c0 5.44-4.42 9.86-9.85 9.86zm5.4-7.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.66.15-.2.3-.76.96-.94 1.16-.17.2-.34.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.79-1.47-1.76-1.64-2.06-.17-.3-.02-.46.13-.6.13-.13.3-.34.44-.51.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.66-1.6-.91-2.19-.24-.57-.48-.49-.66-.5l-.56-.01c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.06 2.87 1.21 3.07.15.2 2.1 3.2 5.09 4.49.71.31 1.27.49 1.7.63.72.23 1.37.2 1.88.12.57-.09 1.75-.72 2-1.41.24-.7.24-1.29.17-1.41-.07-.13-.27-.2-.57-.34z"/>
    </svg>
</a>
@endif
