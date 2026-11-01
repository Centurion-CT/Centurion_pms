{{-- The booking site's styles (docs/MULTITENANCY.md, step 5). Ported from the approved
     Map Desk design; pages add their class (pg-home, pg-results, pg-checkout). --}}
<style>
/* Map Desk design system: design/website/c-map/system.css, unchanged. */
/*
 * Centurion · Map Desk design system
 * One stylesheet shared by every Option C page. Tokens first, then
 * components. Pages add layout only; they never set colours, sizes or
 * radii of their own. See DESIGN.md for the reasoning.
 */

/* ------------------------------------------------------------------ */
/* Tokens                                                              */
/* ------------------------------------------------------------------ */
:root {
  /* Neutrals: one cool scale taken from the brand navy */
  --ink-900: #0E2238;   /* headings, primary text */
  --ink-700: #33475C;   /* body text */
  --ink-500: #5F7387;   /* secondary text (4.9:1 on white) */
  --ink-300: #A7B5C2;   /* disabled text, strong dividers */
  --ink-200: #D6DFE7;   /* borders */
  --ink-100: #EAF0F5;   /* quiet fills: inputs, chips */
  --ink-50:  #F5F8FA;   /* page band */
  --white:   #FFFFFF;

  /* Brand: the logo blue, used for actions and selection only */
  --brand-700: #24507C;
  --brand-600: #336799;
  --brand-100: #DCE8F4;
  --brand-50:  #EEF4FA;

  /* Availability: the only other colours in the system */
  --free-700: #0F6B4F;
  --free-500: #1F9A72;
  --free-50:  #E2F4EC;
  --last-700: #8F5300;
  --last-500: #E09B2D;
  --last-50:  #FFF1D9;
  --gone:     #D6DFE7;

  /* Type: Instrument Sans throughout, a 1.2 scale from 16px */
  --font: 'Instrument Sans', system-ui, -apple-system, sans-serif;
  --t-xs:  12px; --lh-xs: 16px;
  --t-sm:  14px; --lh-sm: 20px;
  --t-md:  16px; --lh-md: 24px;
  --t-lg:  19px; --lh-lg: 28px;
  --t-xl:  23px; --lh-xl: 30px;
  --t-2xl: 28px; --lh-2xl: 34px;
  --t-3xl: 40px; --lh-3xl: 44px;
  --t-4xl: 56px; --lh-4xl: 58px;

  /* Space: 4px base */
  --s-1: 4px; --s-2: 8px; --s-3: 12px; --s-4: 16px; --s-5: 20px; --s-6: 24px;
  --s-8: 32px; --s-10: 40px; --s-12: 48px; --s-16: 64px; --s-24: 96px;

  /* Radius: grows with the size of the thing */
  --r-sm: 6px;    /* tags, calendar days */
  --r-md: 10px;   /* buttons, fields, chips */
  --r-lg: 16px;   /* cards, photos */
  --r-xl: 24px;   /* the map, page panels */
  --r-pill: 999px;/* map markers only */

  /* Elevation: flat by default, shadow only on things that float */
  --e-float: 0 1px 2px rgba(14,34,56,.08), 0 12px 32px -8px rgba(14,34,56,.22);
  --e-marker: 0 1px 2px rgba(14,34,56,.2), 0 4px 10px -2px rgba(14,34,56,.25);

  --container: 1280px;
  --gutter: 40px;
  --focus: 0 0 0 3px var(--white), 0 0 0 5px var(--brand-600);
}

/* ------------------------------------------------------------------ */
/* Base                                                                */
/* ------------------------------------------------------------------ */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--white); color: var(--ink-700); font: 400 var(--t-md)/var(--lh-md) var(--font); -webkit-font-smoothing: antialiased; }
img { display: block; max-width: 100%; }
a { color: inherit; }
button { font: inherit; color: inherit; }
:focus-visible { outline: none; box-shadow: var(--focus); }
.container { max-width: var(--container); margin-inline: auto; padding-inline: var(--gutter); }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* Headings */
.display { font-size: var(--t-4xl); line-height: var(--lh-4xl); font-weight: 600; letter-spacing: -0.03em; color: var(--ink-900); margin: 0; }
.h1 { font-size: var(--t-3xl); line-height: var(--lh-3xl); font-weight: 600; letter-spacing: -0.025em; color: var(--ink-900); margin: 0; }
.h2 { font-size: var(--t-2xl); line-height: var(--lh-2xl); font-weight: 600; letter-spacing: -0.02em; color: var(--ink-900); margin: 0; }
.h3 { font-size: var(--t-lg); line-height: var(--lh-lg); font-weight: 600; letter-spacing: -0.01em; color: var(--ink-900); margin: 0; }
.lead { font-size: var(--t-lg); line-height: var(--lh-lg); color: var(--ink-500); margin: 0; }
.meta { font-size: var(--t-sm); line-height: var(--lh-sm); color: var(--ink-500); margin: 0; }
.price { font-weight: 600; color: var(--ink-900); }
.price s { font-weight: 400; color: var(--ink-500); margin-right: var(--s-1); }

