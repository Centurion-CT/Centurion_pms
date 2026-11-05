{{-- The booking site's styles (docs/MULTITENANCY.md, step 5), from the guest
     booking redesign. The business's colour arrives as --panel and --action
     on <html>; everything else follows from them. --}}
<style>
:root {
    --panel: #16324B; --action: #336799; --on-panel: #A9C5E1;
    --panel-deep: color-mix(in srgb, var(--panel) 72%, #000);
    --action-hover: color-mix(in srgb, var(--action) 82%, #000);
    --tint: color-mix(in srgb, var(--action) 10%, #FFF);
    --c-ink: #14202C; --c-body: #364656; --c-mute: #5E6F80; --c-line: #8796A5; --c-rule: #D9E2EA; --c-band: #F4F7FA;
    --c-free: #0F6B4F; --c-last: #8F5300; --c-danger: #A12A40;
    --f-display: 'Bricolage Grotesque', system-ui, sans-serif;
    --f-body: 'Hanken Grotesk', system-ui, sans-serif;
}
.site, .site *, .site *::before, .site *::after { box-sizing: border-box; }
body.site { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: #FFF; color: var(--c-body); font: 400 16px/1.5 var(--f-body); font-feature-settings: "tnum" 1; -webkit-font-smoothing: antialiased; }
:where(.site) img { display: block; max-width: 100%; }
:where(.site) a { color: var(--action); }
:where(.site) a:hover { color: var(--action-hover); }
.site :focus-visible { outline: 3px solid color-mix(in srgb, var(--action) 45%, #FFF); outline-offset: 2px; }
:where(.site) h1, :where(.site) h2, :where(.site) h3 { margin: 0; color: var(--c-ink); }
:where(.site) main { flex: 1 0 auto; }
.container { width: 100%; max-width: 1240px; margin: 0 auto; padding: 0 24px; }
.container--narrow { max-width: 1120px; }
.container--wide { max-width: 1360px; }
.display { font: 600 clamp(40px, 5vw, 68px)/1.04 var(--f-display); letter-spacing: -.02em; }
.title { font: 600 clamp(32px, 4vw, 44px)/1.1 var(--f-display); letter-spacing: -.02em; }
.heading { font: 600 clamp(28px, 3vw, 34px)/1.15 var(--f-display); letter-spacing: -.015em; }
.subhead { font-size: 22px; font-weight: 600; line-height: 1.3; }
.lead { margin: 0; font-size: 18px; }
.meta { color: var(--c-mute); font-size: 15px; }
p.meta { margin: 0; }
.hide-sm { display: inline; } .show-sm { display: none; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 20px; border: 0; border-radius: 8px; font: 600 16px/1 var(--f-body); text-decoration: none; white-space: nowrap; cursor: pointer; }
.btn-lg { height: 52px; padding: 0 24px; font-size: 18px; }
.btn-sm { padding: 0 16px; font-size: 15px; }
.btn-block { display: flex; width: 100%; }
.site a.btn-primary, .btn-primary { background: var(--action); color: #FFF; }
.site a.btn-primary:hover, .btn-primary:hover, a:hover > .listing-body .btn-primary { background: var(--action-hover); color: #FFF; }
.btn-primary[disabled] { opacity: .6; cursor: progress; }
.site a.btn-secondary, .btn-secondary { background: #FFF; border: 1px solid var(--c-line); color: var(--c-ink); }
.site a.btn-secondary:hover, .btn-secondary:hover { border-color: var(--c-ink); color: var(--c-ink); }
.site a.btn-inverse, .btn-inverse { background: #FFF; color: var(--c-ink); }
.site a.btn-inverse:hover { background: var(--tint); color: var(--c-ink); }
.btn[aria-disabled="true"] { background: var(--c-band); color: var(--c-mute); cursor: default; }

.site-header { position: relative; z-index: 20; background: #FFF; border-bottom: 1px solid var(--c-rule); }
.site-header .container { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 32px; min-height: 72px; padding-top: 12px; padding-bottom: 12px; }
.site .logo { display: flex; align-items: center; gap: 10px; min-width: 0; color: var(--c-ink); font-size: 18px; font-weight: 600; text-decoration: none; }
.logo img { width: 36px; height: 36px; flex: none; border-radius: 8px; object-fit: contain; }
.site-nav { display: flex; gap: 24px; }
.site .site-nav a { color: var(--c-body); font-weight: 500; text-decoration: none; }
.site .site-nav a:hover { color: var(--c-ink); }
.site-header .end { margin-left: auto; display: flex; align-items: center; gap: 20px; }
.site .tel { display: inline-flex; align-items: center; gap: 8px; color: var(--c-ink); font-weight: 500; text-decoration: none; }
.tel svg { display: none; width: 22px; height: 22px; }
.help { margin: 0; color: var(--c-mute); }
.site .help a { color: var(--c-ink); font-weight: 600; text-decoration: none; }

.change { flex: 0 1 460px; min-width: 0; margin: 0 auto; position: relative; }
.change > summary { display: flex; align-items: center; gap: 12px; min-height: 48px; padding: 0 8px 0 16px; border: 1px solid var(--c-line); border-radius: 8px; color: var(--c-ink); cursor: pointer; list-style: none; }
.change > summary::-webkit-details-marker { display: none; }
.change > summary b { font-weight: 600; white-space: nowrap; }
.change > summary span { color: var(--c-mute); white-space: nowrap; }
.change .change-go { margin-left: auto; padding: 6px 12px; border-radius: 6px; background: var(--tint); color: var(--action-hover); font-size: 15px; font-weight: 600; }
.change-panel { position: absolute; top: calc(100% + 8px); left: 50%; width: min(860px, calc(100vw - 32px)); transform: translateX(-50%); z-index: 30; }

.search { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; background: #FFF; border: 1px solid var(--c-rule); border-radius: 12px; box-shadow: 0 8px 28px rgba(15, 36, 56, .14); }
.seg { position: relative; flex: 1 1 180px; min-width: 0; display: flex; flex-direction: column; justify-content: center; min-height: 68px; padding: 10px 16px; border-radius: 8px; background: var(--c-band); cursor: pointer; }
.seg:focus-within { outline: 3px solid color-mix(in srgb, var(--action) 45%, #FFF); outline-offset: 1px; }
.seg-label { font-size: 14px; font-weight: 500; color: var(--c-mute); }
.seg-value, .seg select, .seg input { font: 600 18px/1.3 var(--f-body); color: var(--c-ink); }
.seg select { width: 100%; margin: 0; padding: 0; border: 0; background: none; cursor: pointer; }
.seg select:focus { outline: 0; }
.seg input[type="date"] { width: 100%; padding: 0; border: 0; background: none; }
.seg-date .seg-value { display: none; }
.js .seg-date .seg-value { display: block; }
.js .seg-date input { position: absolute; inset: 0; height: 100%; opacity: 0; cursor: pointer; }
.seg-date input::-webkit-calendar-picker-indicator { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; padding: 0; cursor: pointer; }
.search > .btn { flex: 1 1 180px; height: auto; min-height: 68px; }
.where-pick summary { display: flex; flex-direction: column; list-style: none; cursor: pointer; }
.where-pick summary::-webkit-details-marker { display: none; }
.popover { position: absolute; top: calc(100% + 8px); left: 0; z-index: 30; width: 280px; max-height: 320px; overflow: auto; padding: 8px; background: #FFF; border: 1px solid var(--c-rule); border-radius: 12px; box-shadow: 0 8px 28px rgba(15, 36, 56, .14); }
.option { display: flex; align-items: flex-start; gap: 10px; padding: 10px; border-radius: 8px; cursor: pointer; }
.option:hover { background: var(--c-band); }
.option input { margin: 4px 0 0; accent-color: var(--action); }
.option b { font-weight: 600; color: var(--c-ink); }

.hero { background: var(--panel); }
.hero .container { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 40px 64px; padding-top: 72px; }
.hero-copy { flex: 1 1 420px; min-width: 0; padding-bottom: 104px; }
.site .hero h1 { color: #FFF; }
.hero .lead { max-width: 460px; margin-top: 16px; font-size: 19px; color: var(--on-panel); }
.hero-photo { flex: 1 1 520px; min-width: 0; height: 460px; background-size: cover; background-position: center; clip-path: polygon(0% 44%, 30% 44%, 30% 100%, 35% 100%, 35% 22%, 65% 22%, 65% 100%, 70% 100%, 70% 0%, 100% 0%, 100% 100%, 0% 100%); }
.hero .bars { flex: 1 1 420px; height: 300px; }
.hero .contact { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
.hero--quiet .hero-copy { padding-bottom: 72px; }
.search-row { position: relative; margin-top: -44px; }
.bars { display: flex; align-items: flex-end; gap: 5%; min-width: 0; }
.bars i { flex: 1 1 0; }
.bars i:nth-child(1) { height: 44%; background: color-mix(in srgb, var(--action) 30%, #FFF); }
.bars i:nth-child(2) { height: 72%; background: color-mix(in srgb, var(--action) 55%, #FFF); }
.bars i:nth-child(3) { height: 100%; background: var(--action); }
.hero .bars i:nth-child(1), .find-panel .bars i:nth-child(1) { background: color-mix(in srgb, var(--panel) 82%, #FFF); }
.hero .bars i:nth-child(2), .find-panel .bars i:nth-child(2) { background: color-mix(in srgb, var(--panel) 62%, #FFF); }
.hero .bars i:nth-child(3), .find-panel .bars i:nth-child(3) { background: color-mix(in srgb, var(--panel) 40%, #FFF); }
.block { padding: 72px 0 0; }
.block:last-of-type { padding-bottom: 80px; }
.site .block-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 24px; margin-bottom: 24px; }
.block-head a { font-weight: 600; }

.listings { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
.site a.listing { display: flex; flex-direction: column; color: var(--c-body); text-decoration: none; }
.listing-photo { width: 100%; height: 260px; object-fit: cover; border-radius: 12px; }
.listing-photo--bars { display: flex; padding: 32px 32px 0; background: var(--tint); }
.listing-photo--bars .bars { flex: 1; }
.listing-body { display: flex; flex-direction: column; gap: 10px; padding-top: 14px; }
.listing-info { display: block; }
.listing-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.listing-name { font-size: 20px; font-weight: 600; color: var(--c-ink); }
.listing-buy { display: flex; flex-direction: column; }
.listing-price { color: var(--c-ink); }
.listing-price b { font-size: 20px; font-weight: 600; }
.listing-price s { font-size: 16px; font-weight: 400; color: var(--c-mute); }
.free { flex: none; font-size: 15px; font-weight: 600; color: var(--c-free); }
.free--last { color: var(--c-last); }
.free--gone { color: var(--c-mute); }
.site a.listing--row { flex-direction: row; border: 1px solid var(--c-rule); border-radius: 12px; overflow: hidden; }
.site a.listing--row:hover { border-color: var(--c-line); }
.listing--row .listing-photo { flex: 0 0 34%; width: 34%; height: 216px; border-radius: 0; }
.listing--row .listing-body { flex: 1 1 auto; min-width: 0; flex-direction: row; justify-content: space-between; gap: 16px 24px; padding: 20px 24px; }
.listing--row .listing-info { flex: 1 1 auto; min-width: 0; }
.listing--row .listing-head { display: block; }
.listing--row .listing-name { display: block; font-size: 22px; }
.listing--row .listing-head .free { display: block; margin-top: 2px; }
.listing--row .listing-buy { flex: none; align-items: flex-end; justify-content: space-between; gap: 12px; text-align: right; }
.listing--row .listing-price b { font-size: 24px; }

.places { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px; }
.place { display: flex; align-items: center; gap: 20px; padding: 16px; border: 1px solid var(--c-rule); border-radius: 12px; }
.place > img, .place > .bars { flex: none; width: 128px; height: 128px; border-radius: 8px; object-fit: cover; }
.place > .bars { padding: 16px 16px 0; background: var(--tint); }
.place h3 { font-size: 20px; font-weight: 600; }
.place p { margin: 2px 0 12px; color: var(--c-mute); }
.place a { font-weight: 600; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px 40px; padding-top: 20px; border-top: 1px solid var(--c-rule); }
.facts h3 { margin-bottom: 4px; font-size: 17px; font-weight: 600; }
.facts p { margin: 0; }
.long { display: flex; flex-wrap: wrap; gap: 32px 64px; padding: 40px; border-radius: 12px; background: var(--panel); color: var(--on-panel); }
.long > div { flex: 1 1 320px; }
.site .long h2 { color: #FFF; margin-bottom: 8px; }
.long .btn { margin-top: 16px; }
.long table { flex: 2 1 420px; border-collapse: collapse; color: #FFF; }
.long th { padding: 0 0 8px; color: var(--on-panel); font-size: 15px; font-weight: 500; text-align: left; }
.long td { padding: 10px 0; border-top: 1px solid color-mix(in srgb, #FFF 20%, var(--panel)); vertical-align: top; }
.long td small { display: block; color: var(--on-panel); font-size: 14px; }
.long .r { text-align: right; }

.results { display: flex; flex-wrap: wrap; gap: 32px; padding-top: 32px; padding-bottom: 56px; }
.results-list { flex: 999 1 620px; min-width: 0; }
.results-list > .meta { margin: 4px 0 20px; }
.results-map { flex: 1 1 400px; min-width: 0; position: sticky; top: 24px; align-self: flex-start; height: min(760px, calc(100vh - 48px)); min-height: 420px; border-radius: 12px; overflow: hidden; background: var(--tint); }
.results-map .map { height: 100%; }
.filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 24px; }
.site a.chip { display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 16px; border: 1px solid var(--c-line); border-radius: 8px; color: var(--c-ink); font-size: 15px; font-weight: 500; text-decoration: none; white-space: nowrap; }
.site a.chip[aria-pressed="true"] { border-color: var(--panel); background: var(--panel); color: #FFF; font-weight: 600; }
.filters .sort { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.site a.chip-map { display: none; }
.rows { display: flex; flex-direction: column; gap: 16px; }
.empty { padding: 32px; border: 1px dashed var(--c-line); border-radius: 12px; }
.empty p { margin: 0; }
.empty .meta { margin-top: 8px; }

.marker-wrap { background: none; border: 0; }
.marker { position: absolute; transform: translate(-50%, -50%); padding: 8px 12px; border: 0; border-radius: 8px; background: #FFF; color: var(--c-ink); font: 600 15px/1 var(--f-body); white-space: nowrap; box-shadow: 0 2px 8px rgba(15, 36, 56, .24); cursor: pointer; }
.marker.is-active { background: var(--panel); color: #FFF; }
.marker.is-dim { opacity: .55; }

.review-link { margin: -16px 0 20px; font-weight: 600; }
.site .review-link a { color: var(--c-ink); text-decoration: none; }
.review-link span, .review-score span { color: #B97A12; }
.review-score { margin: -4px 0 16px; color: var(--c-ink); }
.review-score b { font-size: 20px; font-weight: 600; }
.review-list { display: flex; flex-direction: column; gap: 20px; margin-bottom: 12px; }
.review-item + .review-item { padding-top: 20px; border-top: 1px solid var(--c-rule); }
.review-head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; margin: 0 0 6px; color: var(--c-ink); }
.review-head b { font-weight: 600; }
.review-stars { color: #B97A12; letter-spacing: 1px; }
.review-stars i { color: var(--c-rule); font-style: normal; }
.review-body { margin: 0; white-space: pre-line; overflow-wrap: anywhere; }
.review-reply { margin: 12px 0 0; padding: 12px 14px; border-radius: 8px; background: var(--c-band); overflow-wrap: anywhere; }
.review-reply b { display: block; font-weight: 600; color: var(--c-ink); }

.back { display: inline-flex; align-items: center; min-height: 44px; font-weight: 600; }
.gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px; margin: 8px 0 32px; border-radius: 12px; overflow: hidden; }
.gallery img { width: 100%; height: 400px; object-fit: cover; }
.gallery--bars { height: 220px; padding: 40px 40px 0; background: var(--tint); }
.gallery--bars .bars { height: 100%; }
.apartment { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 40px 56px; padding-bottom: 64px; }
.apartment-main { flex: 999 1 560px; min-width: 0; }
.apartment-main > .meta { margin: 4px 0 24px; font-size: 18px; }
.apartment-main > .lead { max-width: 620px; margin-bottom: 32px; }
.section { padding-top: 24px; margin-bottom: 32px; border-top: 1px solid var(--c-rule); }
.section h2 { margin-bottom: 12px; }
.ticks { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 24px; margin: 0; padding: 0; list-style: none; }
.cal-wrap { max-width: 420px; }
.cal-month { margin: 0 0 8px; font-weight: 600; color: var(--c-ink); }
.cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 2px; margin-bottom: 16px; text-align: center; font-size: 15px; }
.cal .dow { padding: 4px 0; color: var(--c-mute); }
.cal .day { display: flex; align-items: center; justify-content: center; height: 44px; border-radius: 6px; color: var(--c-ink); }
.cal .start, .cal .end { background: var(--panel); color: #FFF; font-weight: 600; }
.cal .range { background: var(--tint); }
.cal .gone { color: var(--c-line); text-decoration: line-through; }
.book-card { flex: 1 1 360px; min-width: 0; position: sticky; top: 24px; padding: 24px; border: 1px solid var(--c-rule); border-radius: 12px; box-shadow: 0 8px 28px rgba(15, 36, 56, .08); }
.book-card > .price { margin: 0 0 16px; color: var(--c-ink); }
.book-card > .price b { font-size: 30px; font-weight: 600; }
.book-card form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
.book-card form .seg { min-height: 60px; padding: 8px 12px; border: 1px solid var(--c-line); background: #FFF; }
.book-card form .seg-value, .book-card form select { font-size: 16px; }
.book-card form .btn { grid-column: 1 / -1; }
.book-card .free { display: block; margin-bottom: 16px; }
.lines { display: grid; grid-template-columns: 1fr auto; row-gap: 6px; margin: 0; }
.lines dt { padding-right: 16px; }
.lines dd { margin: 0; color: var(--c-ink); font-weight: 500; text-align: right; }
.lines .save { color: var(--c-free); }
.lines .total { margin-top: 8px; padding-top: 12px; border-top: 1px solid var(--c-rule); font-size: 20px; font-weight: 600; color: var(--c-ink); }
.book-card > .btn { margin-top: 20px; }
.book-card > .meta { margin-top: 12px; }

.pg-checkout > [wire\:id] { flex: 1 0 auto; display: flex; flex-direction: column; }
.band { flex: 1 0 auto; background: var(--c-band); padding: 24px 0 64px; }
.band h1 { margin: 4px 0 24px; }
.layout { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 24px; }
.form { flex: 999 1 520px; min-width: 0; display: flex; flex-direction: column; gap: 24px; }
.panel { padding: 24px; background: #FFF; border: 1px solid var(--c-rule); border-radius: 12px; }
.panel > h2 { margin-bottom: 16px; }
.panel--dark { background: var(--panel); border: 0; color: var(--on-panel); }
.site .panel--dark h2 { margin-bottom: 4px; color: #FFF; }
.panel--dark p { margin: 0 0 16px; }
.fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.field--full { grid-column: 1 / -1; }
.field label { font-size: 15px; font-weight: 600; color: var(--c-ink); }
.field label span { font-weight: 400; color: var(--c-mute); }
.input { width: 100%; height: 48px; padding: 0 14px; border: 1px solid var(--c-line); border-radius: 8px; background: #FFF; color: var(--c-ink); font: inherit; }
select.input { padding: 0 10px; }
textarea.input { height: 96px; padding: 12px 14px; resize: vertical; }
.input:focus { outline: 0; border-color: var(--action); box-shadow: 0 0 0 1px var(--action); }
.input::placeholder { color: var(--c-mute); }
.hint { margin: 0; font-size: 14px; color: var(--c-mute); }
.error { margin: 0; font-size: 14px; color: var(--c-danger); }
.form-error { margin: 0; padding: 12px 14px; border-radius: 8px; background: #FBE7EA; color: var(--c-danger); font-size: 15px; }
.pay { display: flex; flex-direction: column; gap: 8px; }
.pay-option { display: flex; align-items: center; gap: 12px; min-height: 64px; padding: 10px 14px; border: 1px solid var(--c-line); border-radius: 8px; cursor: pointer; }
.pay-option:has(input:checked) { border: 2px solid var(--action); padding: 9px 13px; background: var(--tint); }
.pay-option input { flex: none; margin: 0; accent-color: var(--action); }
.pay-option > span { flex: 1 1 auto; min-width: 0; }
.pay-option b { display: block; font-weight: 600; color: var(--c-ink); }
.pay-option small { display: block; font-size: 15px; }
.pay-option > .amount { flex: none; font-weight: 600; color: var(--c-ink); }
.summary { flex: 1 1 340px; min-width: 0; position: sticky; top: 24px; background: #FFF; border: 1px solid var(--c-rule); border-radius: 12px; overflow: hidden; }
.summary > img { width: 100%; height: 180px; object-fit: cover; }
.summary-body { padding: 20px 24px 24px; }
.summary h2 { font-size: 22px; font-weight: 600; }
.summary-body > .meta { margin-bottom: 16px; }
.summary .policy { margin-top: 12px; }
.pay-bar { display: flex; flex-direction: column; gap: 12px; margin-top: 20px; }
.pay-now, .pay-check { display: none; }
.pay-bar .meta { font-size: 14px; }

.narrow { max-width: 640px; padding-top: 56px; padding-bottom: 72px; }
.narrow .lead { margin-top: 12px; }
.narrow .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; }

.review { display: flex; flex-direction: column; gap: 20px; margin-top: 28px; }
.review fieldset { margin: 0; padding: 0; border: 0; }
.review legend { padding: 0; margin-bottom: 6px; font-size: 15px; font-weight: 600; color: var(--c-ink); }
.stars { display: flex; gap: 4px; }
.stars button { width: 44px; height: 44px; padding: 0; border: 0; background: none; color: var(--c-rule); font-size: 32px; line-height: 1; cursor: pointer; }
.stars button.is-on { color: #E3A83A; }

.find { flex: 1 0 auto; display: flex; flex-wrap: wrap; }
.find-main { flex: 2 1 440px; min-width: 0; display: flex; flex-direction: column; justify-content: space-between; gap: 40px; padding: 32px clamp(16px, 5vw, 72px); }
.find-body { width: 100%; max-width: 420px; }
.find-body h1 { margin-bottom: 12px; }
.find-body form { display: flex; flex-direction: column; gap: 20px; margin-top: 28px; }
.find-body .after { margin: 20px 0 0; }
.find-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 24px; font-size: 14px; color: var(--c-mute); }
.find-foot nav { display: flex; gap: 20px; }
.site .find-foot a { color: var(--c-mute); }
.find-panel { flex: 3 1 420px; min-width: 0; display: flex; flex-direction: column; gap: 32px; padding: 40px clamp(16px, 5vw, 72px) 0; background: var(--panel); }
.find-panel p { margin: 0; color: var(--on-panel); font-size: 15px; }
.find-panel .hero-photo { flex: 1 1 360px; min-height: 320px; height: auto; }
.find-panel .bars { flex: 1 1 360px; min-height: 320px; }

.site-footer { background: var(--panel-deep); color: var(--on-panel); }
.site-footer .container { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px 48px; padding-top: 32px; padding-bottom: 32px; }
.site-footer p { margin: 0; }
.site-footer .foot-name { color: #FFF; font-size: 18px; font-weight: 600; }
.site .site-footer a { color: var(--on-panel); }
.site .site-footer a:hover { color: #FFF; }
.site-footer nav { display: flex; flex-wrap: wrap; gap: 8px 20px; font-size: 15px; }

@media (max-width: 720px) {
    .hide-sm { display: none; } .show-sm { display: inline; }
    .container { padding: 0 16px; }
    .site-header .container { min-height: 64px; gap: 8px 12px; padding-top: 10px; padding-bottom: 10px; }
    .site-nav { display: none; }
    .site-header .end { gap: 4px; }
    .tel { width: 44px; height: 44px; justify-content: center; }
    .tel span { display: none; }
    .tel svg { display: block; }
    .change { order: 3; flex: 1 1 100%; }
    .change-panel { position: static; width: auto; transform: none; margin-top: 8px; }
    .hero .container { display: block; padding-top: 32px; padding-bottom: 72px; }
    .hero-copy { padding-bottom: 0; }
    .hero--quiet .container { padding-bottom: 40px; }
    .hero-photo, .hero .bars { height: 200px; margin-top: 24px; }
    .search { margin: 0; }
    .search-row { margin-top: -36px; }
    .seg { flex-basis: calc(50% - 4px); min-height: 60px; padding: 8px 12px; }
    .seg-value, .seg select, .seg input { font-size: 16px; }
    .seg:has(select), .where-pick, .search > .btn { flex-basis: 100%; }
    .search > .btn { min-height: 52px; }
    .block { padding-top: 40px; }
    .block:last-of-type { padding-bottom: 48px; }
    .listing-photo { height: 220px; }
    .long { padding: 24px; }
    .places { grid-template-columns: minmax(0, 1fr); }
    .place > img, .place > .bars { width: 96px; height: 96px; }
    .site a.listing--row { gap: 12px; padding-bottom: 16px; border: 0; border-bottom: 1px solid var(--c-rule); border-radius: 0; overflow: visible; }
    .listing--row .listing-photo { flex: 0 0 112px; width: 112px; height: 136px; min-height: 0; border-radius: 8px; }
    .listing--row .listing-photo--bars { padding: 12px 12px 0; }
    .listing--row .listing-body { display: block; padding: 0; }
    .listing--row .listing-name { font-size: 18px; }
    .listing--row .listing-buy { display: block; margin-top: 4px; text-align: left; }
    .listing--row .listing-buy .meta { display: block; }
    .listing--row .listing-price b { font-size: 18px; }
    .listing--row .btn { display: none; }
    .results { display: block; padding-top: 20px; }
    .filters { flex-wrap: nowrap; overflow-x: auto; margin: 0 -16px 16px; padding: 2px 16px; }
    .filters .sort { margin-left: 0; }
    .site a.chip-map { display: inline-flex; }
    .results-map { position: static; height: 360px; margin-top: 24px; scroll-margin-top: 16px; }
    .gallery { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; margin: 8px -16px 24px; border-radius: 0; }
    .gallery img { flex: 0 0 88%; height: 260px; scroll-snap-align: start; }
    .gallery img:only-child { flex-basis: 100%; }
    .gallery--bars { display: block; height: 200px; margin: 8px 0 24px; padding: 24px 24px 0; border-radius: 12px; }
    .book-card, .summary { position: static; }
    .layout { flex-direction: column; align-items: stretch; }
    .summary { order: -1; flex: none; }
    .form { flex: none; }
    .summary > img { height: 140px; }
    .band { padding: 8px 0 124px; }
    .panel { padding: 16px; }
    .pay-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 25; gap: 8px; margin: 0; padding: 12px 16px; background: #FFF; border-top: 1px solid var(--c-rule); }
    .pay-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .pay-now { display: block; }
    .pay-check { display: block; margin: 0; font-size: 14px; color: var(--c-danger); }
    .pay-now span { display: block; font-size: 14px; color: var(--c-mute); }
    .pay-now b { font-size: 20px; font-weight: 600; color: var(--c-ink); }
    .pay-bar .btn-block { display: inline-flex; width: auto; }
    .pay-bar .meta { display: none; }
    .narrow { padding-top: 32px; padding-bottom: 48px; }
    .find { display: block; }
    .find-panel { display: none; }
    .find-main { padding-top: 20px; }
}
</style>
