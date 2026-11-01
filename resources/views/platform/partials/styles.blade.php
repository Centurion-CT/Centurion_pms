{{-- Platform pages on the central domain: home, sign-up, find-my-business.
     Same family as the staff sign-in ("Harbour"): Bricolage Grotesque +
     Hanken Grotesk, navy slab, logo blue for actions.
     Designs: design/platform/superdesign (direction A, "Product tour"). --}}
<style>
    :root {
        --navy-950: #0B1C2D; --navy-900: #0F2438; --navy-800: #16324B; --navy-700: #2A4A68; --navy-300: #8DB6DE; --navy-200: #A9C5E1;
        --ink-900: #14202C; --ink-700: #364656; --ink-500: #5E6F80; --ink-300: #A3B1BE; --line: #D9E2EA; --fill: #EDF2F6; --paper: #F5F8FA;
        --blue: #336799; --blue-700: #26527D; --blue-50: #EAF1F8; --ok: #8FE0C0; --bad: #F6B3BF;
        --display: 'Bricolage Grotesque', system-ui, sans-serif; --body: 'Hanken Grotesk', system-ui, sans-serif;
        --e-float: 0 24px 60px -24px rgba(11, 28, 45, .45), 0 2px 6px rgba(11, 28, 45, .08);
        --e-soft: 0 14px 40px -18px rgba(11, 28, 45, .28);
        --focus: 0 0 0 3px rgba(141, 182, 222, .35);
    }
    * { box-sizing: border-box; }
    html { background: var(--navy-900); }
    body { margin: 0; background: #fff; color: var(--ink-700); font: 400 16px/1.55 var(--body); }
    img { display: block; max-width: 100%; }
    a { color: inherit; text-decoration: none; }
    :focus-visible { outline: none; box-shadow: var(--focus); border-radius: 6px; }
    .container { max-width: 1200px; margin: 0 auto; padding-inline: 40px; }
    .display, .h1, .h2, .h3 { font-family: var(--display); font-weight: 600; margin: 0; color: var(--ink-900); }
    .display { letter-spacing: -.025em; line-height: 1.02; }
    .h1 { letter-spacing: -.02em; line-height: 1.08; }
    .h2 { font-size: 30px; letter-spacing: -.012em; line-height: 1.15; }
    .h3 { font-size: 18px; line-height: 1.3; }
    .lead { font-size: 18px; line-height: 1.55; color: var(--ink-500); margin: 0; }
    .meta { font-size: 14px; color: var(--ink-500); margin: 0; }
    .on-navy .h1, .on-navy .display { color: #fff; }
    .on-navy .lead, .on-navy .meta { color: var(--navy-200); }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 20px; border-radius: 8px; border: 0; font: 600 16px/1 var(--body); cursor: pointer; white-space: nowrap; transition: background .15s, color .15s, box-shadow .15s; }
    .btn[disabled] { opacity: .6; cursor: not-allowed; }
    .btn-sm { height: 36px; padding: 0 14px; font-size: 14px; }
    .btn-lg { height: 48px; }
    .btn-block { width: 100%; }
    .btn-primary { background: var(--blue); color: #fff; }
    .btn-primary:hover { background: var(--blue-700); }
    .btn-light { background: #fff; color: var(--navy-800); }
    .btn-light:hover { background: var(--blue-50); }
    .btn-ghost { background: transparent; color: #fff; }
    .btn-ghost:hover { background: rgba(255, 255, 255, .08); }
    .btn-outline-light { background: transparent; color: #fff; box-shadow: inset 0 0 0 1px var(--navy-300); }
    .btn-outline-light:hover { background: rgba(255, 255, 255, .06); }

    .logo { display: inline-flex; align-items: center; gap: 12px; font: 600 18px/1 var(--display); color: #fff; }
    .logo-mark { width: 34px; height: 34px; border-radius: 9px; background: #fff; display: grid; place-items: center; flex: none; }
    .logo-mark img { width: 24px; height: 24px; object-fit: contain; }

    /* Address field: the business's own web address. */
    .address { display: flex; align-items: center; background: #fff; color: var(--ink-900); transition: box-shadow .15s; }
    .address input { flex: 1 1 0; width: 0; min-width: 0; border: 0; outline: 0; background: none; color: var(--ink-900); padding: 0; }
    .address input::placeholder { color: var(--ink-300); font-weight: 500; }
    .address .suffix { flex: none; color: var(--ink-500); }
    .address:focus-within { box-shadow: inset 0 0 0 2px var(--navy-300), var(--focus); }
    .address input:focus-visible { box-shadow: none; }
    .astatus { display: flex; align-items: flex-start; gap: 8px; min-height: 22px; margin: 10px 0 0; font-size: 14px; color: var(--navy-200); }
    .astatus::before { content: ''; width: 8px; height: 8px; margin-top: 7px; border-radius: 50%; background: var(--navy-300); flex: none; }
    .astatus.is-free { color: var(--ok); }
    .astatus.is-free::before { background: var(--ok); }
    .astatus.is-taken { color: var(--bad); }
    .astatus.is-taken::before { background: var(--bad); }
    .astatus:empty { visibility: hidden; }

    /* ── Home ─────────────────────────────────────────── */
    .header { position: sticky; top: 0; z-index: 40; background: rgba(15, 36, 56, .96); backdrop-filter: blur(8px); }
    .header .container { display: flex; align-items: center; gap: 32px; height: 68px; }
    .nav { display: flex; gap: 26px; }
    .nav a { color: var(--navy-200); font-size: 14px; font-weight: 600; }
    .nav a:hover { color: #fff; }
    .header .end { margin-left: auto; display: flex; gap: 8px; align-items: center; }
    .hero { background: var(--navy-900); position: relative; overflow: hidden; }
    .hero::before { content: ''; position: absolute; inset: 0; background: radial-gradient(1000px 540px at 50% -8%, rgba(51, 103, 153, .38), transparent 62%); pointer-events: none; }
    .hero .container { position: relative; padding-top: 76px; padding-bottom: 300px; text-align: center; }
    .hero .display { font-size: 62px; max-width: 16ch; margin-inline: auto; }
    .hero .lead { margin: 22px auto 0; max-width: 58ch; }
    .picker { margin: 34px auto 0; max-width: 560px; }
    .picker-label { display: block; color: #fff; font-size: 15px; font-weight: 600; margin-bottom: 12px; }
    .picker .address { height: 64px; border-radius: 999px; padding: 0 8px 0 22px; }
    .picker .address input { font: 600 18px/1 var(--body); text-align: right; }
    .picker .address .suffix { font-size: 17px; padding-right: 8px; }
    .picker .address .btn { flex: none; height: 48px; border-radius: 999px; padding: 0 24px; }
    .picker .astatus { justify-content: center; }
    .hero-foot { margin-top: 16px; font-size: 14px; color: var(--navy-200); }
    .hero-foot a { color: #fff; font-weight: 600; }
    .compo { position: relative; max-width: 1060px; margin: 52px auto 0; height: 430px; }
    .compo .frame { position: absolute; border-radius: 14px; overflow: hidden; background: #fff; box-shadow: var(--e-float); }
    .compo .front { width: 720px; left: 50%; transform: translateX(-50%); top: 30px; z-index: 3; }
    .compo .back { width: 420px; right: 6px; top: 0; transform: rotate(4deg); z-index: 1; opacity: .96; }
    .compo .float { z-index: 4; width: 300px; left: -6px; bottom: -18px; }
    .compo .float .site { height: 150px; overflow: hidden; }
    .browser-bar { display: flex; align-items: center; gap: 10px; height: 38px; padding: 0 12px; background: var(--fill); border-bottom: 1px solid var(--line); }
    .dots { display: flex; gap: 6px; }
    .dots i { width: 10px; height: 10px; border-radius: 50%; background: var(--ink-300); opacity: .6; }
    .burl { flex: 1; min-width: 0; height: 24px; border-radius: 6px; background: #fff; display: flex; align-items: center; padding: 0 10px; font-size: 12px; color: var(--ink-500); overflow: hidden; white-space: nowrap; }
    .burl b { color: var(--ink-900); font-weight: 600; }
    .tour { padding-block: 96px; }
    .tour-head { max-width: 720px; }
    .tabs { display: flex; gap: 6px; margin-top: 28px; border-bottom: 1px solid var(--line); overflow-x: auto; }
    .tab { appearance: none; border: 0; background: none; font: 600 15px/1 var(--body); color: var(--ink-500); padding: 14px 16px; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
    .tab:hover { color: var(--ink-900); }
    .tab[aria-selected="true"] { color: var(--blue); border-color: var(--blue); }
    .tabbody { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 56px; align-items: center; margin-top: 44px; }
    .tabbody[hidden] { display: none; }
    .tabbody ul { margin: 22px 0 0; padding-left: 20px; color: var(--ink-700); }
    .tabbody li + li { margin-top: 10px; }
    .shot { border-radius: 14px; overflow: hidden; box-shadow: var(--e-soft); background: #fff; }
    .tall { max-height: 460px; overflow: hidden; }
    .band { background: var(--paper); padding-block: 84px; }
    .facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 40px; border-top: 1px solid var(--line); border-left: 1px solid var(--line); }
    .fact { padding: 30px 32px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
    .fact h3 { margin-bottom: 8px; }
    .fact p { margin: 0; color: var(--ink-500); font-size: 14px; }
    .story { background: var(--navy-900); position: relative; overflow: hidden; padding-block: 96px; }
    .story::before { content: ''; position: absolute; inset: 0; background: radial-gradient(760px 420px at 82% 50%, rgba(51, 103, 153, .3), transparent 60%); }
    .story .container { position: relative; }
    .story blockquote { margin: 0; max-width: 20ch; font: 600 44px/1.1 var(--display); color: #fff; letter-spacing: -.02em; }
    .places { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }
    .pill { display: inline-flex; align-items: center; height: 34px; padding: 0 16px; border-radius: 999px; font-size: 14px; font-weight: 600; background: rgba(255, 255, 255, .08); color: #fff; box-shadow: inset 0 0 0 1px var(--navy-700); }
    .steps-sec { padding-block: 88px; }
    .steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 40px; margin: 40px 0 0; padding: 0; list-style: none; counter-reset: s; }
    .steps li { counter-increment: s; position: relative; padding-top: 60px; }
    .steps li::before { content: counter(s); position: absolute; top: 0; left: 0; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 999px; font: 600 20px/1 var(--display); color: #fff; background: var(--blue); }
    .steps h3 { margin-bottom: 8px; }
    .steps p { margin: 0; color: var(--ink-500); font-size: 15px; }
    .faq-sec { background: var(--paper); padding-block: 88px; }
    .faq-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 56px; margin-top: 36px; }
    .faq details { border-bottom: 1px solid var(--line); }
    .faq summary { list-style: none; cursor: pointer; padding: 20px 0; font: 600 18px/1.3 var(--body); color: var(--ink-900); display: flex; justify-content: space-between; gap: 16px; }
    .faq summary::-webkit-details-marker { display: none; }
    .faq summary::after { content: '+'; font-weight: 500; color: var(--ink-500); }
    .faq details[open] summary::after { content: '–'; }
    .faq details p { margin: 0 0 20px; color: var(--ink-500); }
    .closing { background: var(--navy-900); padding-block: 92px; position: relative; overflow: hidden; }
    .closing::before { content: ''; position: absolute; inset: 0; background: radial-gradient(900px 440px at 50% 50%, rgba(51, 103, 153, .3), transparent 62%); }
    .closing .container { position: relative; text-align: center; }
    .closing .h1 { font-size: 42px; max-width: 18ch; margin-inline: auto; }
    .closing .lead { margin: 16px auto 0; max-width: 52ch; }
    .footer { background: var(--navy-950); color: var(--navy-200); padding: 48px 0; font-size: 14px; }
    .footer .container { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
    .footer a:hover { color: #fff; }
    .footer nav { display: flex; gap: 24px; flex-wrap: wrap; }

    /* ── Slab pages: sign-up, find my business ────────── */
    .page { display: grid; grid-template-columns: 560px minmax(0, 1fr); min-height: 100vh; }
    .slab { background: var(--navy-800); display: flex; flex-direction: column; padding: 40px 56px; color: #fff; }
    .slab-body { flex: 1; display: flex; flex-direction: column; justify-content: center; padding-block: 40px; max-width: 440px; }
    .slab .h1 { font-size: 34px; margin-bottom: 12px; color: #fff; }
    .slab .lead { color: var(--navy-200); font-size: 16px; }
    .slab .lead b { color: #fff; }
    .slab .meta { color: var(--navy-200); }
    .slab .meta a, .slab .link { color: #fff; font-weight: 600; text-decoration: underline; text-underline-offset: 4px; text-decoration-color: var(--navy-300); background: none; border: 0; padding: 0; font: inherit; font-weight: 600; cursor: pointer; }
    .slab form { display: grid; gap: 18px; margin-top: 26px; }
    .stepper { display: flex; gap: 10px; list-style: none; padding: 0; margin: 0 0 36px; counter-reset: st; flex-wrap: wrap; }
    .stepper li { counter-increment: st; display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--navy-300); }
    .stepper li::before { content: counter(st); display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; font-size: 12px; box-shadow: inset 0 0 0 1px var(--navy-700); }
    .stepper li.done::before { content: '✓'; background: var(--navy-700); color: #fff; box-shadow: none; }
    .stepper li.now { color: #fff; }
    .stepper li.now::before { background: #fff; color: var(--navy-800); box-shadow: none; }
    .stepper li:not(:last-child)::after { content: ''; width: 18px; height: 1px; background: var(--navy-700); margin-left: 4px; }
    .field { display: grid; gap: 6px; }
    .field > label, .label { font-size: 13px; font-weight: 600; color: #fff; }
    .input { width: 100%; height: 44px; border: 0; border-radius: 8px; padding: 0 14px; font: 500 15px/1 var(--body); color: var(--ink-900); background: #fff; outline: 0; }
    .input:focus { box-shadow: inset 0 0 0 2px var(--navy-300), var(--focus); }
    select.input { appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%235E6F80' stroke-width='2'%3E%3Cpath d='M3 5l4 4 4-4'/%3E%3C/svg%3E") no-repeat right 14px center; }
    .slab .address { height: 44px; border-radius: 8px; padding: 0 6px 0 14px; }
    .slab .address input { font: 500 15px/1 var(--body); }
    .slab .address .suffix { font-size: 15px; padding-right: 8px; }
    .slab .address .btn { height: 32px; border-radius: 6px; padding: 0 14px; font-size: 14px; }
    .hint { font-size: 13px; color: var(--navy-300); }
    .error { font-size: 13px; color: var(--bad); margin: 0; }
    .choice-row { display: flex; flex-wrap: wrap; gap: 8px; }
    .choice { position: relative; }
    .choice input { position: absolute; opacity: 0; }
    .choice span { display: inline-flex; align-items: center; height: 36px; padding: 0 14px; border-radius: 999px; font-size: 14px; font-weight: 600; color: var(--navy-200); box-shadow: inset 0 0 0 1px var(--navy-700); cursor: pointer; }
    .choice input:checked + span { background: #fff; color: var(--navy-800); box-shadow: none; }
    .choice input:focus-visible + span { box-shadow: var(--focus); }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
    .slab .actions .btn-primary { background: #fff; color: var(--navy-800); }
    .slab .actions .btn-primary:hover { background: var(--blue-50); }
    .code { display: flex; gap: 10px; }
    .code input { width: 52px; height: 60px; border-radius: 8px; border: 0; background: var(--navy-900); box-shadow: inset 0 0 0 1px var(--navy-700); text-align: center; font: 600 26px/1 var(--display); color: #fff; outline: none; }
    .code input:focus { box-shadow: inset 0 0 0 2px var(--navy-300); }
    .code .gap { width: 6px; }
    .checklist { list-style: none; margin: 28px 0 0; padding: 0; display: grid; gap: 14px; }
    .checklist li { display: flex; align-items: center; gap: 14px; font-size: 16px; }
    .checklist li::before { content: '✓'; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; background: #1F9A72; color: #fff; font-size: 14px; flex: none; }
    .done-card { margin-top: 32px; padding: 24px; border-radius: 12px; background: var(--navy-900); box-shadow: inset 0 0 0 1px var(--navy-700); }
    .done-card .url { font: 600 19px/1.3 var(--display); color: #fff; overflow-wrap: anywhere; margin: 8px 0 0; }
    .or { display: flex; align-items: center; gap: 12px; margin: 32px 0 0; font-size: 14px; color: var(--navy-300); }
    .or::before, .or::after { content: ''; flex: 1; border-top: 1px solid var(--navy-700); }
    .sent { margin-top: 24px; padding: 16px; border-radius: 8px; background: var(--navy-900); box-shadow: inset 0 0 0 1px var(--navy-700); font-size: 15px; color: var(--navy-200); }
    .slab-foot { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; color: var(--navy-300); }
    .slab-foot a:hover { color: #fff; }
    .side { background: var(--paper); display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 28px; padding: 64px 56px; }
    .side > * { width: 100%; max-width: 560px; }
    .browser { border-radius: 14px; overflow: hidden; background: #fff; box-shadow: var(--e-soft); }
    .site { padding: 24px; display: grid; gap: 20px; }
    .site-top { display: flex; align-items: center; gap: 12px; }
    .site-top .mono { width: 36px; height: 36px; border-radius: 8px; background: var(--navy-800); color: #fff; display: grid; place-items: center; font: 600 15px/1 var(--display); }
    .site-top b { font: 600 16px/1.2 var(--display); color: var(--ink-900); }
    .site-top .staff { margin-left: auto; height: 32px; padding: 0 12px; border-radius: 6px; box-shadow: inset 0 0 0 1px var(--line); display: inline-flex; align-items: center; font-size: 13px; font-weight: 600; color: var(--ink-900); }
    .site-hero { border-radius: 12px; min-height: 200px; padding: 24px; display: flex; flex-direction: column; justify-content: flex-end; color: #fff; background: linear-gradient(180deg, rgba(11, 28, 45, 0) 30%, rgba(11, 28, 45, .75)), var(--room-photo) center / cover; }
    .site-hero h3 { font: 600 28px/1.1 var(--display); margin: 0; letter-spacing: -.01em; }
    .site-hero p { margin: 4px 0 0; font-size: 14px; opacity: .85; }
    .site-search { display: grid; grid-template-columns: repeat(3, 1fr) auto; gap: 8px; }
    .site-search span { height: 40px; border-radius: 8px; box-shadow: inset 0 0 0 1px var(--line); display: flex; align-items: center; padding: 0 12px; font-size: 13px; color: var(--ink-500); }
    .site-search .go { background: var(--blue); color: #fff; box-shadow: none; font-weight: 600; }
    .gets { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; font-size: 14px; color: var(--ink-500); }
    .gets b { display: block; color: var(--ink-900); overflow-wrap: anywhere; }
    .mail-head { padding: 20px 24px; border-bottom: 1px solid var(--line); display: grid; gap: 4px; font-size: 14px; color: var(--ink-500); }
    .mail-head b { color: var(--ink-900); font: 600 17px/1.3 var(--display); }
    .mail-body { padding: 24px; display: grid; gap: 16px; color: var(--ink-700); }
    .mail-body p { margin: 0; }
    .mail-row { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 8px; box-shadow: inset 0 0 0 1px var(--line); }
    .mail-row b { color: var(--ink-900); display: block; }
    .mail-row small { color: var(--ink-500); font-size: 13px; }
    .mail-row .btn { margin-left: auto; }
    .biz-mark { width: 34px; height: 34px; flex: none; border-radius: 8px; display: grid; place-items: center; font: 600 15px/1 var(--display); color: #fff; background: var(--navy-800); }

    @media (max-width: 1100px) {
        .page { grid-template-columns: minmax(0, 1fr); }
        .side { padding: 40px 24px; }
    }
    @media (max-width: 1000px) {
        .hero .display { font-size: 44px; }
        .hero .container { padding-bottom: 72px; }
        .compo { height: auto; margin-top: 40px; }
        .compo .frame { position: static; width: 100% !important; transform: none !important; }
        .compo .back, .compo .float { display: none; }
        .tabbody, .faq-grid { grid-template-columns: minmax(0, 1fr); }
        .facts { grid-template-columns: 1fr 1fr; }
        .steps { grid-template-columns: minmax(0, 1fr); }
        .story blockquote { font-size: 32px; max-width: none; }
    }
    @media (max-width: 760px) {
        .container { padding-inline: 16px; }
        .nav, .hide-sm { display: none !important; }
        .header .container { gap: 12px; }
        .hero .container { padding-top: 48px; }
        .hero .display { font-size: 38px; }
        .picker .address { height: 56px; padding-left: 16px; }
        .picker .address input, .picker .address .suffix { font-size: 15px; }
        .picker .address .btn { height: 42px; padding: 0 16px; }
        .facts, .gets, .two { grid-template-columns: minmax(0, 1fr); }
        .tour, .band, .story, .steps-sec, .faq-sec, .closing { padding-block: 56px; }
        .closing .h1 { font-size: 32px; }
        .slab { padding: 28px 16px; }
        .code input { width: 42px; height: 52px; font-size: 22px; }
        .site-search { grid-template-columns: 1fr 1fr; }
        .mail-row { flex-wrap: wrap; }
        .mail-row .btn { margin-left: 0; width: 100%; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