/* ------------------------------------------------------------------ */
/* Buttons                                                             */
/* ------------------------------------------------------------------ */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: var(--s-2); height: 44px; padding: 0 var(--s-5); border: 0; border-radius: var(--r-md); font-size: var(--t-md); font-weight: 600; text-decoration: none; cursor: pointer; white-space: nowrap; transition: background-color .15s, box-shadow .15s; }
.btn svg { width: 18px; height: 18px; flex: none; }
.btn-primary { background: var(--brand-600); color: var(--white); }
.btn-primary:hover { background: var(--brand-700); }
.btn-secondary { background: var(--white); color: var(--ink-900); box-shadow: inset 0 0 0 1px var(--ink-200); }
.btn-secondary:hover { box-shadow: inset 0 0 0 1px var(--ink-900); }
.btn-ghost { background: transparent; color: var(--ink-900); }
.btn-ghost:hover { background: var(--ink-100); }
.btn-inverse { background: var(--white); color: var(--ink-900); }
.btn-inverse:hover { background: var(--brand-50); }
.btn-lg { height: 52px; padding: 0 var(--s-6); font-size: var(--t-lg); }
.btn-sm { height: 36px; padding: 0 var(--s-3); font-size: var(--t-sm); }
.btn-block { width: 100%; }
.btn[disabled] { background: var(--ink-100); color: var(--ink-300); box-shadow: none; cursor: default; }
.btn-icon { width: 40px; height: 40px; padding: 0; border-radius: var(--r-md); }

