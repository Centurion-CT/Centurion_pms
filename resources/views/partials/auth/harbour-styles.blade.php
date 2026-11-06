{{-- Staff auth styles: login, forgot, reset, activate. --}}
<style>
    .cx-login {
        --cx-panel: #16324B; --cx-action: #336799;
        --ink: #14202C; --ink-700: #364656; --ink-500: #5E6F80; --line: #8796A5;
        min-height: 100vh;
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
        background: #FFFFFF;
        color: var(--ink-700);
        font: 400 16px/1.5 'Hanken Grotesk', system-ui, sans-serif;
    }

    .cx-column { order: -1; display: flex; flex-direction: column; justify-content: space-between; gap: 40px; padding: 40px clamp(16px, 5vw, 72px); }
    .cx-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; font-weight: 600; font-size: 18px; color: var(--ink); }
    .cx-mark { width: 40px; height: 40px; flex: none; border-radius: 8px; object-fit: contain; }
    .cx-mark-letter { display: grid; place-items: center; background: var(--cx-panel); color: #FFFFFF; font: 600 20px/1 'Bricolage Grotesque', system-ui, sans-serif; }
    .cx-body { width: 100%; max-width: 400px; }

    .cx-eyebrow { margin: 0 0 8px; color: var(--ink-500); }
    .cx-title { margin: 0; font: 600 40px/1.08 'Bricolage Grotesque', system-ui, sans-serif; letter-spacing: -.02em; color: var(--ink); }
    .cx-lede { margin: 12px 0 0; font-size: 17px; color: var(--ink-500); }
    .cx-meta { margin: 8px 0 0; font-size: 15px; color: var(--ink-500); }
    .cx-lede strong, .cx-meta strong { font-weight: 600; color: var(--ink); }
    .cx-alert { margin: 24px 0 0; padding: 12px 14px; border-radius: 8px; background: #FBE7EA; color: #A12A40; font-size: 15px; line-height: 1.45; }

    .cx-form { margin-top: 32px; display: flex; flex-direction: column; gap: 20px; }
    .cx-label-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; margin-bottom: 6px; }
    .cx-label { font-size: 14px; font-weight: 600; color: var(--ink); }
    .cx-label-row a, .cx-link { font-size: 14px; font-weight: 500; color: var(--cx-action); }
    .cx-input-wrap { position: relative; }
    .cx-input {
        width: 100%; height: 48px; box-sizing: border-box; padding: 0 14px;
        border: 1px solid var(--line); border-radius: 8px; background: #FFFFFF;
        font: inherit; color: var(--ink); outline: none;
    }
    .cx-input-wrap .cx-input { padding-right: 64px; }
    .cx-input::placeholder { color: var(--ink-500); }
    .cx-input:focus { border-color: var(--cx-action); box-shadow: 0 0 0 1px var(--cx-action); }
    .cx-input.is-invalid { border-color: #A12A40; }
    .cx-toggle { position: absolute; top: 0; right: 0; height: 48px; min-width: 60px; padding: 0 12px; border: 0; background: none; font: inherit; font-size: 14px; font-weight: 500; color: var(--cx-action); cursor: pointer; }
    .cx-toggle .cx-hide { display: none; }
    .is-revealed .cx-toggle .cx-show { display: none; }
    .is-revealed .cx-toggle .cx-hide { display: inline; }
    .cx-error { margin: 6px 0 0; font-size: 14px; color: #A12A40; }
    .cx-hint { margin: 6px 0 0; font-size: 14px; color: var(--ink-500); }
    .cx-check { display: flex; align-items: center; gap: 10px; min-height: 44px; font-size: 15px; }
    .cx-check input { width: 20px; height: 20px; margin: 0; accent-color: var(--cx-action); }

    .cx-submit, .cx-secondary { display: inline-flex; align-items: center; justify-content: center; height: 48px; padding: 0 20px; border-radius: 8px; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
    .cx-submit { border: 0; background: var(--cx-action); color: #FFFFFF; }
    .cx-submit:hover { filter: brightness(.9); }
    .cx-submit:disabled { opacity: .7; cursor: wait; }
    .cx-secondary { border: 1px solid var(--line); background: #FFFFFF; color: var(--ink); }
    .cx-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; }
    .cx-after { margin: 24px 0 0; font-size: 14px; color: var(--ink-500); }
    .cx-submit:focus-visible, .cx-secondary:focus-visible, .cx-toggle:focus-visible, .cx-column a:focus-visible { outline: 2px solid var(--cx-action); outline-offset: 2px; }

    .cx-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 24px; font-size: 13px; color: var(--ink-500); }
    .cx-foot nav { display: flex; gap: 20px; }
    .cx-foot a { color: var(--ink-500); }

    .cx-panel { display: flex; flex-direction: column; gap: 40px; padding: 40px clamp(16px, 5vw, 72px) 0; background: var(--cx-panel); color: #FFFFFF; overflow: hidden; }
    .cx-address { margin: 0; font-size: 15px; overflow-wrap: anywhere; }
    .cx-band-brand { display: none; }
    .cx-bars { flex: 1 1 360px; min-height: 320px; }
    .cx-bars-photo { background-size: cover; background-position: center; clip-path: polygon(0% 56%, 30% 56%, 30% 100%, 35% 100%, 35% 28%, 65% 28%, 65% 100%, 70% 100%, 70% 0%, 100% 0%, 100% 100%, 0% 100%); }
    .cx-bars-solid { display: flex; align-items: flex-end; gap: 5%; }
    .cx-bars-solid i { flex: 1 1 0; }
    .cx-bars-solid i:nth-child(1) { height: 44%; background: #26527D; }
    .cx-bars-solid i:nth-child(2) { height: 72%; background: #336799; }
    .cx-bars-solid i:nth-child(3) { height: 100%; background: #8DB6DE; }
    .cx-login[style] .cx-bars-solid i:nth-child(1) { background: color-mix(in srgb, var(--cx-panel) 82%, #FFF); }
    .cx-login[style] .cx-bars-solid i:nth-child(2) { background: color-mix(in srgb, var(--cx-panel) 62%, #FFF); }
    .cx-login[style] .cx-bars-solid i:nth-child(3) { background: color-mix(in srgb, var(--cx-panel) 40%, #FFF); }

    /* Phones and small tablets: the form first, the panel a slim band. */
    @media (max-width: 900px) {
        .cx-login { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto 1fr; }
        .cx-column { order: 0; padding: 28px 16px 20px; gap: 28px; }
        .cx-brand { display: none; }
        .cx-body { max-width: 480px; }
        .cx-title { font-size: 30px; line-height: 1.1; letter-spacing: -.015em; }
        .cx-panel { flex-direction: row; justify-content: space-between; align-items: flex-end; gap: 16px; padding: 20px 16px 0; }
        .cx-address { display: none; }
        .cx-band-brand { display: flex; align-items: center; gap: 10px; padding-bottom: 20px; font-weight: 600; font-size: 17px; min-width: 0; }
        .cx-band-brand .cx-mark { width: 36px; height: 36px; background: #FFFFFF; }
        .cx-band-brand .cx-mark-letter { color: var(--cx-panel); }
        .cx-bars { flex: 0 0 96px; min-height: 0; height: 84px; }
    }
</style>
