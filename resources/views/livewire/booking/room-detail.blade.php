@php
    $photos = $room->getMedia('gallery');
    $heroPhoto = $photos->first();
    $thumbs = $photos->skip(1)->take(4);

    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

{{-- Per-tenant guest-facing room detail. Inherits --primary,
     --display-font, --body-font from layouts.tenant-public so accents
     follow the operator's brand. The cream/ink palette matches the
     property landing page so a guest moving from the landing to a room
     never feels they crossed a brand boundary. --}}
<div class="rd-page" wire:poll.15s="refresh">
    <style>
        .rd-page {
            --rd-paper:    #FAF7F2;
            --rd-paper-2:  #F2EEE6;
            --rd-card:     #FFFFFF;
            --rd-ink:      #14171A;
            --rd-ink-soft: #3D434A;
            --rd-ink-mute: #7A7F87;
            --rd-line:     rgba(20, 23, 26, 0.10);
            --rd-line-soft:rgba(20, 23, 26, 0.06);
            --rd-primary-dark: color-mix(in srgb, var(--primary, #14171A) 78%, black);
            --rd-primary-soft: color-mix(in srgb, var(--primary, #14171A) 12%, transparent);

            background: var(--rd-paper);
            color: var(--rd-ink);
            font-family: var(--body-font);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-bottom: 5rem;
        }
        .rd-page h1, .rd-page h2, .rd-page h3 {
            font-family: var(--display-font);
            color: var(--rd-ink);
            letter-spacing: -0.018em;
            line-height: 1.06;
            margin: 0;
            font-weight: 600;
        }
        .rd-page a { color: inherit; text-decoration: none; }
        .rd-page p { color: var(--rd-ink-soft); line-height: 1.65; }

        .rd-wrap { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        @media (min-width: 768px) { .rd-wrap { padding: 0 2.5rem; } }

        /* ── Eyebrow (italic display-serif with leading rule) ──────── */
        .rd-eyebrow {
            display: inline-flex; align-items: center; gap: 10px;
            font-family: var(--display-font);
            font-style: italic;
            font-weight: 500;
            font-size: 14px;
            color: var(--primary, var(--rd-ink-soft));
        }
        .rd-eyebrow::before {
            content: '';
            display: inline-block;
            width: 14px;
            height: 1px;
            background: var(--primary, var(--rd-ink-soft));
        }

        /* ── Breadcrumbs ──────────────────────────────────────────── */
        .rd-crumbs {
            padding: 1.75rem 0 0.5rem;
            font-size: 12.5px;
            color: var(--rd-ink-mute);
            letter-spacing: 0.04em;
        }
        .rd-crumbs a { color: var(--rd-ink-soft); }
        .rd-crumbs a:hover { color: var(--rd-ink); }
        .rd-crumbs .sep { margin: 0 0.6rem; color: var(--rd-ink-mute); }
        .rd-crumbs-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .rd-crumbs-row .rd-crumbs { padding: 1.75rem 0 0.5rem; margin: 0; }

        /* ── Header (title + meta + rating) ───────────────────────── */
        .rd-header { padding: 1rem 0 1.5rem; }
        .rd-title {
            font-size: clamp(34px, 4.5vw, 56px);
            letter-spacing: -0.025em;
            font-weight: 600;
        }
        .rd-meta {
            margin-top: 0.9rem;
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 0.85rem 1.4rem;
            font-size: 14px;
            color: var(--rd-ink-soft);
        }
        .rd-meta-dot { color: var(--rd-ink-mute); }
        .rd-rating {
            display: inline-flex; align-items: center; gap: 0.45rem;
            font-weight: 500; color: var(--rd-ink);
        }
        .rd-rating .star { color: var(--primary, var(--rd-ink-soft)); font-size: 15px; line-height: 1; }
        .rd-rating .mute { color: var(--rd-ink-mute); font-weight: 400; }

        /* ── Gallery ──────────────────────────────────────────────── */
        .rd-gallery {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 6px;
            border-radius: 18px;
            overflow: hidden;
            min-height: clamp(380px, 50vw, 520px);
            background: var(--rd-paper-2);
            margin-bottom: 3rem;
        }
        .rd-gallery .g-main { grid-row: 1 / span 2; }
        .rd-gallery > div { position: relative; overflow: hidden; }
        .rd-gallery img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 600ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .rd-gallery img:hover { transform: scale(1.03); }
        .rd-gallery .g-empty {
            display: flex; align-items: center; justify-content: center;
            background:
                repeating-linear-gradient(45deg, rgba(20,23,26,0.05), rgba(20,23,26,0.05) 12px, transparent 12px, transparent 24px),
                var(--rd-paper-2);
            color: var(--rd-ink-mute);
            font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase;
            font-family: var(--body-font);
        }
        @media (max-width: 760px) {
            .rd-gallery {
                grid-template-columns: 1fr;
                grid-template-rows: auto;
                min-height: auto;
            }
            .rd-gallery .g-main { aspect-ratio: 4 / 3; }
            .rd-gallery > *:not(.g-main) { display: none; }
        }

        /* ── Two-column body ──────────────────────────────────────── */
        .rd-body {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 3rem;
        }
        @media (max-width: 960px) { .rd-body { grid-template-columns: 1fr; gap: 2rem; } }

        .rd-section { padding: 2rem 0; border-top: 1px solid var(--rd-line); }
        .rd-section:first-child { border-top: 0; padding-top: 0; }
        .rd-section h2 {
            font-size: clamp(22px, 2.4vw, 28px);
            margin-bottom: 1rem;
        }

        .rd-prose { font-size: 15.5px; color: var(--rd-ink-soft); line-height: 1.7; }

        /* ── Amenities ────────────────────────────────────────────── */
        .rd-amenities {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.6rem 2rem;
            padding: 0; list-style: none; margin: 0.5rem 0 0;
        }
        .rd-amenities li {
            padding: 0.55rem 0 0.55rem 1.85rem;
            color: var(--rd-ink-soft);
            font-size: 14.5px;
            position: relative;
            border-bottom: 1px solid var(--rd-line-soft);
        }
        .rd-amenities li::before {
            content: '';
            position: absolute; left: 0; top: 0.95rem;
            width: 8px; height: 8px;
            background: var(--primary, var(--rd-ink-soft));
            border-radius: 50%;
        }

        /* ── Location map ─────────────────────────────────────────── */
        .rd-map {
            margin-top: 1.25rem;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(20,17,12,0.06);
        }

        /* ── "Good to know" tiles (check-in / out + late fee) ────── */
        .rd-gtk-grid {
            display: grid;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }
        @media (min-width: 720px) {
            .rd-gtk-grid { grid-template-columns: repeat(2, 1fr); }
        }
        .rd-gtk-tile {
            padding: 1rem 1.15rem;
            background: var(--rd-paper, #FAF7F1);
            border-radius: 12px;
            display: flex; flex-direction: column; gap: 6px;
        }
        .rd-gtk-tile--wide { grid-column: 1 / -1; }
        .rd-gtk-label {
            font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase;
            color: var(--rd-ink-mute, #8a7f6a); font-weight: 500;
        }
        .rd-gtk-value {
            font-size: 14.5px; color: var(--rd-ink, #1a1614); line-height: 1.55;
        }

        /* ── Reviews ──────────────────────────────────────────────── */
        .rd-reviews {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
        }
        .rd-review {
            background: var(--rd-card);
            border: 1px solid var(--rd-line);
            border-radius: 14px;
            padding: 1.5rem 1.5rem 1.35rem;
            margin: 0;
            display: flex; flex-direction: column; gap: 0.85rem;
        }
        .rd-review .stars {
            color: var(--primary, var(--rd-ink-soft));
            font-size: 13px; letter-spacing: 0.04em;
        }
        .rd-review .stars .empty { color: var(--rd-line); }
        .rd-review p {
            font-family: var(--display-font);
            font-style: italic;
            font-size: 17px;
            line-height: 1.45;
            color: var(--rd-ink);
            margin: 0;
            letter-spacing: -0.005em;
        }
        .rd-review-foot {
            display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem;
            padding-top: 0.4rem;
            border-top: 1px solid var(--rd-line-soft);
        }
        .rd-review cite {
            color: var(--rd-ink-soft);
            font-size: 12.5px;
            font-style: normal;
            font-weight: 500;
            letter-spacing: 0.02em;
        }
        .rd-verified {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px;
            background: var(--rd-primary-soft);
            color: var(--rd-primary-dark);
            border-radius: 9999px;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .rd-verified::before {
            content: ''; width: 5px; height: 5px;
            background: var(--primary, var(--rd-ink-soft)); border-radius: 50%;
        }

        /* ── Booking widget (sticky on desktop) ───────────────────── */
        .rd-widget-wrap {
            position: relative;
        }
        @media (min-width: 961px) {
            .rd-widget-wrap {
                position: sticky;
                top: 96px;
                height: fit-content;
            }
        }
        .rd-widget {
            background: var(--rd-card);
            border: 1px solid var(--rd-line);
            border-radius: 18px;
            padding: 1.75rem 1.75rem 1.5rem;
            box-shadow: 0 22px 50px -28px rgba(20,17,12,0.25);
        }
        .rd-widget-price {
            display: flex; align-items: baseline; gap: 0.4rem;
            font-family: var(--display-font);
            font-size: 30px;
            font-weight: 600;
            color: var(--rd-ink);
            line-height: 1;
            letter-spacing: -0.02em;
            margin-bottom: 0.3rem;
        }
        .rd-widget-price .per {
            font-family: var(--body-font);
            font-size: 13.5px;
            color: var(--rd-ink-mute);
            font-weight: 400;
        }
        .rd-widget-rating {
            font-size: 12px;
            color: var(--rd-ink-mute);
            margin-bottom: 1.25rem;
            display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .rd-widget-rating .star { color: var(--primary, var(--rd-ink-soft)); }

        /* Live-availability indicator, pulses gently while the
           booking widget polls the server every 15s. Sits between the
           rating row and the date pickers so the guest sees the
           "real-time" cue right next to the inputs they're using. */
        .rd-live {
            display: inline-flex; align-items: center; gap: 8px;
            margin-bottom: 14px;
            padding: 8px 12px;
            background: color-mix(in srgb, var(--primary, #14171A) 8%, transparent);
            border-radius: 9999px;
            font-size: 11px;
            color: var(--rd-ink-soft);
            font-family: var(--body-font);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            width: fit-content;
        }
        .rd-live-dot {
            width: 7px; height: 7px;
            background: var(--primary, var(--rd-ink-soft));
            border-radius: 50%;
            animation: rd-live-pulse 1.6s ease-in-out infinite;
        }
        @keyframes rd-live-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.5; transform: scale(0.85); }
        }

        .rd-field-label {
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rd-ink-mute);
            font-weight: 500;
            display: block;
            margin-bottom: 6px;
        }
        .rd-dates {
            display: grid; grid-template-columns: 1fr 1fr;
            border: 1px solid var(--rd-line);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .rd-dates > label {
            padding: 12px 14px;
            display: flex; flex-direction: column;
            cursor: pointer;
        }
        .rd-dates > label + label { border-left: 1px solid var(--rd-line); }
        .rd-dates > label:hover { background: var(--rd-paper); }

        .rd-field {
            border: 1px solid var(--rd-line);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 10px;
            transition: border-color 200ms;
        }
        .rd-field:focus-within { border-color: var(--rd-ink); }
        .rd-field-row { display: grid; grid-template-columns: 1fr 1fr; }
        .rd-field-row > div + div { border-left: 1px solid var(--rd-line); padding-left: 14px; }
        .rd-field-row > div + div .rd-field-label { padding-left: 0; }

        .rd-widget input,
        .rd-widget textarea,
        .rd-widget select {
            font-family: inherit;
            font-size: 15px;
            border: 0; outline: 0; padding: 0; margin: 0;
            background: transparent;
            color: var(--rd-ink);
            width: 100%;
            line-height: 1.3;
        }
        .rd-widget textarea { resize: vertical; min-height: 56px; }
        .rd-widget input[type="date"] { font-size: 14.5px; }
        /* Radio / checkbox inputs must NOT inherit the full-width rule
           above, otherwise the radio stretches to fill the flex row
           and the label gets crushed into one-letter-per-line. */
        .rd-widget input[type="radio"],
        .rd-widget input[type="checkbox"] {
            width: auto;
            flex-shrink: 0;
        }

        .rd-warn {
            margin-top: 12px;
            padding: 10px 14px;
            background: rgba(224, 122, 95, 0.10);
            border: 1px solid rgba(184, 92, 66, 0.30);
            border-radius: 10px;
            color: #B85C42;
            font-size: 13px;
            display: flex; align-items: center; gap: 8px;
        }
        .rd-warn::before {
            content: ''; width: 6px; height: 6px;
            background: #B85C42; border-radius: 50%;
            flex-shrink: 0;
        }
        .rd-err {
            color: #B85C42;
            font-size: 12px;
            font-style: italic;
            font-family: var(--display-font);
            margin-top: 6px;
        }

        .rd-summary {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid var(--rd-line-soft);
            font-size: 13.5px;
            color: var(--rd-ink-soft);
            display: grid; gap: 6px;
        }
        .rd-summary .row {
            display: flex; justify-content: space-between; align-items: baseline;
        }
        .rd-summary .row .mute { color: var(--rd-ink-mute); font-size: 12.5px; }
        .rd-summary .row.discount { color: var(--rd-primary-dark); }
        .rd-summary .row.total {
            border-top: 1px solid var(--rd-line);
            margin-top: 8px;
            padding-top: 12px;
            color: var(--rd-ink);
        }
        .rd-summary .row.total .label {
            font-family: var(--display-font);
            font-size: 14.5px;
            font-weight: 500;
        }
        .rd-summary .row.total .amount {
            font-family: var(--display-font);
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.015em;
        }
        .rd-summary .deposit-note {
            margin-top: 4px;
            font-size: 12.5px;
            color: var(--rd-ink-mute);
            line-height: 1.5;
        }

        /* ── Coupon block ─────────────────────────────────────────
           Lives between the form fields and the price summary. The
           input + Apply button share a single bordered row; once a
           discount is applied we replace the input with a "pill"
           showing the name + amount off + a × to clear. */
        .rd-coupon { margin-top: 0.75rem; }
        .rd-coupon-row {
            margin-top: 6px;
            display: grid;
            grid-template-columns: 1fr auto;
            border: 1px solid var(--rd-line);
            border-radius: 12px;
            overflow: hidden;
        }
        .rd-coupon-row:focus-within { border-color: var(--rd-ink); }
        .rd-coupon-row input {
            font-family: inherit;
            font-size: 15px;
            background: transparent;
            color: var(--rd-ink);
            border: 0; outline: 0;
            padding: 12px 14px;
            letter-spacing: 0.06em;
        }
        .rd-coupon-row button {
            background: var(--rd-ink);
            color: var(--rd-card);
            border: 0;
            padding: 0 18px;
            font-family: var(--body-font);
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            transition: background 200ms;
        }
        .rd-coupon-row button:hover { background: var(--rd-primary-dark); }
        .rd-coupon-applied {
            margin-top: 6px;
            padding: 12px 14px;
            background: var(--rd-primary-soft);
            border: 1px solid color-mix(in srgb, var(--primary, #14171A) 30%, transparent);
            border-radius: 12px;
            display: grid;
            gap: 6px;
        }
        .rd-coupon-applied .row {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .rd-coupon-applied .badge {
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rd-primary-dark);
            font-weight: 600;
        }
        .rd-coupon-applied button {
            background: transparent;
            border: 0;
            color: var(--rd-ink-soft);
            font-size: 18px; line-height: 1;
            cursor: pointer;
            padding: 0 4px;
        }
        .rd-coupon-applied button:hover { color: var(--rd-ink); }
        .rd-coupon-applied .name {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 16px;
            color: var(--rd-ink);
            letter-spacing: -0.01em;
        }
        .rd-coupon-applied .name .code {
            display: inline-block;
            margin-left: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11.5px;
            font-weight: 500;
            background: var(--rd-card);
            color: var(--rd-ink);
            padding: 2px 8px;
            border-radius: 6px;
            letter-spacing: 0.06em;
        }
        .rd-coupon-applied .off {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 18px;
            color: var(--rd-primary-dark);
            letter-spacing: -0.015em;
        }
        .rd-coupon-applied .off .summary {
            font-family: var(--body-font);
            font-size: 11.5px;
            font-weight: 400;
            color: var(--rd-ink-mute);
            letter-spacing: 0.04em;
            margin-left: 8px;
            text-transform: uppercase;
        }

        .rd-pay {
            margin-top: 1.1rem;
            padding: 14px 16px;
            background: var(--rd-paper);
            border-radius: 12px;
            border: 1px solid var(--rd-line-soft);
        }
        .rd-pay strong {
            display: block;
            font-size: 10px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rd-ink-mute);
            font-weight: 500;
            margin-bottom: 8px;
        }
        .rd-pay label {
            display: flex; gap: 10px; align-items: flex-start;
            padding: 6px 0;
            font-size: 13.5px;
            color: var(--rd-ink);
            cursor: pointer;
        }
        .rd-pay input[type="radio"] {
            margin-top: 3px;
            accent-color: var(--primary, var(--rd-ink));
            flex-shrink: 0;
        }

        /* The submit pill (matches the landing CTA pattern). */
        .rd-book-btn {
            margin-top: 1.25rem;
            width: 100%;
            display: inline-flex; align-items: center; justify-content: space-between;
            gap: 12px;
            padding: 12px 14px 12px 22px;
            background: var(--rd-ink);
            color: var(--rd-card);
            border: 0;
            border-radius: 9999px;
            font-family: var(--body-font);
            font-size: 14.5px; font-weight: 500;
            cursor: pointer;
            transition: background 200ms, transform 80ms;
        }
        .rd-book-btn:hover { background: var(--rd-primary-dark); }
        .rd-book-btn:active { transform: translateY(1px); }
        .rd-book-btn:disabled {
            background: var(--rd-paper-2);
            color: var(--rd-ink-mute);
            cursor: not-allowed;
        }
        .rd-book-btn .dot {
            width: 32px; height: 32px;
            background: var(--primary, var(--rd-ink-mute));
            color: #fff;
            border-radius: 9999px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .rd-book-btn:disabled .dot { background: var(--rd-ink-mute); }

        .rd-trust {
            margin-top: 1rem;
            font-size: 11.5px;
            color: var(--rd-ink-mute);
            text-align: center;
            letter-spacing: 0.04em;
        }

        /* ── Related rooms ────────────────────────────────────────── */
        .rd-related {
            margin-top: 4rem;
            padding-top: 3rem;
            border-top: 1px solid var(--rd-line);
        }
        .rd-related-head {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 1rem; margin-bottom: 1.75rem;
        }
        .rd-related h2 {
            font-size: clamp(24px, 3vw, 32px);
        }
        .rd-related-track {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(280px, 320px);
            gap: 1.25rem;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            padding: 0.3rem 0 1rem;
            scrollbar-width: thin;
        }
        .rd-related-track::-webkit-scrollbar { height: 6px; }
        .rd-related-track::-webkit-scrollbar-thumb {
            background: var(--rd-line);
            border-radius: 999px;
        }
        .rd-related-card {
            background: var(--rd-card);
            border: 1px solid var(--rd-line);
            border-radius: 16px;
            overflow: hidden;
            scroll-snap-align: start;
            transition: transform 240ms ease, box-shadow 240ms ease, border-color 200ms;
            display: flex; flex-direction: column;
        }
        .rd-related-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px -20px rgba(20,17,12,0.20);
            border-color: var(--rd-ink);
        }
        .rd-related-photo {
            aspect-ratio: 16 / 11;
            background:
                repeating-linear-gradient(45deg, rgba(20,23,26,0.05), rgba(20,23,26,0.05) 12px, transparent 12px, transparent 24px),
                var(--rd-paper-2);
            overflow: hidden;
        }
        .rd-related-photo img {
            width: 100%; height: 100%; object-fit: cover;
            transition: transform 600ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        .rd-related-card:hover .rd-related-photo img { transform: scale(1.04); }
        .rd-related-body { padding: 1.1rem 1.25rem 1.25rem; }
        .rd-related-name {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 18px;
            letter-spacing: -0.015em;
            color: var(--rd-ink);
        }
        .rd-related-meta {
            color: var(--rd-ink-mute);
            font-size: 12.5px;
            margin: 0.3rem 0 0.85rem;
            letter-spacing: 0.02em;
        }
        .rd-related-foot {
            display: flex; align-items: baseline; justify-content: space-between;
            padding-top: 0.85rem;
            border-top: 1px solid var(--rd-line-soft);
        }
        .rd-related-price {
            font-family: var(--display-font);
            font-weight: 600;
            font-size: 18px;
            color: var(--rd-ink);
            letter-spacing: -0.01em;
        }
        .rd-related-price .from {
            font-family: var(--body-font);
            font-size: 11px;
            color: var(--rd-ink-mute);
            font-weight: 400;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-right: 4px;
        }
        .rd-related-price .per {
            font-family: var(--body-font);
            font-size: 12.5px;
            color: var(--rd-ink-mute);
            font-weight: 400;
        }
        .rd-related-cta {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11.5px;
            font-weight: 500;
            color: var(--rd-ink);
            letter-spacing: 0.04em;
        }

        /* ── Sticky mobile booking CTA ─────────────────────────────
           Phones only. Pill-shaped bar pinned to the bottom of the
           viewport showing the live price + "Book", anchors back
           to #book so the guest fills the form on the actual widget
           (no duplicate state). Honours iOS safe-area inset. */
        .rd-mobile-cta { display: none; }
        @media (max-width: 640px) {
            .rd-mobile-cta {
                position: fixed;
                left: 12px; right: 12px;
                bottom: calc(12px + env(safe-area-inset-bottom));
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 10px 10px 10px 18px;
                background: var(--rd-card);
                border: 1px solid var(--rd-line);
                border-radius: 9999px;
                box-shadow: 0 18px 40px -18px rgba(20,17,12,0.30);
                z-index: 40;
            }
            .rd-mobile-cta-price {
                font-family: var(--display-font);
                font-weight: 600;
                font-size: 17px;
                color: var(--rd-ink);
                letter-spacing: -0.015em;
                line-height: 1.1;
            }
            .rd-mobile-cta-price .per {
                font-family: var(--body-font);
                font-size: 11.5px;
                color: var(--rd-ink-mute);
                font-weight: 400;
                margin-left: 4px;
            }
            .rd-mobile-cta-btn {
                display: inline-flex; align-items: center; gap: 10px;
                padding: 8px 8px 8px 18px;
                background: var(--rd-ink);
                color: var(--rd-card);
                border-radius: 9999px;
                font-family: var(--body-font);
                font-size: 13.5px;
                font-weight: 500;
                white-space: nowrap;
            }
            .rd-mobile-cta-btn .dot {
                width: 28px; height: 28px;
                background: var(--primary, var(--rd-ink-mute));
                color: #fff;
                border-radius: 9999px;
                display: inline-flex; align-items: center; justify-content: center;
            }
        }

        /* ── Mobile polish (≤640px) ────────────────────────────────
           Same recipe used on category-detail so a guest moving
           between the category overview and a specific unit page
           sees the same proportions, padding, and CTA cadence. */
        @media (max-width: 640px) {
            .rd-wrap { padding: 0 1.1rem; }
            .rd-crumbs { padding: 1.25rem 0 0.25rem; font-size: 12px; }
            .rd-header { padding: 0.75rem 0 1rem; }
            .rd-title { font-size: clamp(28px, 8.5vw, 36px); }
            .rd-meta { font-size: 13px; gap: 0.5rem 0.85rem; margin-top: 0.7rem; }

            .rd-gallery { margin-bottom: 1.75rem; border-radius: 12px; }

            .rd-body { gap: 1.25rem; }
            .rd-section { padding: 1.5rem 0; }
            .rd-section h2 { margin-bottom: 0.7rem; }

            .rd-widget { padding: 1.25rem 1.25rem 1.25rem; border-radius: 14px; }
            .rd-widget-price { font-size: 26px; }
            .rd-live { font-size: 10.5px; padding: 6px 10px; }
            .rd-book-btn { padding: 12px 12px 12px 18px; font-size: 14px; }

            /* Reserve space for the fixed mobile CTA so the form's
               own button at the very bottom of the page isn't
               covered. 88px ≈ pill height + breathing room. */
            .rd-page { padding-bottom: 88px; }

            .rd-related { margin-top: 2.5rem; padding-top: 2rem; }
            .rd-related-head { margin-bottom: 1.25rem; }
            .rd-related-track { grid-auto-columns: minmax(240px, 280px); gap: 0.9rem; }
            .rd-related-body { padding: 1rem 1.1rem 1.15rem; }
        }
    </style>

    <div class="rd-wrap">

        {{-- ── Breadcrumbs + branch switcher ─────────────────────── --}}
        <div class="rd-crumbs-row">
            <p class="rd-crumbs">
                <a href="/">{{ $property?->name ?? 'Home' }}</a>
                <span class="sep">/</span>
                <span>{{ $room->name }}</span>
            </p>
            @include('tenant.partials.branch-switcher', [
                'branches' => $branches ?? collect(),
                'current' => $property,
            ])
        </div>

        {{-- ── Header ─────────────────────────────────────────────
             Same tightening pass as category-detail: no serif eyebrow,
             room name leads with the rating chip pulled out to sit
             beside it. Meta line collapses type / occupancy / min-nights
             onto a single row. --}}
        <header class="rd-header">
            <div style="display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 0.75rem;">
                <h1 data-testid="room-name" class="rd-title">{{ $room->name }}</h1>
                @if ($rating['count'] > 0)
                    <span class="rd-rating" style="padding: 4px 12px; background: var(--rd-paper-2, #F2EEE6); border-radius: 9999px; font-size: 13px;">
                        <span class="star">★</span>
                        <strong>{{ $rating['average'] }}</strong>
                        <span class="mute">&middot; {{ $rating['count'] }} review{{ $rating['count'] === 1 ? '' : 's' }}</span>
                    </span>
                @endif
            </div>
            <div class="rd-meta" style="margin-top: 0.6rem;">
                <span data-testid="room-type">{{ $room->type }}</span>
                <span class="rd-meta-dot">·</span>
                <span>Sleeps up to {{ $room->maxOccupancy() }}</span>
                <span class="rd-meta-dot">·</span>
                <span>Min {{ $room->min_nights }} night{{ $room->min_nights === 1 ? '' : 's' }}</span>
            </div>
        </header>

        {{-- ── Gallery ──────────────────────────────────────────── --}}
        <div class="rd-gallery" data-testid="gallery">
            @if ($heroPhoto)
                <div class="g-main">
                    <img src="{{ $heroPhoto->getUrl('medium') }}"
                         alt="{{ $heroPhoto->getCustomProperty('alt') ?: $room->name }}">
                </div>
                @foreach ($thumbs as $m)
                    <div>
                        <img src="{{ $m->getUrl('medium') }}"
                             alt="{{ $m->getCustomProperty('alt') ?: $room->name }}"
                             loading="lazy">
                    </div>
                @endforeach
                @for ($i = $photos->count(); $i < 5; $i++)
                    <div class="g-empty">More photos coming</div>
                @endfor
            @else
                <div class="g-main g-empty">Photos coming soon</div>
                @for ($i = 0; $i < 4; $i++)
                    <div class="g-empty">-</div>
                @endfor
            @endif
        </div>

        {{-- ── Body: content + booking widget ───────────────────── --}}
        <div class="rd-body">

            {{-- Left: content sections --}}
            <div>
                @if ($room->description)
                    <section class="rd-section" data-testid="room-description">
                        <h2>About this room</h2>
                        <p class="rd-prose">{!! nl2br(e($room->description)) !!}</p>
                    </section>
                @endif

                @if ($property && $property->amenities)
                    <section class="rd-section" data-testid="property-amenities">
                        <h2>What&rsquo;s included</h2>
                        <ul class="rd-amenities">
                            @foreach ($property->amenities as $a)
                                <li>{{ is_string($a) ? $a : ($a['label'] ?? '') }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($property && $property->address)
                    <section class="rd-section" data-testid="room-location">
                        <h2>Where you&rsquo;ll be</h2>
                        <p class="rd-prose">
                            {{ $property->address }}{{ $property->city ? ', '.$property->city : '' }}{{ $property->state ? ', '.$property->state : '' }}
                        </p>
                        @if ($property->isSectionVisible('map'))
                            <div class="rd-map">
                                <iframe loading="lazy"
                                        src="https://www.google.com/maps?q={{ urlencode($property->address.', '.($property->city ?? '').', '.($property->state ?? '')) }}&output=embed"
                                        style="width:100%; height:320px; border:0; display:block;"></iframe>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Good to know — check-in / check-out + late-fee.
                     Sits between the location and reviews so a guest
                     evaluating this exact room sees the logistics
                     before the social-proof scroll. Whole section
                     self-hides when no field is populated; late-fee
                     tile self-hides when the policy switch is off. --}}
                @php
                    $gtkRules = $property?->houseRules() ?? [];
                    $gtkPolicy = $property?->checkoutPolicy() ?? [
                        'late_checkout_enabled' => false,
                        'late_checkout_grace_minutes' => 0,
                        'late_checkout_hourly_fee' => 0,
                        'late_checkout_full_day_after_hours' => 0,
                    ];
                    $gtkFmt = static function (string $t): string {
                        if ($t === '' || ! preg_match('/^(\d{1,2}):(\d{2})$/', $t)) return '';
                        try { return \Carbon\Carbon::createFromFormat('H:i', $t)->format('g:i A'); }
                        catch (\Throwable) { return ''; }
                    };
                    $gtkInFrom = $gtkFmt($gtkRules['check_in_from'] ?? '');
                    $gtkInTo = $gtkFmt($gtkRules['check_in_to'] ?? '');
                    $gtkOutFrom = $gtkFmt($gtkRules['check_out_from'] ?? '');
                    $gtkOutTo = $gtkFmt($gtkRules['check_out_to'] ?? '');
                    $gtkInText = match (true) {
                        $gtkInFrom !== '' && $gtkInTo !== '' => "{$gtkInFrom} to {$gtkInTo}",
                        $gtkInFrom !== '' => "from {$gtkInFrom}",
                        $gtkInTo !== '' => "until {$gtkInTo}",
                        default => '',
                    };
                    $gtkOutText = match (true) {
                        $gtkOutFrom !== '' && $gtkOutTo !== '' => "{$gtkOutFrom} to {$gtkOutTo}",
                        $gtkOutFrom !== '' => "from {$gtkOutFrom}",
                        $gtkOutTo !== '' => "until {$gtkOutTo}",
                        default => '',
                    };
                    $gtkFeeEnabled = ! empty($gtkPolicy['late_checkout_enabled'])
                        && ((int) $gtkPolicy['late_checkout_hourly_fee'] > 0
                            || (int) $gtkPolicy['late_checkout_full_day_after_hours'] > 0);
                    $gtkFeeLines = [];
                    if ($gtkFeeEnabled) {
                        $deadline = $gtkOutTo !== '' ? $gtkOutTo : ($gtkOutFrom !== '' ? $gtkOutFrom : 'the end of check-out');
                        if ((int) $gtkPolicy['late_checkout_hourly_fee'] > 0) {
                            $gtkFeeLines[] = '₦'.number_format((int) $gtkPolicy['late_checkout_hourly_fee']).'/hour past '.$deadline
                                .((int) $gtkPolicy['late_checkout_grace_minutes'] > 0
                                    ? ' ('.(int) $gtkPolicy['late_checkout_grace_minutes'].'-min grace)'
                                    : '')
                                .'.';
                        }
                        if ((int) $gtkPolicy['late_checkout_full_day_after_hours'] > 0) {
                            $gtkFeeLines[] = 'Stay '.(int) $gtkPolicy['late_checkout_full_day_after_hours'].'+ hours late and the full room rate is charged.';
                        }
                    }
                    $gtkAny = $gtkInText !== '' || $gtkOutText !== '' || $gtkFeeEnabled;
                @endphp
                @if ($gtkAny)
                    <section class="rd-section" data-testid="room-good-to-know">
                        <h2>Good to know</h2>
                        <div class="rd-gtk-grid">
                            @if ($gtkInText !== '')
                                <div class="rd-gtk-tile" data-testid="rd-gtk-checkin">
                                    <span class="rd-gtk-label">Check-in</span>
                                    <span class="rd-gtk-value">{{ $gtkInText }}</span>
                                </div>
                            @endif
                            @if ($gtkOutText !== '')
                                <div class="rd-gtk-tile" data-testid="rd-gtk-checkout">
                                    <span class="rd-gtk-label">Check-out</span>
                                    <span class="rd-gtk-value">{{ $gtkOutText }}</span>
                                </div>
                            @endif
                            @if ($gtkFeeEnabled)
                                <div class="rd-gtk-tile rd-gtk-tile--wide" data-testid="rd-gtk-late-fee">
                                    <span class="rd-gtk-label">Late check-out fee</span>
                                    <span class="rd-gtk-value">{{ implode(' ', $gtkFeeLines) }}</span>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($reviews->isNotEmpty())
                    <section class="rd-section" data-testid="property-reviews">
                        <h2>What guests say <span style="color: var(--rd-ink-mute); font-weight: 400; font-size: 0.75em; letter-spacing: 0;">·  {{ $rating['count'] }}</span></h2>
                        <div class="rd-reviews">
                            @foreach ($reviews as $r)
                                <blockquote class="rd-review">
                                    <div class="stars">
                                        {{ str_repeat('★', $r->rating) }}<span class="empty">{{ str_repeat('★', 5 - $r->rating) }}</span>
                                    </div>
                                    <p>&ldquo;{{ $r->body }}&rdquo;</p>
                                    <div class="rd-review-foot">
                                        <cite>- {{ $r->guest_name }}</cite>
                                        @if ($r->isVerifiedStay())
                                            <span data-testid="verified-stay" class="rd-verified">
                                                Verified stay
                                            </span>
                                        @endif
                                    </div>
                                </blockquote>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            {{-- Right: booking widget --}}
            <aside id="book" class="rd-widget-wrap">
                <form wire:submit="book" data-testid="booking-form" class="rd-widget">

                    <div class="rd-widget-price">
                        ₦{{ number_format($room->base_rate) }}
                        <span class="per">/ night</span>
                    </div>
                    @if ($rating['count'] > 0)
                        <div class="rd-widget-rating">
                            <span class="star">★</span>
                            <strong style="color: var(--rd-ink);">{{ $rating['average'] }}</strong>
                            · {{ $rating['count'] }} review{{ $rating['count'] === 1 ? '' : 's' }}
                        </div>
                    @endif

                    <div class="rd-live" data-testid="rd-live-indicator" aria-live="polite">
                        <span class="rd-live-dot" aria-hidden></span>
                        Live availability &middot; we re-check every 15 seconds
                    </div>

                    {{-- Dates --}}
                    <div class="rd-dates">
                        <label>
                            <span class="rd-field-label">Check-in</span>
                            <input type="date" wire:model.live="checkin" required data-testid="checkin">
                        </label>
                        <label>
                            <span class="rd-field-label">Check-out</span>
                            <input type="date" wire:model.live="checkout" required data-testid="checkout">
                        </label>
                    </div>
                    @error('checkin') <p data-error="checkin" class="rd-err">{{ $message }}</p> @enderror
                    @error('checkout') <p data-error="checkout" class="rd-err">{{ $message }}</p> @enderror

                    @if ($isFree === false)
                        <p data-testid="not-available" class="rd-warn">
                            These dates are not available. Please pick another range.
                        </p>
                    @endif

                    {{-- Guests --}}
                    <div class="rd-field rd-field-row">
                        <div>
                            <label class="rd-field-label" for="rd-adults">Adults</label>
                            <input id="rd-adults" type="number" wire:model.number="adults"
                                   min="1" max="{{ $room->capacity_adults }}" required
                                   data-testid="adults">
                        </div>
                        <div>
                            <label class="rd-field-label" for="rd-children">Children</label>
                            <input id="rd-children" type="number" wire:model.number="children"
                                   min="0" max="{{ $room->capacity_children }}"
                                   data-testid="children">
                        </div>
                    </div>
                    @error('adults') <p data-error="adults" class="rd-err">{{ $message }}</p> @enderror
                    @error('children') <p data-error="children" class="rd-err">{{ $message }}</p> @enderror

                    <div class="rd-field">
                        <label class="rd-field-label" for="rd-name">Full name</label>
                        <input id="rd-name" type="text" wire:model="guest_name" required maxlength="120"
                               data-testid="guest-name" placeholder="Amara Okafor">
                    </div>
                    @error('guest_name') <p data-error="guest_name" class="rd-err">{{ $message }}</p> @enderror

                    <div class="rd-field">
                        <label class="rd-field-label" for="rd-email">Email</label>
                        <input id="rd-email" type="email"
                               wire:model.live.debounce.500ms="guest_email"
                               required
                               data-testid="guest-email" placeholder="you@example.com">
                    </div>
                    @error('guest_email') <p data-error="guest_email" class="rd-err">{{ $message }}</p> @enderror

                    @if ($this->corporateContext !== null)
                        @php $cc = $this->corporateContext; @endphp
                        <div class="rd-corp-banner"
                             data-testid="corporate-banner"
                             style="margin-top: 14px; padding: 14px 16px;
                                    background: color-mix(in srgb, var(--rd-accent, #14171A) 6%, transparent);
                                    border: 1px solid color-mix(in srgb, var(--rd-accent, #14171A) 22%, transparent);
                                    border-radius: 12px; display: grid; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span class="lp-mono"
                                      style="font-size: 11px; letter-spacing: 0.10em; text-transform: uppercase;
                                             color: var(--rd-accent, #14171A); font-weight: 600;">
                                    Corporate · {{ $cc['corporate']->name }}
                                </span>
                                @if ($cc['member'] !== null)
                                    <span style="font-size: 12.5px; color: var(--rd-ink-soft, #3D434A);">
                                        Welcome back, {{ $cc['member']->name }}.
                                    </span>
                                @endif
                            </div>
                            @if (! $cc['propertyAccepts'])
                                <p style="margin: 0; font-size: 13px; color: var(--rd-ink-soft, #3D434A); line-height: 1.5;">
                                    This property doesn't yet accept Keverna Corporates, your booking
                                    will proceed at the public rate.
                                </p>
                            @elseif ($cc['rate'] !== null && $cc['totalSaving'] > 0)
                                <p style="margin: 0; font-size: 13px; color: var(--rd-ink, #14171A); line-height: 1.5;">
                                    Negotiated rate applies, your stay is billed to
                                    <strong style="font-weight: 600;">{{ $cc['corporate']->name }}</strong>'s wallet.
                                    Saving:
                                    <strong style="font-weight: 600;">₦{{ number_format($cc['totalSaving']) }}</strong>.
                                </p>
                            @else
                                <p style="margin: 0; font-size: 13px; color: var(--rd-ink-soft, #3D434A); line-height: 1.5;">
                                    Your stay will be billed to {{ $cc['corporate']->name }}'s corporate wallet.
                                </p>
                            @endif
                            @if ($cc['capWarning'] !== null)
                                <p data-testid="corp-cap-warn"
                                   style="margin: 0; font-size: 12.5px; color: #8c1e1e; line-height: 1.5;">
                                    {{ $cc['capWarning'] }}
                                </p>
                            @endif
                        </div>
                    @endif

                    <div class="rd-field">
                        <label class="rd-field-label" for="rd-phone">Phone</label>
                        <input id="rd-phone" type="tel" wire:model="guest_phone" required
                               data-testid="guest-phone" placeholder="+234 800 000 0000">
                    </div>
                    @error('guest_phone') <p data-error="guest_phone" class="rd-err">{{ $message }}</p> @enderror

                    <div class="rd-field">
                        <label class="rd-field-label" for="rd-special">Special requests</label>
                        <textarea id="rd-special" wire:model="special_requests" rows="2" maxlength="1000"
                                  data-testid="special-requests"
                                  placeholder="Early check-in, dietary notes, anything else…"></textarea>
                    </div>

                    {{-- Coupon / discount input. Auto-applied promos show
                         here too, with an "Auto-applied" hint. --}}
                    <div class="rd-coupon" data-testid="coupon-block">
                        @if ($appliedDiscountId)
                            <div class="rd-coupon-applied" data-testid="coupon-applied">
                                <div class="row">
                                    <span class="badge">
                                        @if ($discountAutoApplied)
                                            Auto-applied
                                        @else
                                            Coupon applied
                                        @endif
                                    </span>
                                    <button type="button" wire:click="clearDiscount"
                                            data-testid="coupon-clear" aria-label="Remove discount">×</button>
                                </div>
                                <div class="name">
                                    {{ $appliedDiscountName }}
                                    @if ($appliedDiscountCodeUsed)
                                        <span class="code">{{ $appliedDiscountCodeUsed }}</span>
                                    @endif
                                </div>
                                <div class="off">
                                    &minus;₦{{ number_format((int) $appliedDiscountAmountOff) }} off
                                    <span class="summary">{{ $appliedDiscountSummary }}</span>
                                </div>
                            </div>
                        @else
                            <label class="rd-field-label" for="rd-coupon">Got a coupon?</label>
                            <div class="rd-coupon-row">
                                <input id="rd-coupon" type="text" wire:model="discountCode"
                                       wire:keydown.enter.prevent="applyDiscount"
                                       data-testid="coupon-code"
                                       placeholder="e.g. SUMMER25"
                                       style="text-transform: uppercase;">
                                <button type="button" wire:click="applyDiscount"
                                        data-testid="coupon-apply">
                                    <span wire:loading.remove wire:target="applyDiscount">Apply</span>
                                    <span wire:loading wire:target="applyDiscount">…</span>
                                </button>
                            </div>
                            @if ($discountError)
                                <p class="rd-err" data-testid="coupon-error">{{ $discountError }}</p>
                            @endif
                        @endif
                    </div>

                    @if ($preview)
                        @php
                            $couponOff = (int) ($appliedDiscountAmountOff ?? 0);
                            $finalTotal = max(0, $preview->totalDue - $couponOff);
                            $finalDeposit = $preview->depositAmount > 0
                                ? min($preview->depositAmount, $finalTotal)
                                : 0;
                        @endphp
                        <div class="rd-summary" data-testid="price-preview">
                            <div class="row">
                                <span class="mute">{{ $preview->nights }} night{{ $preview->nights === 1 ? '' : 's' }}</span>
                                <span>₦{{ number_format($preview->netSubtotal - $preview->packagesTotal) }}</span>
                            </div>
                            @if ($preview->discountAmount > 0)
                                <div class="row discount">
                                    <span class="mute">Length-of-stay discount &middot; {{ $preview->discountPercent }}%</span>
                                    <span>−₦{{ number_format($preview->discountAmount) }}</span>
                                </div>
                            @endif
                            @if ($preview->packagesTotal > 0)
                                <div class="row" data-testid="preview-packages-row">
                                    <span class="mute">Add-ons</span>
                                    <span>₦{{ number_format($preview->packagesTotal) }}</span>
                                </div>
                            @endif
                            @if ($couponOff > 0)
                                <div class="row discount" data-testid="preview-coupon-row">
                                    <span class="mute">
                                        {{ $discountAutoApplied ? 'Auto-applied' : 'Coupon' }} &middot; {{ $appliedDiscountName }}
                                    </span>
                                    <span>−₦{{ number_format($couponOff) }}</span>
                                </div>
                            @endif
                            <div class="row">
                                <span class="mute">Card fee</span>
                                <span>₦{{ number_format($preview->paystackFee) }}</span>
                            </div>
                            <div class="row">
                                <span class="mute">Service fee</span>
                                <span>₦{{ number_format($preview->kevernaFee) }}</span>
                            </div>
                            <div class="row total">
                                <span class="label">Total</span>
                                <span class="amount">₦<span data-testid="preview-total">{{ number_format($finalTotal) }}</span></span>
                            </div>
                            @if ($finalDeposit > 0)
                                <p class="deposit-note">
                                    Or pay <strong style="color: var(--rd-ink);">₦{{ number_format($finalDeposit) }}</strong> now,
                                    ₦{{ number_format($finalTotal - $finalDeposit) }} on check-in.
                                </p>
                            @endif
                        </div>
                    @endif

                    @if ($packages->isNotEmpty())
                        <div class="rd-packages" data-testid="packages-picker"
                             style="display: grid; gap: 10px; padding: 14px 16px; border: 1px solid var(--color-line-soft, #e6e1d8); border-radius: 12px; background: rgba(255,255,255,0.55);">
                            <strong style="font-size: 13.5px;">Add-ons &amp; complimentary packages</strong>
                            @foreach ($packages as $pkg)
                                @php $maxQty = max(1, (int) $pkg->max_per_booking); @endphp
                                <div data-testid="package-row-{{ $pkg->id }}"
                                     style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding-top: 8px; border-top: 1px dashed var(--color-line-soft, #e6e1d8);">
                                    <label style="flex: 1; display: flex; gap: 10px; cursor: pointer;">
                                        @if ($maxQty === 1)
                                            <input type="checkbox" wire:model.live="selectedPackages.{{ $pkg->id }}"
                                                   value="1"
                                                   data-testid="package-toggle-{{ $pkg->id }}"
                                                   style="margin-top: 3px;">
                                        @endif
                                        <span style="font-size: 13.5px;">
                                            <span style="display: block; font-weight: 500;">{{ $pkg->name }}</span>
                                            @if ($pkg->description)
                                                <span style="display: block; color: var(--color-ink-mute, #8a8170); font-size: 12.5px;">{{ $pkg->description }}</span>
                                            @endif
                                        </span>
                                    </label>
                                    <div style="text-align: right; font-size: 13px; white-space: nowrap;">
                                        @if ($pkg->is_complimentary)
                                            <span data-testid="package-price-{{ $pkg->id }}"
                                                  style="color: #2f6b50; font-weight: 500;">Complimentary</span>
                                        @else
                                            <span data-testid="package-price-{{ $pkg->id }}">
                                                ₦{{ number_format($pkg->effectivePrice()) }}
                                            </span>
                                        @endif
                                        @if ($maxQty > 1)
                                            <div style="margin-top: 6px;">
                                                <input type="number" min="0" max="{{ $maxQty }}"
                                                       wire:model.live="selectedPackages.{{ $pkg->id }}"
                                                       data-testid="package-qty-{{ $pkg->id }}"
                                                       style="width: 64px; padding: 4px 6px; border: 1px solid var(--color-line-soft, #e6e1d8); border-radius: 8px; text-align: center;">
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="rd-pay">
                        <strong>Payment</strong>
                        <label>
                            <input type="radio" wire:model="payment_choice" value="full">
                            <span>Pay in full now</span>
                        </label>
                        @if ($allowDeposit && $preview && $preview->depositAmount > 0)
                            <label>
                                <input type="radio" wire:model="payment_choice" value="deposit"
                                       data-testid="payment-deposit">
                                <span>Place on hold &middot; deposit only</span>
                            </label>
                        @endif
                    </div>

                    <button type="submit" data-testid="book-button"
                            @disabled($isFree === false) class="rd-book-btn">
                        <span>
                            <span wire:loading.remove wire:target="book">Book {{ $room->name }}</span>
                            <span wire:loading wire:target="book">Securing your dates…</span>
                        </span>
                        <span class="dot">{!! $pillArrow !!}</span>
                    </button>

                    <p class="rd-trust">Secured by Paystack &middot; You won&rsquo;t be charged until you confirm.</p>
                </form>
            </aside>
        </div>

        {{-- ── Sticky mobile booking CTA ─────────────────────────
             Anchors back to #book so the guest fills the form on
             the actual widget, no duplicate state to keep in sync.
             Hidden on tablets+ via the .rd-mobile-cta media query. --}}
        @php
            $mobileCtaOff = (int) ($appliedDiscountAmountOff ?? 0);
            $mobileCtaTotal = $preview ? max(0, $preview->totalDue - $mobileCtaOff) : null;
        @endphp
        <a href="#book" class="rd-mobile-cta" data-testid="rd-mobile-cta">
            <span class="rd-mobile-cta-price">
                @if ($mobileCtaTotal !== null)
                    ₦{{ number_format($mobileCtaTotal) }}
                    <span class="per">total · {{ $preview->nights }} night{{ $preview->nights === 1 ? '' : 's' }}</span>
                @else
                    ₦{{ number_format($room->base_rate) }}
                    <span class="per">/ night</span>
                @endif
            </span>
            <span class="rd-mobile-cta-btn">
                Book
                <span class="dot">{!! $pillArrow !!}</span>
            </span>
        </a>

        {{-- ── Related rooms ────────────────────────────────────── --}}
        @if ($relatedRooms->isNotEmpty())
            <section class="rd-related" data-testid="related-rooms">
                <div class="rd-related-head">
                    <div>
                        <span class="rd-eyebrow">Also at {{ $property?->name }}</span>
                        <h2 class="mt-3">Other rooms you might love.</h2>
                    </div>
                </div>

                <div class="rd-related-track">
                    @foreach ($relatedRooms as $r)
                        @php $hero = $r->heroImage(); @endphp
                        <a href="/rooms/{{ $r->id }}" class="rd-related-card"
                           data-testid="related-room-{{ $r->id }}">
                            <div class="rd-related-photo">
                                @if ($hero)
                                    <img src="{{ $hero->getUrl('medium') }}"
                                         alt="{{ $hero->getCustomProperty('alt') ?: $r->name }}"
                                         loading="lazy">
                                @endif
                            </div>
                            <div class="rd-related-body">
                                <div class="rd-related-name">{{ $r->name }}</div>
                                <div class="rd-related-meta">
                                    {{ $r->type }} &middot; sleeps {{ $r->maxOccupancy() }}
                                </div>
                                <div class="rd-related-foot">
                                    <span class="rd-related-price">
                                        <span class="from">From</span>
                                        ₦{{ number_format($r->base_rate) }}
                                        <span class="per">/ night</span>
                                    </span>
                                    <span class="rd-related-cta">
                                        View →
                                    </span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