/* ------------------------------------------------------------------ */
/* Search bar: segments that each open a picker                        */
/* ------------------------------------------------------------------ */
.search { display: flex; align-items: stretch; background: var(--white); border-radius: var(--r-lg); box-shadow: var(--e-float); padding: var(--s-2); gap: var(--s-1); }
.seg { display: flex; flex-direction: column; justify-content: center; min-width: 0; padding: var(--s-2) var(--s-4); border-radius: var(--r-md); border: 0; background: transparent; text-align: left; cursor: pointer; position: relative; }
.seg:hover { background: var(--ink-50); }
.seg[aria-expanded="true"] { background: var(--ink-100); }
.seg + .seg::before { content: ''; position: absolute; left: -3px; top: 25%; bottom: 25%; width: 1px; background: var(--ink-200); }
.seg:hover::before, .seg:hover + .seg::before, .seg[aria-expanded="true"]::before, .seg[aria-expanded="true"] + .seg::before { opacity: 0; }
.seg-label { font-size: var(--t-xs); line-height: var(--lh-xs); font-weight: 600; color: var(--ink-500); }
.seg-value { font-size: var(--t-md); line-height: var(--lh-md); font-weight: 600; color: var(--ink-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.search .btn { align-self: center; margin-left: auto; }

/* Popover used by search segments */
.popover { position: absolute; z-index: 30; background: var(--white); border-radius: var(--r-lg); box-shadow: var(--e-float); padding: var(--s-2); min-width: 300px; }
.option { display: flex; align-items: center; gap: var(--s-3); width: 100%; padding: var(--s-3); border: 0; border-radius: var(--r-md); background: transparent; text-align: left; cursor: pointer; }
.option:hover { background: var(--ink-50); }
.option .check { width: 20px; height: 20px; border-radius: var(--r-sm); box-shadow: inset 0 0 0 1.5px var(--ink-300); display: grid; place-items: center; flex: none; }
.option[aria-checked="true"] .check { background: var(--brand-600); box-shadow: none; }
.option[aria-checked="true"] .check::after { content: ''; width: 10px; height: 6px; border: 2px solid var(--white); border-top: 0; border-right: 0; transform: rotate(-45deg) translate(1px, -1px); }
.option .end { margin-left: auto; }

/* ------------------------------------------------------------------ */
/* Chips (filters) and tabs                                            */
/* ------------------------------------------------------------------ */
.chips { display: flex; gap: var(--s-2); flex-wrap: wrap; }
.chip { display: inline-flex; align-items: center; gap: var(--s-2); height: 36px; padding: 0 var(--s-3); border: 0; border-radius: var(--r-md); background: var(--white); box-shadow: inset 0 0 0 1px var(--ink-200); font-size: var(--t-sm); font-weight: 500; color: var(--ink-700); cursor: pointer; white-space: nowrap; }
.chip:hover { box-shadow: inset 0 0 0 1px var(--ink-500); }
.chip[aria-pressed="true"] { background: var(--ink-900); color: var(--white); box-shadow: none; }
.chip .count { font-size: var(--t-xs); color: inherit; opacity: .65; }

.tabs { display: flex; gap: var(--s-6); border-bottom: 1px solid var(--ink-200); }
.tab { height: 44px; padding: 0; border: 0; background: none; font-size: var(--t-md); font-weight: 500; color: var(--ink-500); cursor: pointer; box-shadow: inset 0 -2px 0 transparent; white-space: nowrap; }
.tab:hover { color: var(--ink-900); }
.tab[aria-selected="true"] { color: var(--ink-900); font-weight: 600; box-shadow: inset 0 -2px 0 var(--ink-900); }

/* ------------------------------------------------------------------ */
/* Tags: availability states                                            */
/* ------------------------------------------------------------------ */
.tag { display: inline-flex; align-items: center; gap: 6px; height: 24px; padding: 0 var(--s-2); border-radius: var(--r-sm); font-size: var(--t-xs); font-weight: 600; white-space: nowrap; background: var(--ink-100); color: var(--ink-700); }
.tag::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.tag-free { background: var(--free-50); color: var(--free-700); }
.tag-last { background: var(--last-50); color: var(--last-700); }
.tag-plain::before { display: none; }
.tag-onphoto { background: var(--white); color: var(--ink-900); }

/* ------------------------------------------------------------------ */
/* Availability strip: the next 14 nights, one bar each.               */
/* The system's signature, taken from the logo's bars.                 */
/* ------------------------------------------------------------------ */
.strip { display: grid; grid-template-columns: repeat(14, 1fr); gap: 2px; height: 22px; align-items: end; }
.strip i { display: block; border-radius: 2px 2px 0 0; background: var(--free-500); height: 100%; }
.strip i.last { background: var(--last-500); height: 60%; }
.strip i.gone { background: var(--gone); height: 30%; }
.strip i.sel { box-shadow: 0 3px 0 -1px var(--ink-900); }
.strip-legend { display: flex; justify-content: space-between; font-size: var(--t-xs); line-height: var(--lh-xs); color: var(--ink-500); margin-top: var(--s-1); }

/* ------------------------------------------------------------------ */
/* Listing card                                                        */
/* ------------------------------------------------------------------ */
.listing { display: flex; flex-direction: column; gap: var(--s-3); text-decoration: none; cursor: pointer; border-radius: var(--r-lg); }
.listing-photo { position: relative; aspect-ratio: 4 / 3; border-radius: var(--r-lg); overflow: hidden; background: var(--ink-100); }
.listing-photo img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s ease; }
.listing:hover .listing-photo img { transform: scale(1.03); }
.listing-photo .tag { position: absolute; top: var(--s-3); left: var(--s-3); }
.listing-head { display: flex; justify-content: space-between; align-items: baseline; gap: var(--s-3); }
.listing-head .h3 { font-size: var(--t-md); line-height: var(--lh-md); }
.listing-score { font-size: var(--t-sm); font-weight: 600; color: var(--ink-900); white-space: nowrap; }
.listing-foot { display: flex; flex-direction: column; gap: 2px; }
.unit { font-weight: 400; color: var(--ink-500); }
.listing.is-active .listing-photo { box-shadow: 0 0 0 2px var(--white), 0 0 0 4px var(--ink-900); }

/* ------------------------------------------------------------------ */
/* Map and markers (Leaflet)                                           */
/* ------------------------------------------------------------------ */
.map { position: relative; background: var(--ink-100); border-radius: var(--r-xl); overflow: hidden; isolation: isolate; }
.map .leaflet-container { width: 100%; height: 100%; background: var(--ink-100); font: inherit; }
.map .map-base { filter: grayscale(1) sepia(.18) hue-rotate(175deg) saturate(1.6) contrast(.92) brightness(1.06); }
.map .leaflet-control-attribution { font-size: 10px; background: rgba(255,255,255,.8); }
.map .leaflet-control-zoom { border: 0; box-shadow: var(--e-marker); border-radius: var(--r-md); overflow: hidden; }
.map .leaflet-control-zoom a { width: 36px; height: 36px; line-height: 36px; color: var(--ink-900); border-color: var(--ink-200); }
.marker { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 var(--s-3); border-radius: var(--r-pill); background: var(--white); color: var(--ink-900); box-shadow: var(--e-marker); font: 600 var(--t-sm) var(--font); white-space: nowrap; cursor: pointer; transform: translate(-50%, -50%); transition: transform .15s; }
.marker::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--free-500); }
.marker.is-last::before { background: var(--last-500); }
.marker:hover { transform: translate(-50%, -50%) scale(1.06); }
.marker.is-active { background: var(--ink-900); color: var(--white); z-index: 1000; }
.marker.is-dim { opacity: .45; }
.marker-wrap { background: none; border: 0; }

