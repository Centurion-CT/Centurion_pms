{{-- Room-type and single-room detail pages (Centurion "Harbour",
     approved mockups 2 + 3). Shared by booking/category-detail and
     booking/room-detail, which use the same rd-* markup. --}}
<style>
    .rd-page { padding-bottom: 72px; }
    .rd-wrap { max-width: 1280px; margin: 0 auto; padding: 0 20px; }
    @media (min-width: 768px) { .rd-wrap { padding: 0 40px; } }

    .rd-crumbs-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 24px; flex-wrap: wrap; }
    .rd-crumbs { margin: 0; font-size: 14px; color: var(--ink-mute); }
    .rd-crumbs a { color: var(--blue-600); font-weight: 600; text-decoration: none; }
    .rd-crumbs .sep { margin: 0 6px; }

    .rd-header { padding-top: 10px; }
    .rd-title { font-family: var(--display); font-weight: 600; font-size: clamp(32px, 4.4vw, 44px); line-height: 1.08; margin: 0; }
    .rd-eyebrow { font-size: 14px; color: var(--ink-mute); }
    .rd-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .rd-meta > span:not(.rd-meta-dot) { display: inline-flex; align-items: center; height: 32px; padding: 0 12px; border-radius: 8px; background: var(--white); border: 1px solid var(--line); font-size: 14px; color: var(--ink); }
    .rd-meta-dot { display: none; }
    .rd-rating { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px !important; border-radius: 8px !important; background: var(--white) !important; border: 1px solid var(--line); font-size: 14px !important; }
    .rd-rating .star, .rd-widget-rating .star { color: var(--gold); }
    .rd-rating .mute { color: var(--ink-mute); }

    /* Gallery: category page (1-5+ tiles via data-count) */
    .rd-gallery { margin-top: 24px; display: grid; gap: 12px; height: clamp(320px, 38vw, 480px); border-radius: 12px; overflow: hidden; }
    .rd-gallery[data-count="1"], .rd-gallery[data-count="empty"] { grid-template-columns: 1fr; }
    .rd-gallery[data-count="2"] { grid-template-columns: 1fr 1fr; }
    .rd-gallery[data-count="3"] { grid-template-columns: 2fr 1fr; grid-template-rows: 1fr 1fr; }
    .rd-gallery[data-count="3"] .g-tile:nth-child(1) { grid-row: 1 / span 2; }
    .rd-gallery[data-count="4"] { grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; }
    .rd-gallery[data-count="4"] .g-tile:nth-child(1) { grid-row: 1 / span 2; }
    .rd-gallery[data-count="4"] .g-tile:nth-child(4) { grid-column: 2 / span 2; }
    .rd-gallery[data-count="5"], .rd-gallery[data-count="many"] { grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; }
    .rd-gallery[data-count="5"] .g-tile:nth-child(1), .rd-gallery[data-count="many"] .g-tile:nth-child(1) { grid-row: 1 / span 2; }
    .rd-gallery .g-tile { position: relative; padding: 0; border: 0; border-radius: 10px; overflow: hidden; cursor: zoom-in; background: var(--blue-50); }
    .rd-gallery img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 300ms ease; }
    .rd-gallery .g-tile .g-tile-img, .rd-gallery .g-tile .blurred-img { width: 100%; height: 100%; }
    .rd-gallery .g-tile:hover img { transform: scale(1.02); }
    .rd-gallery .g-more { position: absolute; right: 12px; bottom: 12px; display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 12px; border-radius: 8px; background: var(--white); color: var(--ink); font-size: 14px; font-weight: 600; }
    .rd-gallery .g-more svg { width: 14px; height: 14px; }
    .rd-gallery .g-empty { display: grid; place-items: center; border-radius: 10px; background: var(--blue-50); color: var(--ink-mute); font-size: 15px; }
    /* Gallery: single-room page (main + up to 4 thumbs) */
    .rd-gallery:not([data-count]) { grid-template-columns: 2fr 1fr 1fr; grid-template-rows: 1fr 1fr; }
    .rd-gallery:not([data-count]) .g-main { grid-row: 1 / span 2; }
    .rd-gallery:not([data-count]) > div { position: relative; overflow: hidden; border-radius: 10px; background: var(--blue-50); }
    .rd-gallery:not([data-count]) > .g-main:only-child { grid-column: 1 / -1; }
    @media (max-width: 767px) {
        .rd-gallery, .rd-gallery[data-count] , .rd-gallery:not([data-count]) { grid-template-columns: 1fr; grid-template-rows: 1fr; height: 260px; }
        .rd-gallery .g-tile:nth-child(n+2), .rd-gallery:not([data-count]) > div:not(.g-main) { display: none; }
        .rd-gallery .g-tile:nth-child(1) { grid-row: auto !important; }
    }

    /* Lightbox */
    .rd-lightbox { position: fixed; inset: 0; z-index: 80; display: none; align-items: center; justify-content: center; background: rgba(10, 22, 36, 0.92); }
    .rd-lightbox[data-open="true"] { display: flex; }
    .rd-lightbox-stage { position: relative; max-width: min(1100px, 92vw); max-height: 86vh; }
    .rd-lightbox-stage img { max-width: 100%; max-height: 86vh; border-radius: 8px; display: block; }
    .rd-lightbox-close, .rd-lightbox-prev, .rd-lightbox-next { position: absolute; width: 44px; height: 44px; border-radius: 999px; border: 0; background: var(--white); color: var(--ink); display: grid; place-items: center; cursor: pointer; }
    .rd-lightbox-close { top: -56px; right: 0; }
    .rd-lightbox-prev { left: -56px; top: 50%; transform: translateY(-50%); }
    .rd-lightbox-next { right: -56px; top: 50%; transform: translateY(-50%); }
    @media (max-width: 767px) { .rd-lightbox-prev { left: 8px; } .rd-lightbox-next { right: 8px; } .rd-lightbox-close { top: 8px; right: 8px; } }
    .rd-lightbox-counter { position: absolute; bottom: -36px; left: 0; right: 0; text-align: center; color: var(--blue-200); font-size: 14px; }

    /* Body: content + widget */
    .rd-body { display: grid; grid-template-columns: 1fr; gap: 40px; margin-top: 40px; align-items: start; }
    @media (min-width: 1024px) { .rd-body { grid-template-columns: minmax(0, 1fr) 420px; gap: 56px; } }
    .rd-section { padding: 28px 0; border-top: 1px solid var(--line); }
    .rd-body > div > .rd-section:first-child { border-top: 0; padding-top: 0; }
    .rd-section h2 { font-family: var(--display); font-weight: 600; font-size: 24px; margin: 0 0 12px; }
    .rd-prose { color: var(--ink-soft); font-size: 16px; line-height: 1.7; max-width: 66ch; margin: 0; }
    .rd-amenities { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px 32px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
    .rd-amenities li { display: flex; gap: 10px; align-items: flex-start; font-size: 16px; }
    .rd-amenities li::before { content: ""; flex-shrink: 0; width: 18px; height: 18px; margin-top: 3px; background: no-repeat center / contain url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='%23336799' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 10.5l4 4 8-9'/%3E%3C/svg%3E"); }
    .rd-map { margin-top: 14px; border-radius: 12px; overflow: hidden; border: 1px solid var(--line); }
    .rd-gtk-grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
    .rd-gtk-tile--wide { grid-column: 1 / -1; }
    .rd-gtk-label { display: block; font-size: 14px; color: var(--ink-mute); }
    .rd-gtk-value { display: block; font-weight: 600; font-size: 18px; margin-top: 2px; }
    .rd-gtk-tile--wide .rd-gtk-value { font-weight: 500; font-size: 15px; }
    .rd-reviews { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
    .rd-review { margin: 0; background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 18px 20px; }
    .rd-review .stars { color: var(--gold); letter-spacing: 1px; }
    .rd-review .stars .empty { color: #D5DCE4; }
    .rd-review p { margin: 8px 0 0; color: var(--ink-soft); }
    .rd-review-foot { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 14px; }
    .rd-review-foot cite { font-style: normal; font-weight: 600; }
    .rd-verified { font-size: 12px; font-weight: 600; color: var(--emerald); background: #E6F4EC; padding: 2px 8px; border-radius: 6px; }

    /* Booking widget */
    .rd-widget-wrap { scroll-margin-top: 90px; }
    @media (min-width: 1024px) { .rd-widget-wrap { position: sticky; top: 92px; } }
    .rd-widget { background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 20px; box-shadow: 0 18px 40px -24px rgba(16, 38, 60, 0.35); display: grid; gap: 14px; }
    .rd-step-pane { display: grid; gap: 14px; }
    .rd-step-pane[style*="display: none"] { display: none !important; }
    [x-cloak] { display: none !important; }
    .rd-gtk-value::first-letter { text-transform: uppercase; }
    .rd-widget-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
    .rd-widget-price { font-family: var(--display); font-weight: 600; font-size: 28px; }
    .rd-widget-price .per { font-family: var(--body); font-weight: 400; font-size: 15px; color: var(--ink-mute); }
    .rd-step { font-size: 13px; font-weight: 600; color: var(--blue-600); white-space: nowrap; }
    .rd-widget-rating { font-size: 14px; color: var(--ink-mute); }
    .rd-live { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink-mute); }
    .rd-live-dot { width: 7px; height: 7px; border-radius: 999px; background: var(--emerald); }
    .rd-dates, .rd-field-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px; }
    .rd-dates label, .rd-field-row > div, .rd-field { display: block; border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px; background: var(--white); min-width: 0; }
    .rd-field-row { border: 0; padding: 0; }
    .rd-dates label:focus-within, .rd-field-row > div:focus-within, .rd-field:focus-within { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(51, 103, 153, 0.18); }
    .rd-field-label { display: block; font-size: 13px; color: var(--ink-mute); }
    .rd-dates input, .rd-field input, .rd-field textarea, .rd-field-row input { width: 100%; min-width: 0; border: 0; padding: 2px 0 0; font: inherit; font-size: 16px; font-weight: 600; color: var(--ink); background: transparent; outline: none; resize: vertical; }
    .rd-field textarea { font-weight: 400; }
    .rd-field input::placeholder, .rd-field textarea::placeholder { color: #9AA3AD; font-weight: 400; }
    .rd-err { margin: -6px 0 0; font-size: 13px; color: var(--rose); }
    .rd-warn { margin: 0; padding: 10px 12px; border-radius: 8px; background: #FCEDEE; color: #8C2A33; font-size: 14px; }
    .rd-ok { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--emerald); }

    .rd-group-title { font-size: 14px; font-weight: 600; margin: 4px 0 -4px; }
    .rd-packages { display: grid; gap: 8px; }
    .rd-pkg { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; }
    .rd-pkg:has(input:checked) { border-color: var(--blue); background: var(--blue-50); }
    .rd-pkg input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--blue); flex-shrink: 0; }
    .rd-pkg-name { flex: 1; font-size: 15px; }
    .rd-pkg-name small { display: block; color: var(--ink-mute); font-size: 13px; }
    .rd-pkg-price { font-weight: 600; white-space: nowrap; }
    .rd-pkg-qty { width: 64px; height: 34px; border: 1px solid var(--line); border-radius: 6px; text-align: center; font: inherit; }

    .rd-coupon { display: grid; gap: 6px; }
    .rd-coupon > summary { list-style: none; cursor: pointer; color: var(--blue-600); font-weight: 600; font-size: 14px; }
    .rd-coupon > summary::-webkit-details-marker { display: none; }
    .rd-coupon-row { display: flex; gap: 8px; }
    .rd-coupon-row input { flex: 1; min-width: 0; height: 42px; border: 1px solid var(--line); border-radius: 8px; padding: 0 12px; font: inherit; }
    .rd-coupon-row button { height: 42px; padding: 0 16px; border: 1px solid var(--line); border-radius: 8px; background: var(--white); font: inherit; font-weight: 600; cursor: pointer; }
    .rd-coupon-applied { border: 1px solid #BFE3CD; background: #EEF8F2; border-radius: 8px; padding: 10px 12px; font-size: 14px; }
    .rd-coupon-applied .row { display: flex; justify-content: space-between; align-items: center; }
    .rd-coupon-applied .badge { font-size: 12px; font-weight: 600; color: var(--emerald); }
    .rd-coupon-applied button { border: 0; background: none; font-size: 18px; cursor: pointer; color: var(--ink-mute); }
    .rd-coupon-applied .name { font-weight: 600; margin-top: 2px; }
    .rd-coupon-applied .code { margin-left: 6px; font-size: 12px; padding: 1px 6px; border-radius: 4px; background: var(--white); }
    .rd-coupon-applied .off { color: var(--emerald); margin-top: 2px; }
    .rd-coupon-applied .summary { color: var(--ink-mute); margin-left: 4px; }

    .rd-summary { border-top: 1px solid var(--line); padding-top: 12px; display: grid; gap: 8px; font-size: 15px; }
    .rd-summary .row { display: flex; justify-content: space-between; gap: 12px; }
    .rd-summary .mute { color: var(--ink-soft); }
    .rd-summary .discount { color: var(--emerald); }
    .rd-summary .row.total { border-top: 1px solid var(--line); padding-top: 10px; font-weight: 600; font-size: 17px; }
    .rd-summary .deposit-note { margin: 0; font-size: 13px; color: var(--ink-mute); }

    .rd-pay { display: grid; gap: 8px; }
    .rd-pay > strong { font-size: 14px; }
    .rd-pay label { display: flex; align-items: flex-start; gap: 12px; padding: 12px; border: 1px solid var(--line); border-radius: 8px; cursor: pointer; font-size: 15px; }
    .rd-pay label:has(input:checked) { border-color: var(--blue); background: var(--blue-50); }
    .rd-pay input[type="radio"] { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--blue); flex-shrink: 0; }
    .rd-pay-text { flex: 1; }
    .rd-pay-text b { display: block; font-weight: 600; }
    .rd-pay-text span { color: var(--ink-soft); font-size: 14px; }
    .rd-pay-amount { font-weight: 600; white-space: nowrap; }

    .rd-book-btn, .rd-next-btn { width: 100%; height: 50px; border: 0; border-radius: 8px; background: var(--blue); color: var(--white); font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .rd-book-btn:hover, .rd-next-btn:hover { background: var(--blue-600); }
    .rd-book-btn[disabled], .rd-next-btn[disabled] { background: #A9B7C6; cursor: not-allowed; }
    .rd-book-btn .dot { display: none; }
    .rd-trust { margin: 0; text-align: center; font-size: 13px; color: var(--ink-mute); }
    .rd-back { justify-self: start; border: 0; background: none; padding: 0; font: inherit; font-size: 14px; font-weight: 600; color: var(--blue-600); cursor: pointer; }
    .rd-stay-card { display: grid; gap: 2px; padding: 12px; border-radius: 8px; background: var(--mist); font-size: 14px; color: var(--ink-soft); }
    .rd-stay-card b { color: var(--ink); font-size: 15px; }

    /* Sticky mobile CTA */
    .rd-mobile-cta { display: none; }
    @media (max-width: 1023px) {
        .rd-mobile-cta { position: fixed; left: 0; right: 0; bottom: 0; z-index: 40; display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--white); border-top: 1px solid var(--line); text-decoration: none; }
        .rd-mobile-cta-price { font-family: var(--display); font-weight: 600; font-size: 20px; color: var(--ink); }
        .rd-mobile-cta-price .per { display: block; font-family: var(--body); font-weight: 400; font-size: 13px; color: var(--ink-mute); }
        .rd-mobile-cta-btn { margin-left: auto; height: 46px; padding: 0 22px; border-radius: 8px; background: var(--blue); color: var(--white); font-weight: 600; display: inline-flex; align-items: center; }
        .rd-mobile-cta-btn .dot { display: none; }
        .rd-page { padding-bottom: 96px; }
    }

    /* Related */
    .rd-related { margin-top: 56px; }
    .rd-related-head h2 { font-family: var(--display); font-weight: 600; font-size: 28px; margin: 0; }
    .rd-related-head .rd-eyebrow { display: none; }
    .rd-related-track { margin-top: 20px; display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
    .rd-related-card { display: block; background: var(--white); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; text-decoration: none; }
    .rd-related-photo { aspect-ratio: 16 / 10; background: var(--blue-50); }
    .rd-related-photo img { width: 100%; height: 100%; object-fit: cover; }
    .rd-related-body { padding: 16px 18px; display: grid; gap: 4px; }
    .rd-related-name { font-family: var(--display); font-weight: 600; font-size: 20px; }
    .rd-related-meta { font-size: 14px; color: var(--ink-soft); }
    .rd-related-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 6px; }
    .rd-related-price { font-family: var(--display); font-weight: 600; font-size: 19px; }
    .rd-related-price .from, .rd-related-price .per { font-family: var(--body); font-weight: 400; font-size: 13px; color: var(--ink-mute); }
    .rd-related-cta { font-weight: 600; color: var(--blue-600); font-size: 14px; }
</style>
