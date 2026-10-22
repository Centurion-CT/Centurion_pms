{{-- Centurion "Harbour" public theme: fonts, tokens and the shared
     components (top bar, cards, fields, panels, footer, modal) used by
     the landing page and every page on layouts/tenant-public. --}}
@php $cxProperty = $property ?? \App\Models\Tenant::current()?->property; @endphp
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600,700|hanken-grotesk:400,500,600,700&display=swap">
<style>
        :root {
            --primary: {{ $cxProperty?->primary_color ?? '#336799' }};
            --navy: #16324B; --navy-deep: #0F2438; --navy-line: #2A4A68;
            --blue: #336799; --blue-600: #285480; --blue-50: #EAF1F8; --blue-200: #A9C5E1; --blue-300: #8DB6DE;
            --mist: #EEF3F7; --white: #FFFFFF; --gold: #E3A83A;
            --ink: #1A2430; --ink-soft: #4A5563; --ink-mute: #6B7684;
            --line: rgba(26, 36, 48, 0.10);
            --emerald: #1F7A50; --rose: #C2414B;
            --display: 'Bricolage Grotesque', system-ui, sans-serif;
            --body: 'Hanken Grotesk', system-ui, sans-serif;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
        body { margin: 0; background: var(--mist); color: var(--ink); font-family: var(--body); font-size: 16px; line-height: 1.5; -webkit-font-smoothing: antialiased; font-feature-settings: "tnum" 1; }
        a { color: inherit; }
        img { max-width: 100%; display: block; }
        .cx-wrap { max-width: 1280px; margin: 0 auto; padding: 0 20px; }
        @media (min-width: 768px) { .cx-wrap { padding: 0 40px; } }
        .cx-display { font-family: var(--display); font-weight: 600; letter-spacing: -0.01em; }
        :focus-visible { outline: 3px solid var(--blue-300); outline-offset: 2px; }

        /* Top bar (sticky, navy) */
        .cx-top { position: sticky; top: 0; z-index: 40; background: var(--navy); color: var(--white); }
        .cx-top-inner { height: 72px; display: flex; align-items: center; gap: 24px; }
        .cx-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; min-width: 0; }
        .cx-brand-mark { width: 40px; height: 40px; border-radius: 10px; background: var(--white); display: grid; place-items: center; flex-shrink: 0; overflow: hidden; }
        .cx-brand-mark img { width: 28px; height: 28px; object-fit: contain; }
        .cx-brand-name { font-family: var(--display); font-weight: 600; font-size: 19px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cx-nav { margin-left: auto; display: none; align-items: center; gap: 26px; font-size: 15px; color: var(--blue-200); }
        .cx-nav a { text-decoration: none; }
        .cx-nav a:hover { color: var(--white); }
        @media (min-width: 900px) { .cx-nav { display: flex; } }
        .cx-top-cta { margin-left: auto; height: 40px; padding: 0 16px; border-radius: 8px; background: var(--white); color: var(--navy); font-weight: 600; font-size: 15px; display: inline-flex; align-items: center; text-decoration: none; white-space: nowrap; }
        @media (min-width: 900px) { .cx-top-cta { margin-left: 0; } }

        /* Docked search pill (appears once the hero form scrolls away) */
        .cx-docked { display: none; align-items: center; gap: 10px; height: 40px; padding: 0 6px 0 14px; border-radius: 8px; border: 1px solid var(--navy-line); background: var(--navy-deep); color: var(--blue-200); font: inherit; font-size: 14px; cursor: pointer; }
        .cx-docked .cx-docked-btn { width: 30px; height: 30px; border-radius: 6px; background: var(--blue); color: var(--white); display: grid; place-items: center; }
        .is-search-docked .cx-docked { display: inline-flex; }
        .cx-docked-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        @media (max-width: 479px) { .cx-docked-guests { display: none; } }
        @media (max-width: 899px) { .is-search-docked .cx-docked { margin-left: auto; } .is-search-docked .cx-top-cta { display: none; } }

        /* Hero */
        .cx-hero { background: var(--navy); color: var(--white); overflow: hidden; }
        .cx-hero-grid { display: grid; grid-template-columns: 1fr; gap: 40px; padding-top: 40px; }
        @media (min-width: 1024px) { .cx-hero-grid { grid-template-columns: minmax(0, 1fr) 520px; gap: 56px; align-items: end; padding-top: 48px; } }
        .cx-hero-copy { padding-bottom: 56px; }
        @media (min-width: 1024px) { .cx-hero-copy { padding-bottom: 80px; } }
        .cx-title { font-family: var(--display); font-weight: 600; font-size: clamp(40px, 6vw, 68px); line-height: 1.0; letter-spacing: -0.015em; margin: 0; max-width: 14ch; }
        .cx-tagline { font-size: 18px; color: var(--blue-200); margin: 18px 0 0; max-width: 34em; }
        .cx-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
        .cx-chip { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 12px; border-radius: 8px; background: rgba(255, 255, 255, 0.08); color: var(--white); font-size: 14px; }
        .cx-chip .star { color: var(--gold); }

        .cx-book { margin-top: 32px; background: var(--white); color: var(--ink); border-radius: 12px; padding: 16px; max-width: 580px; }
        .cx-book-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
        .cx-field, .cx-field input, .cx-field select { min-width: 0; }
        .cx-field { display: block; border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; background: var(--white); cursor: pointer; }
        .cx-field:focus-within { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(51, 103, 153, 0.18); }
        .cx-field-label { display: block; font-size: 13px; color: var(--ink-mute); }
        .cx-field input, .cx-field select { width: 100%; border: 0; padding: 2px 0 0; font: inherit; font-size: 16px; font-weight: 600; color: var(--ink); background: transparent; outline: none; }
        .cx-book-submit { grid-column: 1 / -1; height: 48px; border: 0; border-radius: 8px; background: var(--blue); color: var(--white); font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; }
        .cx-book-submit:hover { background: var(--blue-600); }
        .cx-book-note { margin: 12px 2px 0; font-size: 13px; color: var(--ink-mute); display: flex; gap: 6px; align-items: center; }
        .cx-hero-cta { display: inline-flex; align-items: center; height: 48px; padding: 0 22px; margin-top: 32px; border-radius: 8px; background: var(--white); color: var(--navy); font-weight: 600; text-decoration: none; }

        .cx-windows { display: none; align-items: flex-end; gap: 14px; height: 560px; }
        @media (min-width: 1024px) { .cx-windows { display: flex; } }
        .cx-window { flex: 1; border-radius: 12px 12px 4px 4px; background: var(--navy-line) center / cover no-repeat; }
        .cx-window:nth-child(1) { height: 100%; }
        .cx-window:nth-child(2) { height: 72%; }
        .cx-window:nth-child(3) { height: 46%; }

        /* Sections */
        .cx-section { padding: 72px 0; }
        .cx-h2 { font-family: var(--display); font-weight: 600; font-size: clamp(28px, 3.4vw, 40px); line-height: 1.1; margin: 0; }
        .cx-sub { color: var(--ink-soft); margin: 8px 0 0; }
        .cx-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px 24px; }
        .cx-link { color: var(--blue-600); font-weight: 600; text-decoration: none; }
        .cx-link:hover { text-decoration: underline; text-underline-offset: 4px; }

        /* Cards */
        .cx-grid { margin-top: 36px; display: grid; gap: 22px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
        .cx-card { display: flex; flex-direction: column; background: var(--white); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; text-decoration: none; transition: border-color 150ms ease; }
        .cx-card:hover { border-color: rgba(51, 103, 153, 0.45); }
        .cx-card-photo { position: relative; aspect-ratio: 3 / 2; background: var(--blue-50); }
        .cx-card-photo img { width: 100%; height: 100%; object-fit: cover; }
        .cx-card-body { padding: 20px 22px 22px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .cx-card-top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
        .cx-card-name { font-family: var(--display); font-weight: 600; font-size: 22px; line-height: 1.2; margin: 0; }
        .cx-avail { font-size: 13px; font-weight: 600; color: var(--emerald); white-space: nowrap; }
        .cx-avail.is-low { color: var(--rose); }
        .cx-avail.is-full { color: var(--ink-mute); }
        .cx-card-meta { font-size: 15px; color: var(--ink-soft); }
        .cx-card-foot { margin-top: auto; padding-top: 14px; display: flex; align-items: center; gap: 12px; }
        .cx-price { font-family: var(--display); font-weight: 600; font-size: 24px; }
        .cx-price small { font-family: var(--body); font-weight: 400; font-size: 14px; color: var(--ink-mute); }
        .cx-card-cta { margin-left: auto; height: 42px; padding: 0 18px; border-radius: 8px; background: var(--navy); color: var(--white); font-weight: 600; font-size: 15px; display: inline-flex; align-items: center; }

        .cx-empty { margin-top: 32px; background: var(--white); border: 1px dashed rgba(51, 103, 153, 0.35); border-radius: 12px; padding: 40px; }
        .cx-empty h2 { font-family: var(--display); font-weight: 600; font-size: 24px; margin: 0; }
        .cx-empty p { margin: 8px 0 0; color: var(--ink-soft); }

        /* Bands */
        .cx-panel { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 32px; }
        @media (min-width: 768px) { .cx-panel { padding: 40px; } }
        .cx-amenities { margin: 24px 0 0; padding: 0; list-style: none; display: grid; gap: 14px 32px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        .cx-amenities li { display: flex; gap: 10px; align-items: flex-start; font-size: 15px; }
        .cx-amenities svg { flex-shrink: 0; color: var(--blue); margin-top: 2px; }

        .cx-reviews { margin-top: 28px; display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
        .cx-review { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 22px; }
        .cx-review-head { display: flex; gap: 12px; align-items: flex-start; }
        .cx-avatar { width: 40px; height: 40px; border-radius: 999px; object-fit: cover; flex-shrink: 0; background: var(--blue-50); color: var(--blue-600); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; }
        .cx-review-author { font-weight: 600; }
        .cx-review-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 4px; font-size: 12px; color: var(--ink-mute); }
        .cx-review-rating { margin-left: auto; font-weight: 600; white-space: nowrap; }
        .cx-review-rating .star { color: var(--gold); }
        .cx-review p { margin: 12px 0 0; color: var(--ink-soft); }
        .cx-response { margin-top: 14px; padding: 12px 14px; border-radius: 8px; background: var(--blue-50); font-size: 14px; }
        .cx-response-head { display: flex; justify-content: space-between; gap: 8px; font-weight: 600; color: var(--blue-600); }
        .cx-response-head time { font-weight: 400; color: var(--ink-mute); }
        .cx-response p { margin: 6px 0 0; color: var(--ink); }

        .cx-contact { margin-top: 24px; display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        .cx-tile-label { display: block; font-size: 14px; color: var(--ink-mute); }
        .cx-tile-value { display: block; font-weight: 600; font-size: 18px; margin-top: 2px; word-break: break-word; }
        .cx-tile-value a { text-decoration: none; color: var(--blue-600); }
        .cx-about { margin-top: 18px; color: var(--ink-soft); max-width: 70ch; }
        .cx-about a { color: var(--blue-600); }

        .cx-gtk { background: var(--white); border-block: 1px solid var(--line); }
        .cx-gtk-grid { display: grid; gap: 28px; padding-block: 56px; grid-template-columns: 1fr; }
        @media (min-width: 900px) { .cx-gtk-grid { grid-template-columns: 260px repeat(3, minmax(0, 1fr)); align-items: start; } }
        .cx-gtk-value { display: block; font-weight: 600; font-size: 20px; margin-top: 4px; }
        .cx-gtk-wide .cx-gtk-value { font-size: 16px; font-weight: 500; line-height: 1.5; }

        /* Footer */
        .cx-foot { background: var(--navy-deep); color: var(--white); }
        .cx-foot-top { display: flex; flex-wrap: wrap; align-items: center; gap: 20px 40px; padding-block: 48px; }
        .cx-foot-where { color: var(--blue-200); font-size: 15px; }
        .cx-foot-cta { margin-left: auto; height: 44px; padding: 0 18px; border-radius: 8px; background: var(--white); color: var(--navy); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .cx-foot-bottom { border-top: 1px solid rgba(255, 255, 255, 0.10); }
        .cx-foot-bottom .cx-wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px 24px; padding-block: 18px; font-size: 13px; color: var(--blue-200); }
        .cx-foot-bottom nav { display: flex; flex-wrap: wrap; gap: 18px; }
        .cx-foot-bottom a { text-decoration: none; }
        .cx-foot-bottom a:hover { color: var(--white); }

        /* WhatsApp button */
        .cx-wa { position: fixed; right: 20px; bottom: 20px; z-index: 50; display: inline-flex; align-items: center; gap: 8px; height: 48px; padding: 0 18px; border-radius: 999px; background: var(--navy); color: var(--white); font-weight: 600; text-decoration: none; box-shadow: 0 12px 28px -12px rgba(15, 36, 56, 0.6); }
        .cx-wa:hover { background: var(--blue-600); }

        /* Search modal */
        .cx-modal { position: fixed; inset: 0; z-index: 60; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 36, 56, 0.55); }
        .cx-modal.is-open { display: flex; }
        .cx-modal-panel { width: 100%; max-width: 560px; background: var(--white); border-radius: 12px; padding: 20px; box-shadow: 0 24px 60px -20px rgba(15, 36, 56, 0.6); }
        .cx-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .cx-modal-head h2 { font-family: var(--display); font-weight: 600; font-size: 22px; margin: 0; }
        .cx-modal-close { width: 36px; height: 36px; border: 0; border-radius: 8px; background: var(--mist); font-size: 22px; line-height: 1; cursor: pointer; color: var(--ink); }

        /* Buttons shared by guest pages */
        .cx-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 46px; padding: 0 20px; border-radius: 8px; border: 1px solid transparent; background: var(--blue); color: var(--white); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .cx-btn:hover { background: var(--blue-600); }
        .cx-btn[disabled] { opacity: 0.6; cursor: progress; }
        .cx-btn--ghost { background: var(--white); color: var(--ink); border-color: var(--line); }
        .cx-btn--ghost:hover { background: var(--mist); }
        .cx-btn--danger { background: var(--rose); }
        .cx-btn--danger:hover { background: #A5333C; }
        .cx-btn--light { background: var(--white); color: var(--navy); }
        .cx-btn--block { width: 100%; }
</style>