/* ------------------------------------------------------------------ */
/* Calendar (date range)                                               */
/* ------------------------------------------------------------------ */
.cal { display: grid; grid-template-columns: repeat(7, 1fr); row-gap: 2px; text-align: center; font-size: var(--t-sm); }
.cal .dow { font-size: var(--t-xs); color: var(--ink-500); font-weight: 600; padding-bottom: var(--s-1); }
.cal .day { height: 36px; display: grid; place-items: center; color: var(--ink-900); position: relative; }
.cal .day.gone { color: var(--ink-300); text-decoration: line-through; }
.cal .day.range { background: var(--brand-50); }
.cal .day.start, .cal .day.end { background: var(--ink-900); color: var(--white); font-weight: 600; }
.cal .day.start { border-radius: var(--r-md) 0 0 var(--r-md); }
.cal .day.end { border-radius: 0 var(--r-md) var(--r-md) 0; }

/* ------------------------------------------------------------------ */
/* Summary lines (price breakdown)                                     */
/* ------------------------------------------------------------------ */
.lines { display: grid; grid-template-columns: 1fr auto; gap: var(--s-2) 0; margin: 0; font-size: var(--t-md); }
.lines dt { color: var(--ink-500); }
.lines dd { margin: 0; padding-left: var(--s-4); text-align: right; color: var(--ink-900); }
.lines .total { padding-top: var(--s-3); border-top: 1px solid var(--ink-200); font-weight: 600; color: var(--ink-900); font-size: var(--t-lg); }
.lines .save { color: var(--free-700); }

/* ------------------------------------------------------------------ */
/* Form fields                                                          */
/* ------------------------------------------------------------------ */
.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: var(--t-sm); font-weight: 600; color: var(--ink-900); }
.field .hint { font-size: var(--t-xs); color: var(--ink-500); }
.input { height: 48px; padding: 0 var(--s-4); border: 0; border-radius: var(--r-md); background: var(--white); box-shadow: inset 0 0 0 1px var(--ink-200); font: 400 var(--t-md) var(--font); color: var(--ink-900); width: 100%; }
.input::placeholder { color: var(--ink-300); }
.input:hover { box-shadow: inset 0 0 0 1px var(--ink-500); }
.input:focus { outline: none; box-shadow: inset 0 0 0 2px var(--brand-600); }
.radio-card { display: flex; gap: var(--s-3); align-items: flex-start; padding: var(--s-4); border-radius: var(--r-md); box-shadow: inset 0 0 0 1px var(--ink-200); cursor: pointer; }
.radio-card:has(input:checked) { box-shadow: inset 0 0 0 2px var(--ink-900); }
.radio-card input { margin: 3px 0 0; accent-color: var(--ink-900); width: 18px; height: 18px; }

/* ------------------------------------------------------------------ */
/* Header + footer                                                      */
/* ------------------------------------------------------------------ */
.site-header { position: sticky; top: 0; z-index: 40; background: rgba(255,255,255,.92); backdrop-filter: saturate(1.4) blur(12px); border-bottom: 1px solid var(--ink-200); }
.site-header .container { display: flex; align-items: center; gap: var(--s-8); height: 72px; }
.logo { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--ink-900); font-size: var(--t-lg); font-weight: 600; letter-spacing: -0.01em; white-space: nowrap; }
.logo img { width: 28px; }
.site-nav { display: flex; gap: var(--s-1); }
.site-nav a { padding: var(--s-2) var(--s-3); border-radius: var(--r-md); font-size: var(--t-sm); font-weight: 500; color: var(--ink-700); text-decoration: none; }
.site-nav a:hover { background: var(--ink-100); color: var(--ink-900); }
.site-header .end { margin-left: auto; display: flex; align-items: center; gap: var(--s-2); }

