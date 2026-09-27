{{--
    Keverna landing, marketplace v2 (Airbnb-inspired).

    Content-first layout: minimal top nav with centered category tabs
    (All / Stays / Workspaces / Rentals / Host), compact 3-field search
    pill, then horizontal-scroll rows of listing cards below.

    Inventory is placeholder for now, a small "coming soon" band is
    honest about it while the cards preview the eventual look. When the
    cross-tenant public search index ships the cards become real
    listings; the card shape + row scroll UI stay.

    Design language shifts to a light/white marketplace surface, distinct
    from the warm cream .lp-page marketing look which is preserved
    verbatim at /for-hosts.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Keverna · Stays, workspaces and rentals across Nigeria</title>
    <meta name="description" content="Browse verified stays, workspaces and long-term rentals across Nigeria. Book by the night, week, month or year." />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Alpine (standalone, CDN). This is a plain marketing page, it
         doesn't render Livewire components, so it doesn't get
         @livewireScripts (which normally bundles Alpine). Load Alpine
         directly so x-data / x-show / x-for on the search pill and
         category tabs actually initialise. `defer` waits for HTML to
         parse before booting Alpine, so it picks up every x-data on
         the page. --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.0/dist/cdn.min.js"></script>

    <style>
        /* Marketplace surface, Keverna warm cream + olive-lime accent,
           Inter for interface, Fraunces for editorial headlines. Scoped
           under .mp-page so it doesn't collide with .lp-page (still
           used by /for-hosts). */
        .mp-page {
            /* Colour tokens. Body is pure white; header + search
               pill live on a subtle light-grey surface so the
               interactive strip visually separates from content. */
            --mp-paper:   #FFFFFF;         /* body / page background */
            --mp-surface: #F5F5F5;         /* header + search pill background */
            --mp-surface-2: #EBEBEB;       /* deeper surface (active pill / hover) */
            --mp-card:   #FFFFFF;
            --mp-line:   #E7E7E7;
            --mp-line-strong: #C9C9C9;
            --mp-ink:    #14171A;
            --mp-ink-soft: #3A4148;
            --mp-mute:   #6E7078;
            --mp-accent: #8AAE3A;   /* deep olive-lime, primary CTA + underline */
            --mp-accent-light: #ADCB52;
            --mp-accent-soft: rgba(173, 203, 82, 0.14);
            --mp-accent-glow: rgba(138, 174, 58, 0.25);

            background: var(--mp-paper);
            color: var(--mp-ink);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
            line-height: 1.45;
            margin: 0;
        }
        .mp-page a { color: inherit; text-decoration: none; }
        .mp-page *:focus-visible { outline: 2px solid var(--mp-accent); outline-offset: 2px; border-radius: 4px; }
        .mp-container { max-width: 1440px; margin: 0 auto; padding: 0 40px; }
        .mp-script {
            font-family: 'Fraunces', Georgia, serif;
            font-style: italic;
            font-weight: 500;
            color: var(--mp-accent);
        }
        .mp-serif {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 600;
            letter-spacing: -0.02em;
        }
        @media (max-width: 768px) {
            .mp-container { padding: 0 20px; }
        }

        /* ── Top nav ────────────────────────────────────────────── */
        .mp-nav {
            position: sticky; top: 0; z-index: 40;
            background: var(--mp-surface);
            border-bottom: 1px solid var(--mp-line);
            transition: box-shadow 240ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        background 240ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        /* When the summary pill triggers "force-open", the nav pops
           above the page as a focal overlay, solid background so the
           search widget reads clearly, higher z-index above any hero
           imagery, and a soft shadow to lift it off the page. Scrim
           behind (below) dims the rest of the page. */
        .mp-nav[data-force-open="1"] {
            background: var(--mp-surface);
            box-shadow: 0 20px 40px -18px rgba(20, 23, 26, 0.25);
            z-index: 60;
        }
        /* Scrim, full-viewport dim that sits BELOW the nav
           (z-index 55 vs nav's 60) but above the rest of the page. */
        .mp-scrim {
            position: fixed; inset: 0;
            background: rgba(20, 23, 26, 0.35);
            z-index: 55;
            opacity: 0;
            pointer-events: none;
            transition: opacity 260ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-scrim.is-visible {
            opacity: 1;
            pointer-events: auto;
        }
        .mp-nav-inner {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 24px;
            padding: 18px 0 20px;
            transition: padding 320ms cubic-bezier(0.32, 0.72, 0, 1);
        }
        /* Middle slot holds BOTH the categories row and a compact
           summary pill on top of each other via grid-template-areas
           so the swap needs no reflow. */
        .mp-nav-middle {
            display: grid;
            grid-template-areas: "stack";
            align-items: center;
            justify-items: center;
        }
        .mp-nav-middle > * { grid-area: stack; }

        /* Collapse behavior. Single duration + easing across every
           moving property so the swap feels like ONE motion, not four
           overlapping ones. Cubic-bezier is Apple's iOS decel curve.
           `will-change` hints the compositor to promote to a layer,
           so transform + opacity animate on the GPU. Full pill uses
           a fixed-height wrapper (.mp-search-wrap) whose grid row
           collapses 84px → 0 smoothly (avoids the max-height reflow
           jank of the previous approach). */
        :root { --mp-ease: cubic-bezier(0.32, 0.72, 0, 1); --mp-dur: 320ms; }
        .mp-nav.is-scrolled .mp-nav-inner { padding: 10px 0 12px; }

        .mp-cats,
        .mp-summary-inline {
            transition: opacity var(--mp-dur) var(--mp-ease),
                        transform var(--mp-dur) var(--mp-ease);
            will-change: opacity, transform;
        }
        .mp-nav.is-scrolled .mp-cats {
            opacity: 0;
            transform: translateY(-6px);
            pointer-events: none;
        }

        /* Full search pill wrapped in a grid-rows collapsable container.
           grid-template-rows: 1fr → 0fr is smoothly animatable in modern
           browsers and collapses the actual box (unlike scale) so the
           content below rides up. */
        .mp-search-wrap {
            display: grid;
            grid-template-rows: 1fr;
            transition: grid-template-rows var(--mp-dur) var(--mp-ease);
        }
        .mp-search-wrap > .mp-search-slot { overflow: hidden; min-height: 0; }
        .mp-search {
            transition: opacity var(--mp-dur) var(--mp-ease),
                        transform var(--mp-dur) var(--mp-ease);
            transform-origin: top center;
            will-change: opacity, transform;
        }
        .mp-nav.is-scrolled .mp-search-wrap { grid-template-rows: 0fr; }
        .mp-nav.is-scrolled .mp-search {
            opacity: 0;
            transform: scale(0.96) translateY(-4px);
            pointer-events: none;
        }

        /* Compact "Start your search" pill (inline, middle slot). Shows
           only when scrolled. Clicking it scrolls to top so the full
           search re-expands. */
        .mp-summary-inline {
            display: inline-flex; align-items: center; gap: 0;
            border: 1px solid #DDDDDD;
            border-radius: 999px;
            padding: 4px 4px 4px 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            font-size: 13px; font-weight: 500;
            background: #fff;
            cursor: pointer;
            opacity: 0; pointer-events: none;
            transform: translateY(6px);
        }
        .mp-nav.is-scrolled .mp-summary-inline {
            opacity: 1; pointer-events: auto;
            transform: translateY(0);
        }
        /* Each segment button, reset default button styling, apply
           padding + trailing divider so they read as a single pill
           split into three tappable regions. */
        .mp-summary-inline .mp-summary-seg {
            padding: 10px 16px; border: none; background: transparent;
            border-right: 1px solid #EEE;
            color: #222; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 8px;
            cursor: pointer;
            font-family: inherit; font-size: 13px; font-weight: 500;
            transition: color 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-summary-inline .mp-summary-seg:hover { color: #000; }
        .mp-summary-inline .mp-summary-seg.mute { color: #6A6A6A; font-weight: 400; }
        .mp-summary-inline .mp-summary-seg.mute:hover { color: #222; }
        /* Last segment button (before the search circle) drops its
           trailing divider, the circle sits in its own space. */
        .mp-summary-inline .mp-summary-seg:last-of-type { border-right: none; }
        /* 3D house glyph sits inline before the first label text. */
        .mp-summary-inline .home-glyph {
            width: 26px; height: 26px; flex-shrink: 0;
            object-fit: contain;
            display: inline-block;
        }
        .mp-summary-inline .search-btn {
            width: 34px; height: 34px; border-radius: 999px;
            background: var(--mp-accent); color: #fff; border: none;
            padding: 0;
            display: inline-flex; align-items: center; justify-content: center;
            margin-left: 6px; flex-shrink: 0;
            cursor: pointer;
            box-shadow: 0 4px 12px -4px var(--mp-accent-glow);
            transition: background 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        transform 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-summary-inline .search-btn:hover {
            background: #7A9C33;
            transform: scale(1.04);
        }
        .mp-summary-inline .search-btn svg {
            width: 14px; height: 14px;
            color: #fff;
        }
        @media (max-width: 720px) {
            .mp-summary-inline { max-width: calc(100vw - 200px); }
            .mp-summary-inline span { padding: 8px 10px; font-size: 12px; }
        }
        .mp-brand {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 600; font-size: 22px; color: var(--mp-ink);
            letter-spacing: -0.02em;
        }
        .mp-brand-mark {
            width: 28px; height: 28px; border-radius: 8px;
            background: var(--mp-accent); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-brand-mark svg { width: 14px; height: 14px; }

        .mp-cats {
            display: flex; gap: 4px; justify-content: center;
            position: relative;
        }
        .mp-cat {
            position: relative;
            background: transparent; border: none; cursor: pointer;
            padding: 8px 14px 12px;
            font-size: 14px; font-weight: 500;
            color: var(--mp-mute);
            display: inline-flex; align-items: center; gap: 8px;
            transition: color 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)), transform 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
            font-family: inherit;
        }
        .mp-cat:hover { color: var(--mp-ink); }
        .mp-cat[aria-selected="true"] { color: var(--mp-ink); }
        /* Sliding underline, an ::after element with olive-lime bar
           that animates in via scaleX from the middle. */
        .mp-cat::after {
            content: ""; position: absolute;
            left: 12px; right: 12px; bottom: 4px;
            height: 2px; border-radius: 2px;
            background: var(--mp-accent);
            transform: scaleX(0);
            transform-origin: center;
            transition: transform 260ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-cat[aria-selected="true"]::after { transform: scaleX(1); }
        /* 3D PNG icons, larger than stroke SVGs, subtle grayscale +
           opacity dim on inactive tabs so the active one pops. Rest
           position slightly lower so the underline sits directly under
           the icon+label block. */
        .mp-cat-icon {
            width: 42px; height: 42px;
            object-fit: contain;
            display: block;
            transition: transform 520ms cubic-bezier(0.16, 1, 0.3, 1);
            will-change: transform;
        }
        .mp-cat:hover .mp-cat-icon {
            transform: scale(1.12) rotate(-3deg);
        }
        .mp-cat[aria-selected="true"] .mp-cat-icon {
            transform: scale(1.06);
        }
        /* Slightly smaller icon + tighter padding when the nav is
           collapsed so the row stays airy once the search pill hides. */
        .mp-nav.is-scrolled .mp-cat { padding: 6px 12px 10px; }
        .mp-nav.is-scrolled .mp-cat-icon { width: 32px; height: 32px; }

        .mp-nav-right {
            display: flex; align-items: center; justify-content: flex-end; gap: 6px;
        }
        .mp-nav-link {
            padding: 12px 14px;
            border-radius: 22px;
            font-size: 13px; font-weight: 500;
            color: #222;
            transition: background 0.12s;
        }
        .mp-nav-link:hover { background: #F7F7F7; }
        .mp-nav-icon-btn {
            padding: 8px; border-radius: 999px;
            background: transparent; border: 1px solid #DDDDDD;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
        }
        .mp-nav-icon-btn:hover { background: #F7F7F7; }
        .mp-nav-icon-btn svg { width: 14px; height: 14px; color: #222; }

        /* ── Search pill (expanding-fields, Airbnb pattern) ────── */
        /* Outer shell holds Alpine state and wraps BOTH the pill and
           the popover-anchor. The anchor sits directly below the pill
           and hosts the popovers as absolutely-positioned children so
           they escape .mp-search-slot's overflow:hidden (needed by
           the scroll-collapse animation). */
        .mp-search-shell { position: relative; }
        .mp-popover-anchor {
            position: relative;
            /* 0 height by itself, popovers absolute-position off it. */
            max-width: 900px;
            margin: 0 auto;
        }
        .mp-search {
            position: relative;
            display: flex;
            align-items: stretch;
            max-width: 900px;
            margin: 8px auto 24px;
            background: #FFFFFF;
            border: 1px solid var(--mp-line);
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 4px;
            transition: background 200ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        /* Rest: pill is fully white, segments blend in as one
           uniform surface. On hover ANY child, the pill body dims
           to light grey; the segment being hovered stays white so
           it reads as the raised card the user is about to click.
           Same treatment for the active state. */
        .mp-search:hover,
        .mp-search.is-active { background: #F0F0F0; }

        /* Single sliding "hilight" pill sits behind whichever segment
           is active. Moves via left+width transitions so switching
           segments feels like the card slides across the pill, not
           three separate cards toggling. Explicit width:0 default so
           it can't flash visible before Alpine hydrates + assigns the
           first measurement. */
        .mp-search-hilight {
            position: absolute;
            top: 4px; bottom: 4px;
            left: 0; width: 0;
            background: #FFFFFF;
            border-radius: 999px;
            box-shadow: 0 6px 20px -6px rgba(20,23,26,0.18);
            z-index: 0;
            pointer-events: none;
            opacity: 0;
            transition: left 360ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        width 360ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        opacity 200ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
            will-change: left, width;
        }
        .mp-search.is-active .mp-search-hilight { opacity: 1; }
        .mp-seg {
            position: relative;
            z-index: 1;
            display: flex; flex-direction: column; align-items: flex-start;
            gap: 2px;
            padding: 10px 22px;
            background: transparent;
            border: none; cursor: pointer;
            font-family: inherit; color: inherit;
            text-align: left;
            flex: 1; min-width: 0;
            border-radius: 999px;
            transition: background 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        /* Rest-state hover: segment lifts to pure white with a soft
           shadow. Reads as a preview of the active card. */
        .mp-seg:hover {
            background: #FFFFFF;
            box-shadow: 0 2px 8px rgba(20,23,26,0.08);
        }
        /* When a segment is already active, hovering ANOTHER segment
           dims (not lifts), the white lifted card is reserved for
           the active one. Excluded from the dim rule: the active
           segment itself, so its own hover stays transparent and
           the sliding white hilight underneath remains visible. */
        .mp-search.is-active .mp-seg:not(.is-active-seg):hover {
            background: #D9D9D9;
            box-shadow: none;
        }
        .mp-search.is-active .mp-seg.is-active-seg:hover {
            background: transparent;
            box-shadow: none;
        }
        .mp-seg-label {
            font-size: 12px; font-weight: 600; color: var(--mp-ink);
            letter-spacing: 0;
        }
        .mp-seg-value {
            font-size: 14px; color: var(--mp-mute);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            max-width: 100%;
        }
        .mp-seg.has-value .mp-seg-value { color: var(--mp-ink); font-weight: 500; }
        .mp-seg-divider {
            width: 1px; margin: 12px 0;
            background: rgba(20,23,26,0.14);
            transition: opacity 180ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }

        /* Who column: same flex:1 share as Where/When, but wraps
           BOTH the Who label button AND the Search submit inside
           its column. Column widths stay equal; the Search button's
           expansion consumes only Who's inner label width, never
           spills into Where or When. */
        .mp-seg-who {
            position: relative;
            z-index: 1;
            flex: 1;
            display: flex; align-items: center;
            padding: 0;
            border-radius: 999px;
            transition: background 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-seg-who-btn {
            flex: 1; min-width: 0;
            display: flex; flex-direction: column; align-items: flex-start;
            gap: 2px;
            padding: 10px 22px;
            background: transparent; border: none; cursor: pointer;
            text-align: left; font-family: inherit; color: inherit;
            border-radius: 999px;
        }
        /* Hover the WHO LABEL button lifts the whole column to white
           (matches active-state sliding hilight visually). Hovering
           the Search submit inside doesn't trigger this, it only
           does its own thing. Achieved via :has() so the parent only
           reacts to the label button's :hover, not the search-submit's. */
        .mp-seg-who:has(.mp-seg-who-btn:hover) {
            background: #FFFFFF;
            box-shadow: 0 2px 8px rgba(20,23,26,0.08);
        }
        .mp-search.is-active .mp-seg-who:not(.is-active-seg):has(.mp-seg-who-btn:hover) {
            background: #D9D9D9;
            box-shadow: none;
        }
        .mp-search.is-active .mp-seg-who.is-active-seg:has(.mp-seg-who-btn:hover) {
            background: transparent;
            box-shadow: none;
        }

        /* Fixed-size search button, label always visible so nothing
           inside the pill reflows when a segment is activated. */
        .mp-search-submit {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px;
            margin-left: 8px;
            padding: 0 22px;
            height: 48px;
            background: var(--mp-accent);
            color: #fff;
            border: none; border-radius: 999px;
            cursor: pointer; font-weight: 600; font-size: 14px;
            font-family: inherit;
            box-shadow: 0 6px 18px -6px var(--mp-accent-glow);
            transition: background 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        box-shadow 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        transform 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-search-submit svg { width: 16px; height: 16px; flex-shrink: 0; }
        .mp-search-submit:hover {
            background: #7A9C33;
            box-shadow: 0 10px 24px -8px var(--mp-accent-glow);
            transform: translateY(-1px);
        }
        /* Label collapses to zero width at rest and expands smoothly
           when a segment is active. max-width animates cleanly;
           opacity fades slightly faster so the text reads by the
           time the width is done growing. The gap between icon and
           label lives on the label's margin-left (not the button's
           `gap`) so it collapses along with the label, no phantom
           8px slot when the label is hidden. */
        .mp-search-submit { gap: 0; }
        .mp-search-submit-label {
            display: inline-block;
            font-size: 13.5px; letter-spacing: -0.005em;
            max-width: 0;
            margin-left: 0;
            opacity: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: max-width  320ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        margin-left 320ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        opacity     220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-search-submit-label.is-visible {
            max-width: 100px;
            margin-left: 8px;
            opacity: 1;
        }

        /* ── Popovers ───────────────────────────────────────────── */
        /* Positioned off .mp-popover-anchor (a 0-height container
           sitting right under the pill), so top:12px = 12px below the
           pill. z-index inside .mp-nav's stacking context, the nav's
           own z-index (40) puts the whole tree above the hero. */
        .mp-popover {
            position: absolute; top: 12px; z-index: 60;
            background: #FFFFFF;
            border: 1px solid var(--mp-line);
            border-radius: 32px;
            box-shadow: 0 24px 60px -20px rgba(20,23,26,0.28);
            padding: 24px;
            overflow: hidden;
            transform-origin: top center;
        }
        .mp-popover-where { left: 0; width: 420px; max-width: calc(100vw - 32px); }
        .mp-popover-when  { left: 50%; transform: translateX(-50%); width: 780px; max-width: calc(100vw - 32px); }
        .mp-popover-who   { right: 0; width: 400px; max-width: calc(100vw - 32px); }

        /* Popover: destinations */
        .mp-pop-title {
            font-size: 12px; font-weight: 600; color: var(--mp-mute);
            letter-spacing: 0.06em; text-transform: uppercase;
            margin: 0 0 12px;
        }
        .mp-dest {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 8px; border-radius: 12px;
            background: transparent; border: none; cursor: pointer;
            text-align: left; font-family: inherit; color: inherit;
            width: 100%;
            transition: background 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-dest:hover { background: var(--mp-surface); }
        .mp-dest-glyph {
            width: 44px; height: 44px; flex-shrink: 0;
            border-radius: 10px;
            background: #EEF2FF;
            color: #3F6FA3;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .mp-dest-glyph svg { width: 22px; height: 22px; }
        .mp-dest-name { font-size: 14px; font-weight: 600; color: var(--mp-ink); }
        .mp-dest-sub  { font-size: 13px; color: var(--mp-mute); margin-top: 2px; }

        /* Popover: calendar */
        .mp-when-toggle {
            display: flex; justify-content: center; margin-bottom: 8px;
        }
        .mp-when-toggle-inner {
            display: inline-flex;
            background: var(--mp-surface);
            border-radius: 999px;
            padding: 4px;
            gap: 2px;
        }
        .mp-when-toggle button {
            border: none; background: transparent; cursor: pointer;
            padding: 8px 18px; border-radius: 999px;
            font-size: 13px; font-weight: 500; color: var(--mp-mute);
            font-family: inherit;
            transition: background 180ms, color 180ms;
        }
        .mp-when-toggle button.is-active {
            background: #fff; color: var(--mp-ink);
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        .mp-cal {
            display: grid; grid-template-columns: 1fr 1fr; gap: 24px;
            padding: 12px 8px 0;
            position: relative;
        }
        @media (max-width: 720px) {
            .mp-cal { grid-template-columns: 1fr; }
        }
        .mp-cal-nav {
            position: absolute; top: 6px;
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 999px;
            border: 1px solid var(--mp-line); background: #fff; cursor: pointer;
            color: var(--mp-ink);
        }
        .mp-cal-nav:hover { background: var(--mp-surface); }
        .mp-cal-nav:disabled { opacity: 0.35; cursor: default; }
        .mp-cal-nav svg { width: 12px; height: 12px; }
        .mp-cal-nav.prev { left: 8px; }
        .mp-cal-nav.next { right: 8px; }
        .mp-month-title {
            text-align: center; font-size: 14px; font-weight: 600; color: var(--mp-ink);
            margin: 4px 0 10px;
        }
        .mp-week {
            display: grid; grid-template-columns: repeat(7, 1fr);
            gap: 0; font-size: 11px; color: var(--mp-mute);
            text-align: center; margin-bottom: 4px;
        }
        .mp-days {
            display: grid; grid-template-columns: repeat(7, 1fr);
            gap: 2px 0;
        }
        .mp-day {
            aspect-ratio: 1 / 1;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 500; color: var(--mp-ink);
            border: none; background: transparent; cursor: pointer;
            border-radius: 999px;
            font-family: inherit;
            position: relative;
        }
        .mp-day:hover:not(.is-past):not(.is-empty) {
            outline: 1px solid var(--mp-ink);
            outline-offset: -2px;
        }
        .mp-day.is-past { color: var(--mp-line-strong); cursor: default; text-decoration: line-through; text-decoration-color: rgba(0,0,0,0.15); }
        .mp-day.is-empty { pointer-events: none; }
        .mp-day.is-selected {
            background: var(--mp-ink); color: #fff;
        }
        .mp-day.is-in-range {
            background: var(--mp-accent-soft);
            color: var(--mp-ink);
            border-radius: 0;
        }
        .mp-flex {
            margin-top: 18px; padding-top: 16px;
            border-top: 1px solid var(--mp-line);
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .mp-flex-chip {
            padding: 8px 14px; border-radius: 999px;
            border: 1px solid var(--mp-line-strong); background: #fff;
            font-size: 13px; color: var(--mp-ink); cursor: pointer;
            font-family: inherit;
            transition: background 160ms, border-color 160ms;
        }
        .mp-flex-chip:hover { background: var(--mp-surface); }
        .mp-flex-chip.is-active {
            background: var(--mp-ink); color: #fff;
            border-color: var(--mp-ink);
        }

        /* Popover: guest counters */
        .mp-guest-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 4px;
            border-bottom: 1px solid var(--mp-line);
        }
        .mp-guest-row:last-of-type { border-bottom: none; }
        .mp-guest-title { font-size: 15px; font-weight: 600; color: var(--mp-ink); }
        .mp-guest-sub   { font-size: 13px; color: var(--mp-mute); margin-top: 2px; }
        .mp-guest-step {
            display: inline-flex; align-items: center; gap: 12px;
        }
        .mp-guest-btn {
            width: 32px; height: 32px; border-radius: 999px;
            border: 1px solid var(--mp-line-strong); background: #fff;
            cursor: pointer; font-size: 16px; font-weight: 500;
            color: var(--mp-ink); font-family: inherit;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color 160ms, color 160ms, background 160ms;
        }
        .mp-guest-btn:hover:not(:disabled) { border-color: var(--mp-ink); }
        .mp-guest-btn:disabled { opacity: 0.35; cursor: default; }
        .mp-guest-count { min-width: 20px; text-align: center; font-size: 15px; color: var(--mp-ink); }

        @media (max-width: 720px) {
            .mp-search { flex-direction: column; border-radius: 24px; padding: 4px; }
            .mp-seg { border-radius: 20px; padding: 12px 16px; }
            .mp-seg-divider { display: none; }
            /* Who column carries the search submit inside it, on
               mobile we stack them (label on top, big pill button
               below) so the submit doesn't overlay the Who field. */
            .mp-seg-who { flex-direction: column; align-items: stretch; gap: 6px; }
            .mp-seg-who-btn { padding: 12px 16px; border-radius: 20px; }
            .mp-search-submit {
                width: 100%; margin: 0;
                height: 48px; border-radius: 20px;
            }
            /* Force the "Search" label to render on mobile, the
               desktop trick of expanding it only when active reads
               as an empty pill on touch. */
            .mp-search-submit-label {
                max-width: 200px; margin-left: 8px; opacity: 1;
            }
            .mp-popover-when, .mp-popover-where, .mp-popover-who {
                left: 8px !important; right: 8px !important;
                width: auto !important; transform: none !important;
            }
        }

        /* ── Content rows ───────────────────────────────────────── */
        .mp-row { padding: 32px 0 8px; }
        .mp-row-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 16px;
        }
        .mp-row-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 26px; font-weight: 600; color: var(--mp-ink);
            display: inline-flex; align-items: center; gap: 8px;
            letter-spacing: -0.015em;
        }
        .mp-row-title a { display: inline-flex; align-items: center; }
        .mp-row-title svg { width: 16px; height: 16px; }
        .mp-row-subtitle { margin-top: 2px; font-size: 13px; color: #6A6A6A; }
        .mp-row-arrows { display: flex; gap: 8px; }
        .mp-row-arrow {
            width: 32px; height: 32px; border-radius: 999px;
            border: 1px solid #DDDDDD; background: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
        }
        .mp-row-arrow:hover { border-color: #B0B0B0; }
        .mp-row-arrow svg { width: 12px; height: 12px; color: #222; }

        .mp-scroll {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(220px, 1fr);
            gap: 20px;
            overflow-x: auto;
            padding: 4px 0 16px;
            scroll-snap-type: x mandatory;
            scrollbar-width: none;
        }
        .mp-scroll::-webkit-scrollbar { display: none; }
        @media (min-width: 1024px) {
            .mp-scroll { grid-auto-columns: minmax(0, calc((100% - 20px * 6) / 7)); }
        }
        @media (max-width: 640px) {
            .mp-scroll { grid-auto-columns: 68%; }
        }

        /* ── Listing card ───────────────────────────────────────── */
        .mp-card {
            scroll-snap-align: start;
            transition: transform 240ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-card:hover { transform: translateY(-4px); }
        .mp-card:hover .mp-card-photo {
            box-shadow: 0 22px 40px -20px rgba(20, 23, 26, 0.25);
        }
        .mp-card-photo {
            position: relative;
            aspect-ratio: 4 / 3.4;
            border-radius: 14px;
            overflow: hidden;
            background: linear-gradient(135deg, #F2EFE7 0%, #E4E0D3 100%);
            transition: box-shadow 240ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-card-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .mp-card-photo-stub {
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: rgba(20,23,26,0.35);
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 12px;
            letter-spacing: 0.06em; text-transform: uppercase;
            background: repeating-linear-gradient(45deg, #F1F1F1, #F1F1F1 8px, #EAEAEA 8px, #EAEAEA 16px);
        }
        .mp-card-heart {
            position: absolute; top: 12px; right: 12px;
            width: 30px; height: 30px;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
        }
        .mp-card-heart svg {
            width: 22px; height: 22px;
            color: #fff;
            fill: rgba(0,0,0,0.35);
            stroke-width: 2;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,0.15));
        }
        .mp-card-heart:hover svg { fill: rgba(0,0,0,0.5); }
        .mp-card-tag {
            position: absolute; top: 12px; left: 12px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #fff;
            font-size: 11px; font-weight: 600; color: #222;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .mp-card-info { padding-top: 10px; }
        .mp-card-title-row {
            display: flex; justify-content: space-between; align-items: baseline; gap: 8px;
        }
        .mp-card-title {
            font-size: 14px; font-weight: 600; color: #222;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .mp-card-rating {
            font-size: 13px; color: #222;
            display: inline-flex; align-items: center; gap: 3px;
        }
        .mp-card-rating svg { width: 11px; height: 11px; }
        .mp-card-sub { font-size: 13px; color: #6A6A6A; margin-top: 2px; }
        .mp-card-price { font-size: 13px; color: #222; margin-top: 4px; }
        .mp-card-price b { font-weight: 600; }

        /* ── Editorial hero ─────────────────────────────────────── */
        .mp-hero {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 40px;
            align-items: center;
            padding: 56px 40px 24px;
            max-width: 1440px; margin: 0 auto;
        }
        @media (max-width: 900px) {
            .mp-hero { grid-template-columns: 1fr; padding: 32px 20px 12px; gap: 24px; }
        }
        .mp-hero-copy > * {
            opacity: 0;
            transform: translateY(14px);
            animation: mp-rise 700ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)) forwards;
        }
        .mp-hero-copy > *:nth-child(1) { animation-delay: 40ms; }
        .mp-hero-copy > *:nth-child(2) { animation-delay: 160ms; }
        .mp-hero-copy > *:nth-child(3) { animation-delay: 280ms; }
        @keyframes mp-rise {
            to { opacity: 1; transform: translateY(0); }
        }
        .mp-hero-eyebrow {
            font-size: clamp(20px, 2vw, 26px);
            display: inline-block;
            position: relative;
            padding-bottom: 4px;
        }
        /* Hand-drawn olive underline that grows in after load. */
        .mp-hero-eyebrow::after {
            content: ""; position: absolute;
            left: 0; right: 8px; bottom: -2px;
            height: 6px;
            background: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 220 6' preserveAspectRatio='none'><path d='M0 4 C 40 0, 90 6, 130 3 S 200 5, 220 2' stroke='%23ADCB52' stroke-width='2' fill='none' stroke-linecap='round'/></svg>") no-repeat center/100% 100%;
            opacity: 0.9;
            transform: scaleX(0);
            transform-origin: left center;
            animation: mp-underline 900ms 400ms cubic-bezier(0.65, 0, 0.35, 1) forwards;
        }
        @keyframes mp-underline { to { transform: scaleX(1); } }
        .mp-hero-headline {
            font-size: clamp(56px, 7.5vw, 104px);
            line-height: 1.02;
            color: var(--mp-ink);
            margin: 8px 0 0;
        }
        .mp-hero-eyebrow {
            font-size: clamp(22px, 2.2vw, 30px);
        }
        .mp-hero-sub {
            margin: 20px 0 0; max-width: 52ch;
            font-size: 15px; line-height: 1.6;
            color: var(--mp-ink-soft);
        }

        /* ── Mobile hero search CTA ───────────────────────────────
           Big brand-colour pill sitting under the hero copy on
           mobile, right before the tile block. Fires an
           mp-open-search window event that the nav's shared x-data
           listens for → opens the same bottom-sheet form the nav
           chip opens. Hidden ≥ 641px so tablet + desktop keep the
           original search-in-nav-pill flow. */
        .mp-hero-search-btn {
            display: none;                        /* revealed at ≤ 640px */
            width: auto;                          /* content-sized, not block */
            align-items: center; gap: 10px;
            margin: 24px 0 4px;
            padding: 16px 28px;
            background: var(--mp-accent);
            color: #FFFFFF;
            border: none;
            border-radius: 999px;
            box-shadow: 0 12px 28px -10px var(--mp-accent-glow);
            font-family: inherit;
            font-size: 16px; font-weight: 600;
            letter-spacing: -0.005em;
            cursor: pointer;
            transition: background 200ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        transform 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        box-shadow 200ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-hero-search-btn:hover,
        .mp-hero-search-btn:active {
            background: #7A9C33;
            transform: translateY(-1px);
            box-shadow: 0 16px 32px -12px var(--mp-accent-glow);
        }
        .mp-hero-search-btn svg {
            width: 18px; height: 18px;
            color: #FFFFFF;
            flex-shrink: 0;
        }
        @media (max-width: 640px) {
            .mp-hero-search-btn { display: inline-flex; }
        }

        /* ── Mobile nav search chip ───────────────────────────────
           A compact pill sitting inline in .mp-nav-right on phones,
           right before the language button. Tap opens the full
           Where/When/Who form as a bottom sheet (see .mp-search-shell
           .is-mobile-sheet rules further below). Hidden ≥ 641px -
           tablet + desktop see the original multi-field pill under
           the nav-inner as always. */
        .mp-mobile-search-chip {
            display: none;                 /* revealed at ≤ 640px */
            align-items: center; gap: 8px;
            padding: 6px 14px 6px 12px;
            border: 1px solid #DDDDDD;
            border-radius: 999px;
            background: #FFFFFF;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            font-family: inherit; font-size: 13px; font-weight: 500;
            color: var(--mp-ink);
            cursor: pointer;
            min-height: 36px;
            max-width: 240px;
        }
        .mp-mobile-search-chip svg {
            width: 14px; height: 14px;
            color: var(--mp-accent);
            flex-shrink: 0;
        }
        .mp-mobile-search-chip span {
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            color: var(--mp-mute); font-weight: 400;
        }
        .mp-mobile-search-chip:hover,
        .mp-mobile-search-chip:active { background: var(--mp-surface); }
        .mp-hero-tiles {
            position: relative;
            aspect-ratio: 5 / 4.2;
            min-height: 260px;
        }
        .mp-hero-tile {
            position: absolute;
            border-radius: 18px;
            overflow: hidden;
            background: linear-gradient(135deg, #EFEBDD 0%, #E4DFCC 100%);
            box-shadow: 0 20px 40px -18px rgba(20, 23, 26, 0.22);
            display: flex; align-items: flex-end; justify-content: flex-start;
            padding: 14px;
            opacity: 0;
            transform: translateY(20px) rotate(0deg);
            animation: mp-rise-tile 800ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)) forwards;
        }
        /* Per-tile badge placement: workspace bottom-right, rent
           dead-centre, stay stays bottom-left (default). */
        .mp-hero-tile-b { justify-content: flex-end; }
        .mp-hero-tile-c { justify-content: center; }
        .mp-hero-tile > img {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
            z-index: 0;
        }
        /* Soft bottom scrim so the .mp-hero-tag pill stays legible over
           any photo. */
        .mp-hero-tile::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(20,23,26,0.35) 0%, rgba(20,23,26,0) 40%);
            pointer-events: none;
            z-index: 1;
        }
        .mp-hero-tile .mp-hero-tag { position: relative; z-index: 2; }
        .mp-hero-tile-a {
            width: 62%; height: 78%;
            top: 0; left: 0;
            transform: translateY(20px) rotate(-2deg);
            animation-delay: 200ms;
        }
        .mp-hero-tile-b {
            width: 46%; height: 46%;
            top: 30%; right: 4%;
            transform: translateY(20px) rotate(3deg);
            animation-delay: 380ms;
        }
        .mp-hero-tile-c {
            width: 40%; height: 40%;
            bottom: 0; left: 22%;
            transform: translateY(20px) rotate(-1deg);
            animation-delay: 560ms;
        }
        @keyframes mp-rise-tile {
            to { opacity: 1; transform: translateY(0) rotate(var(--rot, 0deg)); }
        }
        .mp-hero-tile-a { --rot: -2deg; }
        .mp-hero-tile-b { --rot: 3deg; }
        .mp-hero-tile-c { --rot: -1deg; }
        .mp-hero-tag {
            font-family: 'Inter', sans-serif;
            font-size: 10.5px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            background: var(--mp-card);
            color: var(--mp-ink);
            padding: 5px 10px;
            border-radius: 999px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        /* Reveal-on-scroll rows, starts translated + invisible, the
           IntersectionObserver at the bottom of <body> flips them in
           when they enter the viewport. Rows below stay put; no layout
           thrash. Respects prefers-reduced-motion. */
        .mp-row {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 620ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        transform 620ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
            will-change: opacity, transform;
        }
        .mp-row.is-in {
            opacity: 1; transform: translateY(0);
        }
        @media (prefers-reduced-motion: reduce) {
            .mp-row, .mp-hero-copy > *, .mp-hero-tile, .mp-hero-eyebrow::after {
                opacity: 1 !important;
                transform: none !important;
                animation: none !important;
                transition: none !important;
            }
        }

        /* ── Coming-soon band ───────────────────────────────────── */
        .mp-honesty {
            margin: 24px 0 8px;
            padding: 14px 20px;
            background: var(--mp-accent-soft);
            border: 1px solid rgba(138, 174, 58, 0.28);
            border-radius: 12px;
            font-size: 13px; color: #3C531A;
            display: flex; align-items: center; gap: 12px;
        }
        .mp-honesty svg { width: 16px; height: 16px; flex-shrink: 0; color: var(--mp-accent); }
        .mp-honesty a { color: var(--mp-accent); font-weight: 600; text-decoration: underline; }

        /* ── Host CTA band ──────────────────────────────────────── */
        .mp-host-band {
            margin: 48px 0;
            padding: 40px;
            background: var(--mp-ink);
            color: var(--mp-card);
            border-radius: 20px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 24px;
            align-items: center;
        }
        .mp-host-band h2 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: clamp(24px, 3vw, 34px);
            font-weight: 600; color: var(--mp-card);
            letter-spacing: -0.015em; margin: 0 0 8px;
        }
        .mp-host-band p { font-size: 14.5px; color: rgba(255,255,255,0.72); margin: 0; max-width: 60ch; line-height: 1.6; }
        .mp-host-band .mp-btn {
            background: var(--mp-accent); color: var(--mp-ink);
            padding: 12px 22px; border-radius: 999px;
            font-weight: 600; font-size: 14px;
            display: inline-flex; align-items: center; gap: 8px;
            border: none; cursor: pointer; white-space: nowrap;
            font-family: inherit;
            box-shadow: 0 8px 24px -10px var(--mp-accent-glow);
            transition: background 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)),
                        transform 220ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
        }
        .mp-host-band .mp-btn:hover {
            background: var(--mp-accent-light);
            transform: translateY(-1px);
        }
        @media (max-width: 640px) {
            .mp-host-band { grid-template-columns: 1fr; padding: 24px; }
        }

        /* ── Footer ─────────────────────────────────────────────── */
        .mp-footer {
            margin-top: 40px;
            padding: 32px 0 24px;
            border-top: 1px solid var(--mp-line);
            font-size: 13px; color: var(--mp-mute);
        }
        .mp-footer-links {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 720px) { .mp-footer-links { grid-template-columns: repeat(2, 1fr); } }
        .mp-footer h4 {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 14px; font-weight: 600; color: var(--mp-ink);
            letter-spacing: -0.01em;
            margin: 0 0 10px;
        }
        .mp-footer ul { list-style: none; padding: 0; margin: 0; }
        .mp-footer li { margin: 6px 0; }
        .mp-footer li a { transition: color 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1)); }
        .mp-footer li a:hover { color: var(--mp-accent); }
        .mp-footer-bottom {
            padding-top: 20px; border-top: 1px solid var(--mp-line);
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
            flex-wrap: wrap;
        }

        /* ═══════════════════════════════════════════════════════════
           MOBILE REFINEMENTS
           ────────────────────────────────────────────────────────────
           Layered on top of the desktop rules above. Two breakpoints:

             ≤ 900px , tablet / large phone landscape. Layout stays
                        readable but the nav stacks + hides labels.

             ≤ 640px , phone portrait. Categories become a horizontal
                        scroll row below the brand+controls; icons +
                        typography scale down; touch targets ≥ 44px.

           Keeps the desktop experience untouched. Nothing here uses
           !important except where an inner rule uses fixed widths that
           can't be responsive otherwise.
           ═══════════════════════════════════════════════════════════ */

        /* No accidental horizontal scroll from an oversized element -
           `overflow-x: clip` prevents the scroll without creating a
           new scroll container, which would otherwise break the
           .mp-nav's `position: sticky` on desktop / tablet. Safari
           16+ supports it; older Safari just doesn't get the clip
           (layout still correct, no sticky regression). */
        html, body { max-width: 100vw; overflow-x: clip; }

        @media (max-width: 900px) {
            /* Hide the "Become a host" text link, icon menu still
               gives access to that page (via right-side menu). Frees
               room for the middle column. */
            .mp-nav-link[data-testid="become-host"] { display: none; }

            /* Hero: safety net so the copy never overflows the viewport
               when a browser doesn't clamp() the headline low enough. */
            .mp-hero-headline { font-size: clamp(38px, 8vw, 64px); }
            .mp-hero-eyebrow  { font-size: clamp(18px, 3vw, 22px); }

            /* Content rows: hide the manual left/right arrows on
               tablet-and-narrower, thumb-swipe is faster and the
               arrows chew the header row. */
            .mp-row-arrows { display: none; }
            .mp-row-title { font-size: 22px; }
        }

        @media (max-width: 640px) {
            /* NAV, FIXED to the top of the viewport on mobile so the
               brand + hamburger stay tappable while the user scrolls
               through hero + content. Simplified 2-column layout:
               brand · right controls. Middle slot (categories +
               summary pill) collapses to zero at rest; the summary
               pill still surfaces via .is-scrolled so users can
               always reach search. Body gets a matching padding-top
               so hero doesn't hide behind the fixed header. */
            .mp-nav {
                position: fixed;
                top: 0; left: 0; right: 0;
            }
            .mp-nav-inner {
                grid-template-columns: 1fr auto;
                grid-template-areas: "brand right" "middle middle";
                gap: 8px 12px;
                padding: 10px 0 6px;
                row-gap: 4px;
            }
            .mp-nav-inner > :first-child { grid-area: brand; }
            .mp-nav-middle              { grid-area: middle; width: 100%; min-width: 0; }
            .mp-nav-right               { grid-area: right; }
            /* Push page content down so nothing sits under the fixed
               nav. Value tuned to a single-row nav (brand + icons). */
            body.mp-page { padding-top: 60px; }
            /* When scrolled, the summary pill row adds a bit, bump
               the padding so the hero doesn't jump under it. */
            .mp-nav.is-scrolled ~ * { }   /* no-op, kept for readability */

            /* Brand: keep the mark, tighten the wordmark size so it
               shares the row cleanly with the two icon buttons. */
            .mp-brand      { font-size: 18px; gap: 6px; }
            .mp-brand-mark { width: 24px; height: 24px; }

            /* Category tabs hide on mobile, moved into the mobile
               drawer (see .mp-mobile-drawer below). The middle slot
               only carries the scrolled summary pill on phone. */
            .mp-cats { display: none; }
            .mp-nav-middle { min-width: 0; }
            /* Row height collapse, with .mp-cats gone the middle slot
               can be zero-height when the summary pill is hidden too
               (i.e. before scroll). Grid rows fluid so the header stays
               tight. */
            .mp-nav-inner { row-gap: 0; }

            /* On mobile the search shell is hidden by default -
               it appears as a bottom sheet when the operator taps
               the mobile-search-chip in the nav (see the
               .mp-search-shell.is-mobile-sheet rules below).
               .mp-summary-inline (the scrolled compact pill on
               desktop) is also silent on mobile, its job is done
               by the chip. */
            .mp-search-shell { display: none; }
            .mp-summary-inline { display: none !important; }
            /* Show the mobile chip on phones. */
            .mp-mobile-search-chip { display: inline-flex; }

            /* Bottom-sheet layout for the search shell when the
               chip's opened it. Full-width, pinned to the bottom
               of the viewport, slides up over a scrim. The full
               Where / When / Who form + popovers inherit their
               existing 720px stacked styles inside the sheet, so
               everything still lays out cleanly. */
            .mp-search-shell.is-mobile-sheet {
                display: block !important;
                position: fixed;
                left: 0; right: 0; bottom: 0;
                z-index: 80;
                background: #FFFFFF;
                border-top-left-radius: 20px;
                border-top-right-radius: 20px;
                max-height: 92vh;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                padding: 22px 12px 20px;
                box-shadow: 0 -18px 40px -12px rgba(20,23,26,0.28);
                animation: mp-sheet-in 260ms cubic-bezier(0.32, 0.72, 0, 1) both;
            }
            /* Grabber bar at the top of the sheet, visual "swipe to
               dismiss" hint. Rendered via ::before so we don't have
               to inject markup into the shell. */
            .mp-search-shell.is-mobile-sheet::before {
                content: "";
                position: absolute;
                top: 8px; left: 50%;
                transform: translateX(-50%);
                width: 42px; height: 4px; border-radius: 999px;
                background: var(--mp-line-strong);
            }
            /* Neutralise the sticky-nav's scroll-collapse math when
               the shell is standing on its own in sheet mode. */
            .mp-search-shell.is-mobile-sheet .mp-search-wrap {
                grid-template-rows: 1fr !important;
            }
            .mp-search-shell.is-mobile-sheet .mp-search {
                opacity: 1 !important;
                transform: none !important;
                pointer-events: auto !important;
                margin: 0;
            }
            /* Popovers in sheet mode: absolute-positioned off the
               anchor still, and the existing 720px override already
               forces left/right to hug the viewport edges. Give the
               anchor a bit of room below the pill so popovers don't
               overlap the pill. */
            .mp-search-shell.is-mobile-sheet .mp-popover-anchor {
                margin-top: 4px;
            }

            /* Right controls, trim padding so the icon pair sits
               inside the narrow slot without pushing brand off-screen. */
            .mp-nav-right { gap: 4px; }
            .mp-nav-icon-btn { padding: 8px; min-width: 40px; min-height: 40px; }

            /* HERO, reduce the tile block's min-height so the whole
               hero (copy + tiles) fits above the fold on 375×667
               phones without huge whitespace. */
            .mp-hero { padding: 20px 20px 8px; gap: 16px; }
            .mp-hero-tiles { min-height: 220px; }
            .mp-hero-sub { font-size: 14px; margin-top: 12px; }

            /* CONTENT ROWS, cards are already 68% each with snap;
               tighten row padding + heading so vertical density is
               reasonable on a phone. */
            .mp-row { padding: 20px 0 4px; }
            .mp-row-head { margin-bottom: 10px; }
            .mp-row-title { font-size: 20px; }
            .mp-row-subtitle { font-size: 12.5px; }
            .mp-scroll { gap: 12px; padding-bottom: 12px; }
            .mp-card-photo { border-radius: 12px; }
            .mp-card-title { font-size: 14px; }
            .mp-card-sub, .mp-card-price { font-size: 12.5px; }

            /* HOST BAND, smaller pad + CTA sits full-width under copy. */
            .mp-host-band { padding: 20px; margin: 24px 0; border-radius: 16px; }
            .mp-host-band h2 { font-size: 22px; }
            .mp-host-band .mp-btn { width: 100%; justify-content: center; padding: 14px 22px; }

            /* FOOTER, legibility + touch targets on link lists. */
            .mp-footer { padding: 20px 0 16px; margin-top: 24px; }
            .mp-footer li a { display: inline-block; padding: 6px 0; min-height: 32px; }
            .mp-footer-bottom { flex-direction: column; align-items: flex-start; gap: 6px; font-size: 12px; }

            /* HONESTY BAND, smaller copy fits on one visual row. */
            .mp-honesty { font-size: 12.5px; padding: 12px 14px; gap: 8px; }

            /* SEARCH PILL, already stacks at 720px; harden the
               submit button so the "Search" label reads even when
               it's the only visible element on the pill. */
            .mp-search-submit { justify-content: center; }
            .mp-search-submit-label.is-visible { max-width: 200px; }
        }

        /* Search-sheet keyframe, used only at ≤ 640px but defined
           at global scope so the animation is available to the
           class regardless of media context. */
        @keyframes mp-sheet-in {
            from { transform: translateY(24px); opacity: 0; }
            to   { transform: translateY(0);   opacity: 1; }
        }

        /* Scrim behind the mobile bottom sheet, sits above the
           fixed nav but below the sheet itself. Only paints on
           mobile via the media query below. */
        .mp-mobile-sheet-scrim {
            position: fixed; inset: 0;
            background: rgba(20, 23, 26, 0.45);
            z-index: 70;
            display: none;
        }
        @media (max-width: 640px) {
            .mp-mobile-sheet-scrim { display: block; }
        }

        /* Desktop / tablet, the mobile chip has no home; hard-hide
           it. The sheet class shouldn't fire either but neutralise
           it defensively so a stale flag can't paint anywhere. */
        @media (min-width: 641px) {
            .mp-mobile-search-chip { display: none !important; }
            .mp-search-shell.is-mobile-sheet {
                position: static !important;
                animation: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                border-radius: 0 !important;
                max-height: none !important;
            }
        }

        /* Ultra-narrow phones (≤ 380px), squeeze one more notch so
           the brand mark + chip + icons don't overflow. */
        @media (max-width: 380px) {
            .mp-brand span:last-child { display: none; } /* mark only */
            .mp-mobile-search-chip { max-width: 140px; padding: 6px 10px 6px 10px; }
            .mp-mobile-search-chip span { font-size: 12px; }
        }

        /* ═══════════════════════════════════════════════════════════
           MOBILE DRAWER, categories + account links live here on
           phone (≤ 640px). Slides in from the right on top of a scrim.
           Alpine handles show/hide via `mobileMenu` on the parent
           x-data. Hidden by default on every viewport; a paired media
           query below shows the toggle button + surfaces on phones.
           ═══════════════════════════════════════════════════════════ */
        .mp-mobile-scrim {
            position: fixed; inset: 0;
            background: rgba(20, 23, 26, 0.45);
            z-index: 70;
            display: none;                 /* revealed at ≤ 640px via display: block */
        }
        .mp-mobile-drawer {
            position: fixed; top: 0; right: 0; bottom: 0;
            width: 88vw; max-width: 360px;
            background: #FFFFFF;
            z-index: 75;
            display: none;                 /* revealed at ≤ 640px */
            flex-direction: column;
            box-shadow: -12px 0 40px -12px rgba(20,23,26,0.35);
        }
        .mp-mobile-drawer-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--mp-line);
        }
        .mp-mobile-drawer-title {
            font-family: 'Fraunces', Georgia, serif;
            font-size: 16px; font-weight: 600; color: var(--mp-ink);
            letter-spacing: -0.01em;
        }
        .mp-mobile-drawer-body {
            flex: 1;
            padding: 12px 12px 20px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .mp-mobile-drawer-eyebrow {
            display: block;
            padding: 10px 12px 4px;
            font-family: 'Inter', sans-serif;
            font-size: 10.5px; font-weight: 700; letter-spacing: 0.12em;
            text-transform: uppercase; color: var(--mp-mute);
        }
        .mp-mobile-menu-item {
            width: 100%;
            display: flex; align-items: center; gap: 12px;
            padding: 14px 12px;
            font-family: inherit; font-size: 15px; font-weight: 500;
            color: var(--mp-ink);
            background: transparent; border: none;
            text-align: left;
            border-radius: 10px;
            cursor: pointer;
            transition: background 160ms var(--mp-ease, cubic-bezier(0.32, 0.72, 0, 1));
            min-height: 52px;
        }
        .mp-mobile-menu-item:hover,
        .mp-mobile-menu-item:active { background: var(--mp-surface); }
        .mp-mobile-menu-item.is-active {
            background: var(--mp-accent-soft);
            color: var(--mp-ink);
            font-weight: 600;
        }
        .mp-mobile-menu-item.is-active .mp-mobile-menu-chev {
            color: var(--mp-accent);
        }
        .mp-mobile-menu-icon {
            width: 32px; height: 32px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .mp-mobile-menu-icon--glyph {
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px;
            background: var(--mp-surface);
            border-radius: 8px;
        }
        .mp-mobile-menu-chev {
            margin-left: auto;
            width: 12px; height: 12px;
            color: var(--mp-mute);
            flex-shrink: 0;
        }
        .mp-mobile-drawer-hr {
            margin: 12px 4px;
            border: 0; border-top: 1px solid var(--mp-line);
        }
        .mp-mobile-drawer-foot {
            padding: 14px 18px;
            border-top: 1px solid var(--mp-line);
            font-size: 12.5px; color: var(--mp-mute);
        }

        /* Enter / leave animations, plain CSS classes Alpine picks
           up via x-transition:enter / :leave. */
        .mp-fade-enter { animation: mp-scrim-in 220ms ease-out both; }
        .mp-fade-leave { animation: mp-scrim-in 200ms ease-in reverse both; }
        .mp-drawer-enter { animation: mp-drawer-in 260ms cubic-bezier(0.32, 0.72, 0, 1) both; }
        .mp-drawer-leave { animation: mp-drawer-in 220ms cubic-bezier(0.32, 0.72, 0, 1) reverse both; }
        @keyframes mp-scrim-in { from { opacity: 0; } to { opacity: 1; } }
        @keyframes mp-drawer-in {
            from { opacity: 0; transform: translateX(24px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        @media (max-width: 640px) {
            .mp-mobile-scrim,
            .mp-mobile-drawer { display: flex; }
        }

        /* Above 640px the hamburger menu still exists in the markup
           but doesn't open the drawer (there's no drawer to see) -
           hide the drawer + scrim + toggle to keep the desktop nav
           unchanged. */
        @media (min-width: 641px) {
            [data-testid="mobile-menu-toggle"] { display: none; }
        }
    </style>
</head>
<body class="mp-page">

@php
    // Category tab defaults from ?type= so /?type=workspace opens Workspace.
    $activeTab = in_array(request()->query('type'), ['all', 'stay', 'workspace', 'rent'], true)
        ? request()->query('type')
        : 'all';

    $today = now()->toDateString();
    $tomorrow = now()->addDay()->toDateString();

    // Placeholder rows, same shape a real cross-tenant search will emit
    // (title, sub, city, price, rating, tag). Cheap to swap for a query
    // once the index exists.
    $mkCard = fn (string $title, string $sub, ?string $city, ?int $price, ?float $rating, ?string $tag = null) => compact('title', 'sub', 'city', 'price', 'rating', 'tag');

    $rowPopularStays = [
        $mkCard('Modern apartment in Ikoyi', 'Serviced · 2 beds',   'Lagos', 65_000, 4.9, 'Guest favourite'),
        $mkCard('Rooftop pool suite',        'Boutique hotel',       'Abuja', 88_000, 5.0, 'Guest favourite'),
        $mkCard('Garden loft, Wuse II',      'Serviced · 1 bed',     'Abuja', 45_000, 4.8, null),
        $mkCard('Beachfront cottage',        'Full house · 3 beds',  'Lagos', 120_000, 4.95, 'Superhost'),
        $mkCard('Studio near GRA',           'Short-let',            'Port Harcourt', 32_000, 4.7, null),
        $mkCard('Downtown suite',            'Boutique hotel',       'Enugu', 55_000, 4.85, null),
        $mkCard('Skyline flat',              'Serviced · 2 beds',    'Lagos', 78_000, 4.92, 'Guest favourite'),
    ];

    $rowHotels = [
        $mkCard('Villa Rosa Boutique',       'Hotel · 4-star',       'Lagos', 92_000, 4.7, null),
        $mkCard('Fraser Suites',             'Serviced hotel',        'Abuja', 145_000, 4.8, 'Guest favourite'),
        $mkCard('The Rose Wood',             'Boutique hotel',        'Port Harcourt', 68_000, 4.6, null),
        $mkCard('Nike Lake Resort',          'Resort · lake view',    'Enugu', 110_000, 4.85, null),
        $mkCard('Chelsea Hotel',             'Business hotel',        'Abuja', 74_000, 4.5, null),
        $mkCard('Radisson Blu Anchorage',    'Hotel · 5-star',        'Lagos', 220_000, 4.9, 'Guest favourite'),
        $mkCard('Golden Tulip',              'Business hotel',        'Ibadan', 58_000, 4.55, null),
    ];

    $rowWorkspaces = [
        $mkCard('Workstation on Adeola Odeku','Hot desk · shared',    'Lagos', 12_000, 4.8, null),
        $mkCard('Regus Wuse II',             'Private office',        'Abuja', 25_000, 4.6, null),
        $mkCard('CoLab Enugu',               'Meeting room · 6-seat', 'Enugu', 18_000, 4.7, 'Guest favourite'),
        $mkCard('The Zone',                  'Day pass',              'Lagos',  9_000, 4.5, null),
        $mkCard('Impact Hub',                'Monthly desk',          'Abuja', 90_000, 4.8, null),
        $mkCard('Terra Workspace',           'Meeting room · 12',     'Port Harcourt', 22_000, 4.6, null),
        $mkCard('WorkNomads GRA',            'Hot desk',              'Port Harcourt', 8_500, 4.4, null),
    ];

    $rowRentals = [
        $mkCard('3-bedroom flat, Lekki Phase 1', 'Annual lease',     'Lagos', 6_500_000, null, null),
        $mkCard('4-bedroom terrace, Jabi',       'Annual lease',     'Abuja', 8_200_000, null, null),
        $mkCard('2-bedroom apartment, Yaba',     'Annual lease',     'Lagos', 3_400_000, null, null),
        $mkCard('Detached duplex, Maitama',      'Annual lease',     'Abuja', 15_000_000, null, 'New'),
        $mkCard('Mini flat, Surulere',           'Annual lease',     'Lagos', 1_800_000, null, null),
        $mkCard('3-bedroom bungalow',            'Annual lease',     'Enugu', 2_400_000, null, null),
        $mkCard('5-bedroom mansion, VI',         'Annual lease',     'Lagos', 22_000_000, null, null),
    ];
@endphp

<div class="mp-page">

    {{-- ═══ TOP NAV ═════════════════════════════════════════════ --}}
    {{-- .is-scrolled is toggled by the inline scroll script at the
         bottom of <body>. Native listener, no Alpine dependency, so
         the collapse works even if Alpine is late booting or absent. --}}
    <header class="mp-nav" data-testid="landing-nav" data-mp-nav>
        <script>
            // Attach the scroll listener eagerly. This <script> is the
            // first child of the <header>, so `currentScript.parentElement`
            // is the header itself (already in the DOM by the time this
            // inline script runs). Passive + rAF-throttled so scrolling
            // stays smooth. No Alpine dependency.
            (function () {
                var script = document.currentScript;
                var nav = script && script.parentElement;
                if (! nav || ! nav.hasAttribute('data-mp-nav')) {
                    // Fallback in case currentScript isn't available (very
                    // old browsers), locate by the data attribute.
                    nav = document.querySelector('[data-mp-nav]');
                }
                if (! nav) return;

                var ticking = false;
                var apply = function () {
                    // Respect the temporary force-open flag set when
                    // the summary pill is clicked, user wants the
                    // full pill even though they're scrolled.
                    if (nav.dataset.forceOpen !== '1') {
                        var scrolled = window.scrollY > 80;
                        nav.classList.toggle('is-scrolled', scrolled);
                        // Flag <html> so the hero-search fade CSS
                        // rule can react in parallel with the nav.
                        document.documentElement.dataset.navScrolled = scrolled ? '1' : '0';
                    }
                    ticking = false;
                };
                apply();
                window.addEventListener('scroll', function () {
                    if (! ticking) {
                        window.requestAnimationFrame(apply);
                        ticking = true;
                    }
                }, { passive: true });
            })();
        </script>
        {{-- Lifted x-data, shared between the desktop category tabs
             and the mobile drawer so both surfaces read the same
             `tab`. `mobileMenu` toggles the phone-only side sheet. --}}
        {{-- Shared x-data, desktop nav categories, mobile drawer,
             and mobile search sheet all read/write these flags.
             The hero's search button (outside this container) fires
             a `mp-open-search` window event which flips
             mobileSearch = true via the listener below. --}}
        <div class="mp-container"
             x-data="{ tab: '{{ $activeTab }}', mobileMenu: false, mobileSearch: false }"
             @keydown.escape.window="mobileMenu = false; mobileSearch = false"
             @mp-open-search.window="mobileSearch = true"
             x-effect="document.body.style.overflow = (mobileMenu || mobileSearch) ? 'hidden' : ''">

            {{-- Row 1: logo · middle-slot (categories OR summary pill) · right controls --}}
            <div class="mp-nav-inner">
                <div>
                    <a href="/" class="mp-brand">
                        <span class="mp-brand-mark" aria-hidden>
                            <svg viewBox="0 0 12 12" fill="none"><path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/></svg>
                        </span>
                        <span>Keverna</span>
                    </a>
                </div>

                <div class="mp-nav-middle">
                    <nav class="mp-cats" role="tablist" aria-label="Categories">
                        <button type="button" role="tab" data-testid="cat-all"
                                :aria-selected="tab === 'all'" @click="tab = 'all'"
                                class="mp-cat">
                            <img src="{{ asset('img/nav/all.png') }}" alt="" class="mp-cat-icon" loading="eager" decoding="async">
                            <span>All</span>
                        </button>
                        <button type="button" role="tab" data-testid="cat-stay"
                                :aria-selected="tab === 'stay'" @click="tab = 'stay'"
                                class="mp-cat">
                            <img src="{{ asset('img/nav/stays.png') }}" alt="" class="mp-cat-icon" loading="eager" decoding="async">
                            <span>Stays</span>
                        </button>
                        <button type="button" role="tab" data-testid="cat-workspace"
                                :aria-selected="tab === 'workspace'" @click="tab = 'workspace'"
                                class="mp-cat">
                            <img src="{{ asset('img/nav/workspaces.png') }}" alt="" class="mp-cat-icon" loading="eager" decoding="async">
                            <span>Workspaces</span>
                        </button>
                        <button type="button" role="tab" data-testid="cat-rent"
                                :aria-selected="tab === 'rent'" @click="tab = 'rent'"
                                class="mp-cat">
                            <img src="{{ asset('img/nav/rentals.png') }}" alt="" class="mp-cat-icon" loading="eager" decoding="async">
                            <span>Rentals</span>
                        </button>
                    </nav>

                    {{-- Compact summary pill, shown when scrolled. Click
                         scrolls back to top so the full search re-expands. --}}
                    {{-- Compact summary pill, shown when scrolled.
                         Each segment is its own button that force-opens
                         the full search overlay AND targets the matching
                         popover. Container is a <div> so we can nest
                         real <button> elements (buttons can't nest). --}}
                    <div class="mp-summary-inline" data-testid="scrolled-summary" role="group" aria-label="Search">
                        <button type="button" class="mp-summary-seg"
                                data-testid="scrolled-seg-where"
                                onclick="event.stopPropagation(); window.mpOpenSearch('where');">
                            <img class="home-glyph" src="{{ asset('img/nav/stays.png') }}" alt="" aria-hidden="true">
                            Anywhere
                        </button>
                        <button type="button" class="mp-summary-seg mute"
                                data-testid="scrolled-seg-when"
                                onclick="event.stopPropagation(); window.mpOpenSearch('when');">
                            Any week
                        </button>
                        <button type="button" class="mp-summary-seg mute"
                                data-testid="scrolled-seg-who"
                                onclick="event.stopPropagation(); window.mpOpenSearch('who');">
                            Add guests
                        </button>
                        <button type="button" class="search-btn"
                                aria-label="Open search"
                                onclick="event.stopPropagation(); window.mpOpenSearch('where');">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="mp-nav-right">
                    <a href="{{ route('for-hosts') }}" class="mp-nav-link" data-testid="become-host">Become a host</a>

                    {{-- Mobile-only inline search chip, sits just
                         before the language button. Tap opens the
                         full Where/When/Who form as a bottom sheet
                         (visible via .mp-search-shell.is-mobile-sheet).
                         Above 640px the CSS hides this element. --}}
                    <button type="button"
                            class="mp-mobile-search-chip"
                            data-testid="mobile-search-chip"
                            @click="mobileSearch = true">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                        <span>Search…</span>
                    </button>

                    <button type="button" class="mp-nav-icon-btn" aria-label="Language">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 1.5 2 11 0 12M8 2c-2 1.5-2 11 0 12"/></svg>
                    </button>
                    <button type="button" class="mp-nav-icon-btn"
                            aria-label="Menu"
                            data-testid="mobile-menu-toggle"
                            @click="mobileMenu = !mobileMenu"
                            :aria-expanded="mobileMenu.toString()">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M2 5h12M2 11h12"/></svg>
                    </button>
                </div>
            </div>

            {{-- ─── Mobile drawer ────────────────────────────────────
                 Slides in from the right on ≤ 640px. Hosts the
                 category tabs + "Become a host" link + language
                 toggle so the top-of-fold nav can stay minimal
                 (brand + icon-only right controls). Backdrop
                 dismisses on tap; ESC also closes via the parent
                 x-data @keydown listener. Body scroll is locked
                 while open. Alpine `tab` state is shared with
                 the desktop .mp-cats via the lifted x-data. --}}
            <div class="mp-mobile-scrim"
                 x-show="mobileMenu"
                 x-cloak
                 x-transition:enter="mp-fade-enter"
                 x-transition:leave="mp-fade-leave"
                 @click="mobileMenu = false"
                 data-testid="mobile-menu-scrim"
                 aria-hidden="true"></div>

            <aside class="mp-mobile-drawer"
                   x-show="mobileMenu"
                   x-cloak
                   x-transition:enter="mp-drawer-enter"
                   x-transition:leave="mp-drawer-leave"
                   data-testid="mobile-menu-drawer"
                   role="dialog" aria-modal="true" aria-label="Menu">
                <div class="mp-mobile-drawer-head">
                    <span class="mp-mobile-drawer-title">Menu</span>
                    <button type="button"
                            class="mp-nav-icon-btn"
                            aria-label="Close menu"
                            data-testid="mobile-menu-close"
                            @click="mobileMenu = false">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>
                    </button>
                </div>

                <div class="mp-mobile-drawer-body">
                    {{-- Category tabs, one big row per tab so they read
                         as menu items on touch. Each writes the same
                         shared `tab` state as the desktop .mp-cats,
                         then closes the drawer. --}}
                    <span class="mp-mobile-drawer-eyebrow">Browse</span>
                    @foreach ([
                        ['all',        'All',        asset('img/nav/all.png')],
                        ['stay',       'Stays',      asset('img/nav/stays.png')],
                        ['workspace',  'Workspaces', asset('img/nav/workspaces.png')],
                        ['rent',       'Rentals',    asset('img/nav/rentals.png')],
                    ] as [$value, $label, $icon])
                        <button type="button"
                                class="mp-mobile-menu-item"
                                data-testid="mobile-cat-{{ $value }}"
                                :class="{ 'is-active': tab === '{{ $value }}' }"
                                @click="tab = '{{ $value }}'; mobileMenu = false">
                            <img src="{{ $icon }}" alt="" class="mp-mobile-menu-icon" loading="lazy" decoding="async">
                            <span>{{ $label }}</span>
                            <svg viewBox="0 0 12 12" class="mp-mobile-menu-chev" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3l3 3-3 3"/></svg>
                        </button>
                    @endforeach

                    <hr class="mp-mobile-drawer-hr">

                    <span class="mp-mobile-drawer-eyebrow">Account</span>
                    <a href="{{ route('for-hosts') }}"
                       class="mp-mobile-menu-item"
                       data-testid="mobile-become-host">
                        <span class="mp-mobile-menu-icon mp-mobile-menu-icon--glyph" aria-hidden>🏠</span>
                        <span>Become a host</span>
                        <svg viewBox="0 0 12 12" class="mp-mobile-menu-chev" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3l3 3-3 3"/></svg>
                    </a>
                    @if (Route::has('login'))
                        <a href="{{ route('login') }}"
                           class="mp-mobile-menu-item"
                           data-testid="mobile-operator-login">
                            <span class="mp-mobile-menu-icon mp-mobile-menu-icon--glyph" aria-hidden>🔑</span>
                            <span>Operator sign in</span>
                            <svg viewBox="0 0 12 12" class="mp-mobile-menu-chev" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3l3 3-3 3"/></svg>
                        </a>
                    @endif
                </div>

                <div class="mp-mobile-drawer-foot">
                    <span>English (NG) &middot; ₦ NGN</span>
                </div>
            </aside>

            {{-- Row 2: search pill (expanding-fields pattern).
                 Outer .mp-search-shell holds the Alpine state and wraps
                 BOTH the collapsable pill AND the popover anchor as
                 siblings, the popovers CAN'T live inside
                 .mp-search-slot because that has overflow:hidden for
                 the scroll-collapse animation and would clip them. --}}
            {{-- Mobile sheet scrim + close button, only paint when
                 `mobileSearch` is true. The scrim sits above the
                 fixed nav (z:60) and below the sheet (z:80). --}}
            <div class="mp-mobile-sheet-scrim"
                 x-show="mobileSearch"
                 x-cloak
                 x-transition:enter="mp-fade-enter"
                 x-transition:leave="mp-fade-leave"
                 @click="mobileSearch = false"
                 data-testid="mobile-search-scrim"
                 aria-hidden="true"></div>

            {{-- Search shell, DESKTOP: sits inline inside the nav
                 as the multi-field pill. MOBILE: hidden by default;
                 tapping the .mp-mobile-search-chip flips the parent
                 x-data's `mobileSearch` flag, which adds the
                 `is-mobile-sheet` class and paints the shell as a
                 fixed bottom sheet over a scrim. --}}
            <div class="mp-search-shell"
                 :class="{ 'is-mobile-sheet': mobileSearch }"
                 x-data="searchExperience({
                     q: '{{ addslashes(request()->query('q', '')) }}',
                     from: '{{ request()->query('from', '') }}',
                     to: '{{ request()->query('to', '') }}',
                     guests: '{{ request()->query('guests', '') }}',
                 })"
                 @keydown.escape.window="close()"
                 @click.outside="close()"
                 @focus-search.window="focusField($event.detail && $event.detail.field)"
                 @mp-close-search.window="close()">
            <div class="mp-search-wrap">
                <div class="mp-search-slot">
                    <form action="{{ route('search') }}" method="get" data-testid="search-pill"
                          class="mp-search"
                          x-ref="pill"
                          :class="{ 'is-active': activeField !== null }"
                          @resize.window.debounce.100="reflowHilight()">
                        <input type="hidden" name="type" value="stay">
                        <input type="hidden" name="q" :value="destination">
                        <input type="hidden" name="from" :value="fromDate ?? ''">
                        <input type="hidden" name="to" :value="toDate ?? ''">
                        <input type="hidden" name="guests" :value="totalGuests || ''">
                        <input type="hidden" name="flex" :value="flexibility === 'exact' ? '' : flexibility">

                        {{-- Sliding white "card" that follows the active segment.
                             Show/hide via CSS on .mp-search.is-active so there's
                             no flash before Alpine hydrates. Position + width
                             driven by Alpine measuring the active segment's
                             bounding rect. --}}
                        <div class="mp-search-hilight"
                             :style="`left: ${hilight.left}px; width: ${hilight.width}px;`"></div>

                        {{-- Where --}}
                        <button type="button" class="mp-seg"
                                x-ref="segWhere"
                                :class="{ 'has-value': !!destination, 'is-active-seg': activeField === 'where' }"
                                @click.stop="open('where', $event.currentTarget)" data-testid="seg-where">
                            <span class="mp-seg-label">Where</span>
                            <span class="mp-seg-value" x-text="destination || 'Search destinations'"></span>
                        </button>
                        <div class="mp-seg-divider" x-show="activeField !== 'where' && activeField !== 'when'"></div>

                        {{-- When --}}
                        <button type="button" class="mp-seg"
                                x-ref="segWhen"
                                :class="{ 'has-value': !!fromDate, 'is-active-seg': activeField === 'when' }"
                                @click.stop="open('when', $event.currentTarget)" data-testid="seg-when">
                            <span class="mp-seg-label">When</span>
                            <span class="mp-seg-value" x-text="whenLabel"></span>
                        </button>
                        <div class="mp-seg-divider" x-show="activeField !== 'when' && activeField !== 'who'"></div>

                        {{-- Who, column of the pill that houses BOTH the
                             Who label button AND the Search submit to its
                             right. The column itself is flex:1 so it stays
                             the same width as the Where and When columns
                             (each column reserves room for a right-side
                             action button; only Who has one today). --}}
                        <div class="mp-seg-who"
                             x-ref="segWho"
                             :class="{ 'has-value': totalGuests > 0, 'is-active-seg': activeField === 'who' }">
                            <button type="button" class="mp-seg-who-btn"
                                    @click.stop="open('who', $event.currentTarget.parentElement)" data-testid="seg-who">
                                <span class="mp-seg-label">Who</span>
                                <span class="mp-seg-value" x-text="whoLabel"></span>
                            </button>
                            <button type="submit" class="mp-search-submit"
                                    data-testid="search-submit"
                                    aria-label="Search">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                                <span class="mp-search-submit-label"
                                      :class="{ 'is-visible': activeField !== null }">Search</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Popover anchor, sits directly below the pill. Its
                 children position absolutely off this anchor so they
                 escape the .mp-search-slot's overflow:hidden and stack
                 above the hero (they're still inside the sticky nav's
                 z-index: 40 context, but at z=60 within it). --}}
            <div class="mp-popover-anchor">
                {{-- ─── Popover: Where ─── --}}
                <div class="mp-popover mp-popover-where"
                     x-show="activeField === 'where'"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform translate-y-1 scale-95"
                     x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.stop
                     data-testid="pop-where">
                    <p class="mp-pop-title">Suggested destinations</p>
                    <template x-for="dest in destinations" :key="dest.name">
                        <button type="button" class="mp-dest" @click="pickDestination(dest.name)">
                            <span class="mp-dest-glyph" x-html="dest.glyph"></span>
                            <span>
                                <span class="mp-dest-name" x-text="dest.name"></span>
                                <span class="mp-dest-sub"  x-text="dest.sub"></span>
                            </span>
                        </button>
                    </template>
                </div>

                {{-- ─── Popover: When ─── --}}
                <div class="mp-popover mp-popover-when"
                     x-show="activeField === 'when'"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform translate-y-1 scale-95"
                     x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.stop
                     data-testid="pop-when">
                    <div class="mp-when-toggle">
                        <div class="mp-when-toggle-inner">
                            <button type="button" :class="{ 'is-active': mode === 'dates' }" @click="mode = 'dates'">Dates</button>
                            <button type="button" :class="{ 'is-active': mode === 'flex' }"  @click="mode = 'flex'">Flexible</button>
                        </div>
                    </div>

                    <div class="mp-cal" x-show="mode === 'dates'">
                        <button type="button" class="mp-cal-nav prev" @click="prevMonth()" :disabled="monthCursor === 0" aria-label="Previous month">
                            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 3L4.5 6l3 3"/></svg>
                        </button>
                        <button type="button" class="mp-cal-nav next" @click="nextMonth()" aria-label="Next month">
                            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3l3 3-3 3"/></svg>
                        </button>
                        <template x-for="offset in [0, 1]" :key="offset">
                            <div>
                                <div class="mp-month-title" x-text="monthLabel(offset)"></div>
                                <div class="mp-week">
                                    <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                                </div>
                                <div class="mp-days">
                                    <template x-for="(cell, i) in getMonthDays(offset)" :key="i">
                                        <template x-if="cell === null">
                                            <span class="mp-day is-empty"></span>
                                        </template>
                                    </template>
                                    <template x-for="(cell, i) in getMonthDays(offset)" :key="'d' + i">
                                        <template x-if="cell !== null">
                                            <button type="button"
                                                    class="mp-day"
                                                    :class="{
                                                        'is-past': isPast(cell.iso),
                                                        'is-selected': isSelected(cell.iso),
                                                        'is-in-range': isInRange(cell.iso)
                                                    }"
                                                    :disabled="isPast(cell.iso)"
                                                    @click="pickDate(cell.iso)"
                                                    x-text="cell.day"></button>
                                        </template>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div x-show="mode === 'flex'" style="padding: 24px 8px; color: var(--mp-mute); font-size: 14px;">
                        Pick a length of stay to see everything that matches.
                    </div>

                    <div class="mp-flex">
                        <template x-for="chip in flexChips" :key="chip.key">
                            <button type="button" class="mp-flex-chip"
                                    :class="{ 'is-active': flexibility === chip.key }"
                                    @click="flexibility = chip.key"
                                    x-text="chip.label"></button>
                        </template>
                    </div>
                </div>

                {{-- ─── Popover: Who ─── --}}
                <div class="mp-popover mp-popover-who"
                     x-show="activeField === 'who'"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform translate-y-1 scale-95"
                     x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.stop
                     data-testid="pop-who">
                    <template x-for="row in guestRows" :key="row.key">
                        <div class="mp-guest-row">
                            <div>
                                <div class="mp-guest-title" x-text="row.title"></div>
                                <div class="mp-guest-sub" x-text="row.sub"></div>
                            </div>
                            <div class="mp-guest-step">
                                <button type="button" class="mp-guest-btn"
                                        :disabled="guests[row.key] <= 0"
                                        @click="adjust(row.key, -1)" aria-label="Decrease">&minus;</button>
                                <span class="mp-guest-count" x-text="guests[row.key]"></span>
                                <button type="button" class="mp-guest-btn"
                                        @click="adjust(row.key, 1)" aria-label="Increase">+</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            </div>{{-- /.mp-search-shell --}}
        </div>
    </header>

    {{-- Scrim behind the "force-open" nav overlay. Sits below the
         nav (z-55 vs nav's z-60 when force-open). Clicking it closes
         the overlay: clears force-open on the nav, sends a synthetic
         window click so the search shell's @click.outside fires and
         hides the popover. --}}
    <div class="mp-scrim" data-mp-scrim
         onclick="(function () {
             var nav = document.querySelector('[data-mp-nav]');
             var scrim = document.querySelector('[data-mp-scrim]');
             if (nav && nav.dataset.forceOpen === '1') {
                 delete nav.dataset.forceOpen;
                 nav.classList.toggle('is-scrolled', window.scrollY > 80);
             }
             if (scrim) scrim.classList.remove('is-visible');
             window.dispatchEvent(new CustomEvent('mp-close-search'));
         })();"></div>

    {{-- ═══ EDITORIAL HERO ══════════════════════════════════════ --}}
    {{-- Small signature moment between the marketplace nav and the
         content rows. Doesn't compete with the search pill (which sits
         in the nav and collapses on scroll), this is decorative +
         brand-setting. Enters with a staggered rise on load. --}}
    <section class="mp-container mp-hero" data-testid="landing-hero">
        <div class="mp-hero-copy">
            <span class="mp-script mp-hero-eyebrow">A room, a desk, or a home.</span>
            <h1 class="mp-serif mp-hero-headline">
                for any duration.
            </h1>
            <p class="mp-hero-sub">
                Nigeria&rsquo;s booking rail across four kinds of space:
                hotels &amp; short-lets, workspaces &amp; day passes, monthly desks,
                and annual leases. Each operator verified, each payment
                settled straight to their bank.
            </p>

            {{-- Mobile-only search CTA, sitting above the tile
                 block. Fires a window event the shared x-data
                 listens for → opens the same bottom sheet the nav
                 chip opens. Hidden on tablet + desktop. --}}
            <button type="button"
                    class="mp-hero-search-btn"
                    data-testid="hero-search-btn"
                    onclick="window.dispatchEvent(new CustomEvent('mp-open-search'))">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5l3 3"/></svg>
                <span>Search</span>
            </button>
        </div>
        {{-- Photo sources: loremflickr (free, CC-licensed Flickr photos,
             no key needed). The `lock` param pins the same photo each
             time, deterministic. Drop matching local files at
             public/img/landing/hero-{stay,workspace,rent}.jpg and the
             file_exists check below picks them up automatically. --}}
        @php
            // Local files ship with the app; loremflickr URLs are the
            // fallback when a matching local file is missing.
            $heroPhotos = [
                'stay'      => ['local' => '/img/landing/hero-stay.jpeg',      'remote' => 'https://loremflickr.com/900/1100/hotel,bedroom,interior/all?lock=1201'],
                'workspace' => ['local' => '/img/landing/hero-workspace.webp', 'remote' => 'https://loremflickr.com/700/700/office,desk,coworking/all?lock=1202'],
                'rent'      => ['local' => '/img/landing/hero-rent.jpeg',      'remote' => 'https://loremflickr.com/700/700/apartment,livingroom/all?lock=1203'],
            ];
            $heroSrc = fn (string $key): string => file_exists(public_path(ltrim($heroPhotos[$key]['local'], '/')))
                ? $heroPhotos[$key]['local']
                : $heroPhotos[$key]['remote'];
        @endphp
        <div class="mp-hero-tiles" aria-hidden="true">
            <span class="mp-hero-tile mp-hero-tile-a">
                <img src="{{ $heroSrc('stay') }}" alt="" loading="eager" decoding="async">
                <span class="mp-hero-tag">Stay</span>
            </span>
            <span class="mp-hero-tile mp-hero-tile-b">
                <img src="{{ $heroSrc('workspace') }}" alt="" loading="eager" decoding="async">
                <span class="mp-hero-tag">Workspace</span>
            </span>
            <span class="mp-hero-tile mp-hero-tile-c">
                <img src="{{ $heroSrc('rent') }}" alt="" loading="eager" decoding="async">
                <span class="mp-hero-tag">Rent</span>
            </span>
        </div>
    </section>

    {{-- ═══ CONTENT ═════════════════════════════════════════════ --}}
    <main class="mp-container">

        @php
            $renderRow = function (string $title, string $subtitle, array $cards, string $testid): void {
                echo '<section class="mp-row" data-testid="'.$testid.'">';
                echo '<div class="mp-row-head"><div>';
                echo '<h2 class="mp-row-title"><a href="'.route('search', ['type' => 'stay']).'">'.$title.' <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4l4 4-4 4"/></svg></a></h2>';
                if ($subtitle !== '') {
                    echo '<p class="mp-row-subtitle">'.$subtitle.'</p>';
                }
                echo '</div>';
                echo '<div class="mp-row-arrows">';
                echo '<button type="button" class="mp-row-arrow" aria-label="Scroll left"><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7.5 3L4.5 6l3 3"/></svg></button>';
                echo '<button type="button" class="mp-row-arrow" aria-label="Scroll right"><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 3l3 3-3 3"/></svg></button>';
                echo '</div></div>';
                echo '<div class="mp-scroll">';
                foreach ($cards as $c) {
                    $slug = \Illuminate\Support\Str::slug($c['title']);
                    echo '<a href="'.route('search', ['type' => 'stay', 'q' => $c['city']]).'" class="mp-card" data-testid="card-'.$slug.'">';
                    echo '<div class="mp-card-photo">';
                    if ($c['tag']) { echo '<span class="mp-card-tag">'.$c['tag'].'</span>'; }
                    echo '<span class="mp-card-heart" aria-label="Save"><svg viewBox="0 0 24 24" stroke="currentColor" fill="currentColor"><path d="M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10z"/></svg></span>';
                    echo '<span class="mp-card-photo-stub">'.htmlspecialchars($c['city'] ?? 'Photo').'</span>';
                    echo '</div>';
                    echo '<div class="mp-card-info">';
                    echo '<div class="mp-card-title-row">';
                    echo '<span class="mp-card-title">'.htmlspecialchars($c['title']).'</span>';
                    if ($c['rating'] !== null) {
                        echo '<span class="mp-card-rating"><svg viewBox="0 0 12 12" fill="currentColor"><path d="M6 1l1.5 3.2 3.5.5-2.5 2.4.6 3.4L6 8.9l-3.1 1.6.6-3.4L1 4.7l3.5-.5z"/></svg>'.number_format($c['rating'], 2).'</span>';
                    }
                    echo '</div>';
                    echo '<div class="mp-card-sub">'.htmlspecialchars($c['sub']).' &middot; '.htmlspecialchars($c['city']).'</div>';
                    echo '<div class="mp-card-price"><b>&#8358;'.number_format($c['price']).'</b> '.($c['title'] && str_contains(strtolower($c['sub']), 'lease') ? '/ year' : ($c['title'] && str_contains(strtolower($c['sub']), 'day pass') ? '/ day' : ($c['title'] && str_contains(strtolower($c['sub']), 'monthly') ? '/ month' : '/ night'))).'</div>';
                    echo '</div>';
                    echo '</a>';
                }
                echo '</div>';
                echo '</section>';
            };
        @endphp

        {{-- Rows: one per vertical, plus a mixed "popular" row --}}
        @php $renderRow('Popular stays in Nigeria', '', $rowPopularStays, 'row-popular-stays'); @endphp
        @php $renderRow('Great hotels for your next trip', 'Book verified boutique + business hotels across Lagos, Abuja and beyond.', $rowHotels, 'row-hotels'); @endphp
        @php $renderRow('Workspaces near you', 'Day passes, monthly desks and meeting rooms.', $rowWorkspaces, 'row-workspaces'); @endphp
        @php $renderRow('Rentals with long-term potential', 'Annual leases across Nigeria\'s major cities.', $rowRentals, 'row-rentals'); @endphp

        {{-- Host CTA band --}}
        <section class="mp-host-band" data-testid="host-band">
            <div>
                <h2>Any building. Any duration. One booking rail.</h2>
                <p>List your hotel, workspace, short-let or residential property on Keverna. Get your own branded booking page, direct Paystack payouts, and a dashboard that runs the whole operation. Free 14-day trial, no card required.</p>
            </div>
            <a href="{{ route('for-hosts') }}" class="mp-btn" data-testid="host-cta">
                Become a host
                <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
            </a>
        </section>

        {{-- Footer --}}
        <footer class="mp-footer" data-testid="footer">
            <div class="mp-footer-links">
                <div>
                    <h4>Support</h4>
                    <ul>
                        <li><a href="mailto:hello@keverna.com">Help centre</a></li>
                        <li><a href="mailto:hello@keverna.com">Contact us</a></li>
                        <li><a href="/for-hosts">Trust &amp; safety</a></li>
                    </ul>
                </div>
                <div>
                    <h4>Book</h4>
                    <ul>
                        <li><a href="{{ route('search', ['type' => 'stay']) }}">Find a stay</a></li>
                        <li><a href="{{ route('search', ['type' => 'workspace']) }}">Find a workspace</a></li>
                        <li><a href="{{ route('search', ['type' => 'rent']) }}">Find a rental</a></li>
                    </ul>
                </div>
                <div>
                    <h4>Hosting</h4>
                    <ul>
                        <li><a href="{{ route('for-hosts') }}">List your property</a></li>
                        <li><a href="{{ route('pricing') }}">Pricing</a></li>
                        @if (Route::has('login'))
                            <li><a href="{{ route('login') }}">Operator sign in</a></li>
                        @endif
                    </ul>
                </div>
                <div>
                    <h4>Keverna</h4>
                    <ul>
                        <li><a href="mailto:hello@keverna.com">hello@keverna.com</a></li>
                        <li>Abuja &middot; Lagos &middot; Enugu</li>
                    </ul>
                </div>
            </div>
            <div class="mp-footer-bottom">
                <span>&copy; {{ date('Y') }} FregateLab Ltd. All rights reserved.</span>
                <span>English (NG) &middot; ₦ NGN</span>
            </div>
        </footer>

    </main>

</div>

{{-- Alpine data for the expanding-fields search pill. State:
     activeField (null|where|when|who), destination, fromDate, toDate,
     flexibility, monthCursor, guests {adults, children, infants}.
     Calendar rendering is fully client-side via getMonthDays()
     returning cells for a given offset from monthCursor. --}}
<script>
    // Shared "open the search overlay + focus a specific field"
     // helper, used by every segment button on the scrolled summary
     // pill (Anywhere / Any week / Add guests / search icon). Force-
     // opens the nav in place, shows the scrim, then dispatches the
     // focus-search event once the pill's uncollapse animation has
     // had time to make the target segment measurable.
    window.mpOpenSearch = function (field) {
        var nav = document.querySelector('[data-mp-nav]');
        var scrim = document.querySelector('[data-mp-scrim]');
        if (nav) {
            nav.dataset.forceOpen = '1';
            nav.classList.remove('is-scrolled');
        }
        if (scrim) scrim.classList.add('is-visible');
        setTimeout(function () {
            window.dispatchEvent(new CustomEvent('focus-search', { detail: { field: field || 'where' } }));
        }, 360);
    };

    // Mobile shell relocation retired, the marketplace page now
    // uses the .mp-hero-search / .mp-nav-search pair instead of the
    // full multi-field pill. See CSS: .mp-search-shell hidden
    // everywhere via display: none.

    // Kept OUTSIDE the returned object literal because `this` isn't
    // bound to the new instance during literal construction. Previously
    // calling `this._glyph()` inside the destinations array threw
    // `this._glyph is not a function`, which failed the whole x-data
    // init and left every x-show unprocessed → all popovers visible.
    var _mpGlyph = function (kind) {
        var g = {
            locate: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>',
            city:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V9l6-4v16M9 21V3l6 4v14M15 21V11l6 2v8"/></svg>',
            water:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12c2 -2 4 -2 6 0s4 2 6 0 4 -2 6 0M2 17c2 -2 4 -2 6 0s4 2 6 0 4 -2 6 0M2 7c2 -2 4 -2 6 0s4 2 6 0 4 -2 6 0"/></svg>',
            hills:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 20l6 -8 4 5 3 -4 7 7z"/></svg>',
        };
        return g[kind] || g.city;
    };

    window.searchExperience = function (seed) {
        seed = seed || {};
        var initialGuests = parseInt(seed.guests, 10) || 0;
        return {
            activeField: null,
            destination: seed.q || '',
            fromDate:    seed.from || null,
            toDate:      seed.to || null,
            flexibility: 'exact',
            mode: 'dates',
            monthCursor: 0,
            guests: {
                adults:   initialGuests > 0 ? initialGuests : 0,
                children: 0,
                infants:  0,
            },
            destinations: [
                { name: 'Nearby',                    sub: "Find what's around you",         glyph: _mpGlyph('locate') },
                { name: 'Lagos, Nigeria',            sub: 'The commercial capital',          glyph: _mpGlyph('city') },
                { name: 'Abuja, Nigeria',            sub: 'Corporate + political hub',       glyph: _mpGlyph('city') },
                { name: 'Port Harcourt, Nigeria',    sub: 'Oil, gas and river views',        glyph: _mpGlyph('water') },
                { name: 'Enugu, Nigeria',            sub: 'Cool hills, warm hospitality',    glyph: _mpGlyph('hills') },
                { name: 'Ibadan, Nigeria',           sub: 'Historic and unhurried',          glyph: _mpGlyph('city') },
                { name: 'Calabar, Nigeria',          sub: 'Coastal calm',                    glyph: _mpGlyph('water') },
                { name: 'Accra, Ghana',              sub: 'Popular for regional trips',      glyph: _mpGlyph('city') },
            ],
            flexChips: [
                { key: 'exact',  label: 'Exact dates' },
                { key: '1',      label: '± 1 day' },
                { key: '2',      label: '± 2 days' },
                { key: '3',      label: '± 3 days' },
                { key: '7',      label: '± 7 days' },
                { key: '14',     label: '± 14 days' },
            ],
            guestRows: [
                { key: 'adults',   title: 'Adults',   sub: 'Ages 13 or above' },
                { key: 'children', title: 'Children', sub: 'Ages 2 – 12' },
                { key: 'infants',  title: 'Infants',  sub: 'Under 2' },
            ],

            get totalGuests () { return this.guests.adults + this.guests.children; },
            get hasValues () {
                return !!this.destination || !!this.fromDate || this.totalGuests > 0;
            },
            get whenLabel () {
                if (! this.fromDate) return 'Add dates';
                var from = this._fmtDay(this.fromDate);
                if (! this.toDate) return from;
                return from + ' – ' + this._fmtDay(this.toDate);
            },
            get whoLabel () {
                if (this.totalGuests === 0 && this.guests.infants === 0) return 'Add guests';
                var parts = [];
                if (this.totalGuests > 0) parts.push(this.totalGuests + ' guest' + (this.totalGuests !== 1 ? 's' : ''));
                if (this.guests.infants > 0) parts.push(this.guests.infants + ' infant' + (this.guests.infants !== 1 ? 's' : ''));
                return parts.join(', ');
            },

            // Sliding hilight state, updated on every open() by measuring
            // the clicked segment's rect relative to the pill.
            hilight: { left: 0, width: 0 },
            _activeEl: null,

            open: function (field, el) {
                this.activeField = field;
                if (el) {
                    this._activeEl = el;
                    this._measure(el);
                    // Search-button width grows once activeField flips
                    // (label expands via CSS transition). Segments
                    // flex-shrink to fit → their positions/widths shift.
                    // Re-measure after the transition settles so the
                    // hilight lands on the FINAL layout, not the
                    // pre-transition one.
                    var self = this;
                    setTimeout(function () { self._measure(el); }, 340);
                }
            },
            close: function () {
                this.activeField = null;
                this._activeEl = null;
                // If the nav was force-open (summary-pill click),
                // clear the flag + hide the scrim + let the scroll
                // listener re-evaluate, nav re-collapses if the
                // user is still scrolled past the threshold.
                var nav = document.querySelector('[data-mp-nav]');
                if (nav && nav.dataset.forceOpen === '1') {
                    delete nav.dataset.forceOpen;
                    nav.classList.toggle('is-scrolled', window.scrollY > 80);
                }
                var scrim = document.querySelector('[data-mp-scrim]');
                if (scrim) scrim.classList.remove('is-visible');
            },
            _measure: function (el) {
                var pill = this.$refs.pill;
                if (! pill || ! el) return;
                var s = el.getBoundingClientRect();
                var p = pill.getBoundingClientRect();
                this.hilight = {
                    left:  Math.round(s.left - p.left),
                    width: Math.round(s.width),
                };
            },
            reflowHilight: function () {
                // Re-measure on window resize so the pill stays glued to
                // the active segment when the viewport reflows.
                if (this._activeEl) this._measure(this._activeEl);
            },
            focusField: function (field) {
                // Bridged from an outside component (the scrolled
                // summary pill), look up the matching segment ref
                // and open it. Wrapped in $nextTick so the layout
                // has settled (nav expanded) before measuring.
                var refMap = { where: 'segWhere', when: 'segWhen', who: 'segWho' };
                var refName = refMap[field] || 'segWhere';
                var self = this;
                this.$nextTick(function () {
                    var el = self.$refs[refName];
                    if (el) self.open(field, el);
                });
            },
            prevMonth: function ()  { if (this.monthCursor > 0) this.monthCursor -= 1; },
            nextMonth: function ()  { this.monthCursor += 1; },

            pickDestination: function (name) {
                this.destination = name;
                this.activeField = 'when';
            },

            pickDate: function (iso) {
                if (this.isPast(iso)) return;
                // First click OR resetting after a range → set start.
                // Click before fromDate → shift start back.
                // Click after fromDate with no end → set end.
                // Click after both are set → start over.
                if (! this.fromDate || (this.fromDate && this.toDate)) {
                    this.fromDate = iso;
                    this.toDate = null;
                } else if (iso < this.fromDate) {
                    this.fromDate = iso;
                    this.toDate = null;
                } else if (iso === this.fromDate) {
                    // toggle off
                    this.fromDate = null;
                    this.toDate = null;
                } else {
                    this.toDate = iso;
                }
            },

            adjust: function (key, delta) {
                var next = (this.guests[key] || 0) + delta;
                if (next < 0) return;
                this.guests[key] = next;
            },

            getMonthDays: function (offset) {
                var base = new Date();
                base.setDate(1);
                base.setMonth(base.getMonth() + this.monthCursor + offset);
                var year = base.getFullYear(), month = base.getMonth();
                var startPad = new Date(year, month, 1).getDay();
                var daysInMonth = new Date(year, month + 1, 0).getDate();
                var out = [];
                for (var i = 0; i < startPad; i++) out.push(null);
                for (var d = 1; d <= daysInMonth; d++) {
                    var iso = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                    out.push({ day: d, iso: iso });
                }
                return out;
            },
            monthLabel: function (offset) {
                var base = new Date();
                base.setDate(1);
                base.setMonth(base.getMonth() + this.monthCursor + offset);
                return base.toLocaleDateString('en', { month: 'long', year: 'numeric' });
            },
            isSelected: function (iso) { return iso === this.fromDate || iso === this.toDate; },
            isInRange:  function (iso) {
                if (! this.fromDate || ! this.toDate) return false;
                return iso > this.fromDate && iso < this.toDate;
            },
            isPast: function (iso) {
                var t = new Date(); t.setHours(0, 0, 0, 0);
                var todayIso = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
                return iso < todayIso;
            },

            _fmtDay: function (iso) {
                var d = new Date(iso + 'T00:00:00');
                return d.toLocaleDateString('en', { day: 'numeric', month: 'short' });
            },
        };
    };
</script>

{{-- Reveal-on-scroll for content rows. IntersectionObserver flips
     `.is-in` on each .mp-row when it enters the viewport, so the row
     rises + fades in from the CSS starting state. One-shot per row -
     unobserve after firing to avoid re-triggering when scrolling back
     up. Skipped entirely if prefers-reduced-motion. --}}
<script>
    (function () {
        if (typeof IntersectionObserver === 'undefined') {
            document.querySelectorAll('.mp-row').forEach(function (r) { r.classList.add('is-in'); });
            return;
        }
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.querySelectorAll('.mp-row').forEach(function (r) { r.classList.add('is-in'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    e.target.classList.add('is-in');
                    io.unobserve(e.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
        document.querySelectorAll('.mp-row').forEach(function (r) { io.observe(r); });
    })();
</script>
</body>
</html>