.site-footer { background: var(--ink-900); color: var(--ink-300); padding-block: var(--s-16) var(--s-10); font-size: var(--t-sm); }
.site-footer .cols { display: grid; grid-template-columns: 2fr repeat(3, 1fr); gap: var(--s-10); }
.site-footer h4 { margin: 0 0 var(--s-3); color: var(--white); font-size: var(--t-sm); font-weight: 600; }
.site-footer a { display: block; text-decoration: none; padding: 2px 0; }
.site-footer a:hover { color: var(--white); }
.site-footer .logo { color: var(--white); margin-bottom: var(--s-3); }
.site-footer .logo img { filter: brightness(0) invert(1); }
.site-footer .base { display: flex; justify-content: space-between; margin-top: var(--s-12); padding-top: var(--s-5); border-top: 1px solid rgba(255,255,255,.12); font-size: var(--t-xs); }

/* ------------------------------------------------------------------ */
/* Compact search (in the header of inner pages)                        */
/* ------------------------------------------------------------------ */
.search-compact { box-shadow: inset 0 0 0 1px var(--ink-200); padding: var(--s-1); border-radius: var(--r-md); }
.search-compact .seg { padding: 2px var(--s-3); }
.search-compact .seg-value { font-size: var(--t-sm); line-height: var(--lh-sm); }
.search-compact .btn { height: 36px; padding: 0 var(--s-3); font-size: var(--t-sm); }

/* ------------------------------------------------------------------ */
/* Card: the one container. Border, no shadow.                          */
/* ------------------------------------------------------------------ */
.card { background: var(--white); border-radius: var(--r-lg); box-shadow: inset 0 0 0 1px var(--ink-200); }
.card-pad { padding: var(--s-6); }
.divider { height: 1px; background: var(--ink-200); border: 0; margin: var(--s-5) 0; }

/* ------------------------------------------------------------------ */
/* Stepper: booking is a sequence, so it is numbered                    */
/* ------------------------------------------------------------------ */
.stepper { display: flex; gap: var(--s-6); list-style: none; margin: 0; padding: 0; counter-reset: st; }
.stepper li { display: flex; align-items: center; gap: var(--s-2); font-size: var(--t-sm); font-weight: 500; color: var(--ink-500); counter-increment: st; }
.stepper li::before { content: counter(st); width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-size: var(--t-xs); font-weight: 600; box-shadow: inset 0 0 0 1.5px var(--ink-300); }
.stepper li.done::before { content: '✓'; background: var(--free-50); color: var(--free-700); box-shadow: none; }
.stepper li.now { color: var(--ink-900); font-weight: 600; }
.stepper li.now::before { background: var(--ink-900); color: var(--white); box-shadow: none; }
.stepper li + li { padding-left: var(--s-6); border-left: 1px solid var(--ink-200); }

textarea.input { height: auto; min-height: 96px; padding: var(--s-3) var(--s-4); resize: vertical; line-height: var(--lh-md); }
select.input { appearance: none; background-image: linear-gradient(45deg, transparent 50%, var(--ink-500) 50%), linear-gradient(135deg, var(--ink-500) 50%, transparent 50%); background-position: calc(100% - 20px) 21px, calc(100% - 15px) 21px; background-size: 5px 5px; background-repeat: no-repeat; }

@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; animation: none !important; } }

@media (max-width: 720px) {
  :root { --gutter: 16px; --t-4xl: 36px; --lh-4xl: 40px; --t-3xl: 30px; --lh-3xl: 36px; --t-2xl: 24px; --lh-2xl: 30px; }
  .site-nav { display: none; }
  .site-header .container { height: 64px; gap: var(--s-3); }
  .logo { font-size: var(--t-md); }
  .search { flex-wrap: wrap; }
  .search .seg { flex: 1 1 40% !important; }
  .search .seg::before { display: none; }
  .search .btn { width: 100%; margin: var(--s-1) 0 0; }
  .hide-sm { display: none; }
  .site-footer .cols { grid-template-columns: 1fr 1fr; }
  .site-footer .cols > :first-child { grid-column: 1 / -1; }
}


/* Home layout (design/website/c-map/home.html) */
  /* Layout only: every colour, size and radius comes from system.css */
  .pg-home .hero { padding-block: var(--s-12) var(--s-6); display: grid; grid-template-columns: 1fr auto; gap: var(--s-10); align-items: end; }
  .pg-home .hero .display { max-width: 15ch; }
  .pg-home .hero .lead { max-width: 36ch; margin-bottom: 6px; }
  .pg-home .search-row { position: relative; z-index: 20; margin-bottom: calc(var(--s-10) * -1); padding-inline: var(--s-6); }
  .pg-home .search .seg { flex: 1; }
  .pg-home .search .seg.where { flex: 1.4; }

  .pg-home .stage { position: relative; height: 640px; }
  .pg-home .stage .map { position: absolute; inset: 0; }
  .pg-home .rail { position: absolute; z-index: 10; top: var(--s-16); left: var(--s-6); bottom: var(--s-6); width: 360px; background: var(--white); border-radius: var(--r-lg); box-shadow: var(--e-float); display: flex; flex-direction: column; overflow: hidden; }
  .pg-home .rail-head { padding: var(--s-4) var(--s-5) var(--s-3); border-bottom: 1px solid var(--ink-200); display: flex; justify-content: space-between; align-items: baseline; }
  .pg-home .rail-list { overflow: auto; flex: 1; }
  .pg-home .loc { display: block; width: 100%; padding: var(--s-4) var(--s-5); border: 0; border-bottom: 1px solid var(--ink-200); background: var(--white); text-align: left; cursor: pointer; }
  .pg-home .loc:hover { background: var(--ink-50); }
  .pg-home .loc-top { display: flex; justify-content: space-between; align-items: center; gap: var(--s-3); }
  .pg-home .loc-top + .loc-top { margin-top: 2px; }
  .pg-home .loc-more { display: none; margin-top: var(--s-3); }
  .pg-home .loc.is-active { background: var(--brand-50); }
  .pg-home .loc.is-active .loc-more { display: block; }
  .pg-home .loc-more img { width: 100%; height: 132px; object-fit: cover; border-radius: var(--r-md); margin-bottom: var(--s-3); }
  .pg-home .loc-more ul { list-style: none; margin: 0 0 var(--s-3); padding: 0; }
  .pg-home .loc-more li { font-size: var(--t-sm); line-height: var(--lh-sm); color: var(--ink-700); padding-left: var(--s-4); position: relative; }
  .pg-home .loc-more li::before { content: ''; position: absolute; left: 2px; top: 8px; width: 5px; height: 5px; border-radius: 50%; background: var(--ink-300); }

  .pg-home section.block { padding-top: var(--s-24); }
  .pg-home .block-head { display: flex; justify-content: space-between; align-items: end; gap: var(--s-8); margin-bottom: var(--s-6); }
  .pg-home .block-head .lead { margin-top: var(--s-2); max-width: 52ch; }
  .pg-home .listings { display: grid; grid-template-columns: repeat(4, 1fr); gap: var(--s-8) var(--s-6); margin-top: var(--s-8); }

  .pg-home .basics { display: grid; grid-template-columns: repeat(4, 1fr); border-top: 1px solid var(--ink-200); }
  .pg-home .basics div { padding: var(--s-6) var(--s-6) 0 0; }
  .pg-home .basics svg { width: 24px; height: 24px; color: var(--brand-600); margin-bottom: var(--s-3); }
  .pg-home .basics .meta { margin-top: var(--s-1); max-width: 28ch; }

  .pg-home .long { background: var(--ink-900); border-radius: var(--r-xl); padding: var(--s-12); display: grid; grid-template-columns: 1fr 1.1fr; gap: var(--s-12); color: var(--ink-300); }
  .pg-home .long .h1 { color: var(--white); margin-bottom: var(--s-3); }
  .pg-home .long p { margin: 0 0 var(--s-6); max-width: 40ch; }
  .pg-home .rates { width: 100%; border-collapse: collapse; font-size: var(--t-md); }
  .pg-home .rates th { text-align: left; font-size: var(--t-xs); font-weight: 600; color: var(--ink-300); padding: 0 0 var(--s-2); }
  .pg-home .rates td { padding: var(--s-3) 0; border-top: 1px solid rgba(255,255,255,.12); color: var(--white); vertical-align: top; }
  .pg-home .rates td.r, .pg-home .rates th.r { text-align: right; }
  .pg-home .rates .m { font-weight: 600; }
  .pg-home .rates .note { display: block; font-size: var(--t-xs); color: var(--ink-300); font-weight: 400; }

  .pg-home .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--s-6); counter-reset: step; }
  .pg-home .step { padding-top: var(--s-5); border-top: 2px solid var(--ink-900); }
  .pg-home .step::before { counter-increment: step; content: 'Step ' counter(step); display: block; font-size: var(--t-sm); font-weight: 600; color: var(--brand-600); margin-bottom: var(--s-2); }
  .pg-home .step .meta { margin-top: var(--s-2); max-width: 34ch; }
  .pg-home main { padding-bottom: var(--s-24); }

  @media (max-width: 1100px) {
    .pg-home .listings { grid-template-columns: repeat(2, 1fr); }
    .pg-home .long { grid-template-columns: 1fr; }
    .pg-home .hero { grid-template-columns: 1fr; }
  }
  @media (max-width: 720px) {
    .pg-home .site-header .end .btn-ghost { display: none; }
    .pg-home .hero { padding-block: var(--s-6) var(--s-4); gap: var(--s-3); }
    .pg-home .search-row { margin-bottom: var(--s-4); padding-inline: 0; }
    .pg-home .search { flex-wrap: wrap; }
    .pg-home .search .seg.where { flex-basis: 100%; }
    .pg-home .search .seg.where + .seg::before { display: none; }
    .pg-home .search .btn { width: 100%; margin-top: var(--s-1); }
    .pg-home .stage { height: auto; }
    .pg-home .stage .map { position: relative; height: 380px; }
    .pg-home .rail { position: static; width: auto; margin-top: var(--s-3); box-shadow: inset 0 0 0 1px var(--ink-200); }
    .pg-home .block-head { flex-direction: column; align-items: start; }
    .pg-home .listings { grid-template-columns: 1fr; }
    .pg-home .basics { grid-template-columns: 1fr 1fr; }
    .pg-home .long { padding: var(--s-6); }
    .pg-home .steps { grid-template-columns: 1fr; }
    .pg-home .tabs { overflow-x: auto; }
    .pg-home section.block { padding-top: var(--s-16); }
  }


/* Results layout (design/website/c-map/results.html) */
  /* Layout only */
  html:has(body.pg-results), body.pg-results { height: 100%; }
  body.pg-results { display: flex; flex-direction: column; }
  .pg-results .site-header .container { max-width: none; }
  .pg-results .site-header .search { margin-inline: auto; width: 560px; }
  .pg-results .site-header .search .seg { flex: 1; }
  .pg-results .filters { display: flex; align-items: center; gap: var(--s-2); padding: var(--s-3) var(--gutter); border-bottom: 1px solid var(--ink-200); overflow-x: auto; flex: none; }
  .pg-results .filters .sep { width: 1px; height: 24px; background: var(--ink-200); margin: 0 var(--s-2); flex: none; }
  .pg-results .filters .end { margin-left: auto; }
  .pg-results .split { flex: 1; min-height: 0; display: grid; grid-template-columns: minmax(0, 1fr) 46%; }
  .pg-results .list { overflow: auto; padding: var(--s-6) var(--gutter) var(--s-16); }
  .pg-results .list-head { display: flex; justify-content: space-between; align-items: baseline; gap: var(--s-4); margin-bottom: var(--s-6); }
  .pg-results .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--s-8) var(--s-6); }
  .pg-results .side { position: relative; padding: var(--s-4) var(--s-4) var(--s-4) 0; }
  .pg-results .side .map { position: absolute; inset: var(--s-4) var(--s-4) var(--s-4) 0; }
  .pg-results .detail { position: absolute; inset: var(--s-4) var(--s-4) var(--s-4) 0; z-index: 500; display: flex; flex-direction: column; overflow: hidden; background: var(--white); border-radius: var(--r-xl); box-shadow: inset 0 0 0 1px var(--ink-200); }
  .pg-results .detail[hidden] { display: none; }
  .pg-results .detail-photo { position: relative; height: 240px; flex: none; }
  .pg-results .detail-photo img { width: 100%; height: 100%; object-fit: cover; }
  .pg-results .detail-photo .btn { position: absolute; top: var(--s-4); left: var(--s-4); box-shadow: var(--e-marker); }
  .pg-results .detail-body { flex: 1; overflow: auto; padding: var(--s-6); display: grid; grid-template-columns: 1fr 1fr; gap: var(--s-6) var(--s-8); align-content: start; }
  .pg-results .detail-body .full { grid-column: 1 / -1; }
  .pg-results .detail-body ul { margin: var(--s-3) 0 0; padding-left: var(--s-5); font-size: var(--t-sm); line-height: var(--lh-sm); color: var(--ink-700); }
  .pg-results .cal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--s-2); }
  .pg-results .detail-foot { flex: none; display: flex; align-items: center; gap: var(--s-4); padding: var(--s-4) var(--s-6); border-top: 1px solid var(--ink-200); }
  .pg-results .detail-foot .sum { flex: 1; }
  .pg-results .detail-foot .sum .h2 { line-height: 1.1; }
  @media (max-width: 1100px) {
    .pg-results .site-header .search { display: none; }
    .pg-results .split { grid-template-columns: 1fr; }
    .pg-results .side { display: none; }
    .pg-results .side.show { display: block; position: fixed; inset: 0; z-index: 60; padding: 0; background: var(--white); }
    .pg-results .side.show .detail { inset: 0; border-radius: 0; box-shadow: none; }
  }
  @media (max-width: 720px) {
    .pg-results .grid { grid-template-columns: 1fr; }
    .pg-results .detail-body { grid-template-columns: 1fr; }
    .pg-results .list-head { flex-direction: column; }
  }


/* Checkout layout (design/website/c-map/checkout.html) */
  /* Layout only */
  .pg-checkout .top { display: flex; justify-content: space-between; align-items: center; gap: var(--s-6); padding-block: var(--s-8) var(--s-10); flex-wrap: wrap; }
  .pg-checkout .layout { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: var(--s-12); align-items: start; padding-bottom: var(--s-24); }
  .pg-checkout .form { display: grid; gap: var(--s-10); }
  .pg-checkout .group { display: grid; grid-template-columns: 1fr 1fr; gap: var(--s-5); }
  .pg-checkout .group > .h2, .pg-checkout .group > .full { grid-column: 1 / -1; }
  .pg-checkout .group > .h2 { margin-bottom: calc(var(--s-1) * -1); }
  .pg-checkout .pay { display: grid; gap: var(--s-3); }
  .pg-checkout .pay .meta { margin-top: 2px; }
  .pg-checkout .summary { position: sticky; top: 96px; overflow: hidden; }
  .pg-checkout .summary img { width: 100%; height: 180px; object-fit: cover; }
  .pg-checkout .summary .card-pad { display: grid; gap: var(--s-4); }
  .pg-checkout .when { display: grid; grid-template-columns: 1fr 1fr; gap: var(--s-3); }
  .pg-checkout .when > div { padding: var(--s-3); border-radius: var(--r-md); background: var(--ink-50); }
  .pg-checkout .submit { display: flex; align-items: center; gap: var(--s-5); flex-wrap: wrap; }
  @media (max-width: 1000px) { .pg-checkout .layout { grid-template-columns: 1fr; } .pg-checkout .summary { position: static; order: -1; } }
  @media (max-width: 720px) { .pg-checkout .group { grid-template-columns: 1fr; } .pg-checkout .stepper li + li { padding-left: var(--s-3); } .pg-checkout .stepper { gap: var(--s-3); } .pg-checkout .stepper li { font-size: var(--t-xs); } .pg-checkout .submit .btn { width: 100%; } }


/* Built-site additions: date and guest fields inside search segments,
   form errors, plain content pages (confirmation, manage booking). */
.seg input, .seg select { border: 0; background: transparent; padding: 0; font: 600 var(--t-md)/var(--lh-md) var(--font); color: var(--ink-900); width: 100%; min-width: 0; cursor: pointer; appearance: none; }
.search-compact .seg input, .search-compact .seg select { font-size: var(--t-sm); line-height: var(--lh-sm); }
.seg:focus-within { background: var(--ink-100); }
.seg input:focus, .seg select:focus { outline: none; }
.where-pick { position: relative; }
.where-pick summary { list-style: none; display: flex; flex-direction: column; cursor: pointer; }
.where-pick summary::-webkit-details-marker { display: none; }
.where-pick[open] > .popover { display: block; }
.where-pick .popover { top: calc(100% + 12px); left: 0; display: none; }
.option .option-box { width: 20px; height: 20px; border-radius: var(--r-sm); box-shadow: inset 0 0 0 1.5px var(--ink-300); display: grid; place-items: center; flex: none; }
.option:has(input:checked) .option-box { background: var(--brand-600); box-shadow: none; }
.option:has(input:checked) .option-box::after { content: ''; width: 10px; height: 6px; border: 2px solid var(--white); border-top: 0; border-right: 0; transform: rotate(-45deg) translate(1px, -1px); }
.option:has(input:focus-visible) { box-shadow: var(--focus); }
.field .error, .form-error { font-size: var(--t-sm); color: #B42318; margin: 0; }
.page { padding-block: var(--s-12) var(--s-24); }
a.chip { text-decoration: none; }
.pg-results .site-header .search { width: 700px; }
.search-compact .seg input[type=date] { min-width: 8.2em; }
.page .narrow { max-width: 640px; }
.empty { padding: var(--s-12) 0; }
@media (max-width: 720px) {
  .site-header .end .btn-secondary { display: none; }
  .site-header .logo { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
}
</style>
