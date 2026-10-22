{{-- Guest self-service sub-pages (pay balance, cancel, change dates,
     extras, review). Centurion "Harbour". --}}
<style>
    .cx-gp { padding: 48px 20px 72px; }
    .cx-gp-card { max-width: 600px; margin: 0 auto; background: var(--white); border: 1px solid var(--line); border-radius: 12px; padding: 28px; display: grid; gap: 18px; }
    .cx-gp-card--wide { max-width: 720px; }
    @media (max-width: 480px) { .cx-gp-card { padding: 20px; } }
    .cx-gp-back { justify-self: start; font-size: 14px; font-weight: 600; color: var(--blue-600); text-decoration: none; }
    .cx-gp-kicker { font-size: 14px; color: var(--ink-mute); margin: 0; }
    .cx-gp-title { font-family: var(--display); font-weight: 600; font-size: 30px; line-height: 1.1; margin: 0; }
    .cx-gp-text { margin: 0; color: var(--ink-soft); }
    .cx-gp-text strong { color: var(--ink); }
    .cx-gp-dl { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; margin: 0; padding: 16px 0; border-block: 1px solid var(--line); }
    .cx-gp-dl dt { font-size: 14px; color: var(--ink-mute); }
    .cx-gp-dl dd { margin: 2px 0 0; font-weight: 600; }
    .cx-gp-note { margin: 0; padding: 12px 14px; border-radius: 8px; font-size: 15px; }
    .cx-gp-note--ok { background: #EEF8F2; color: #1D5E3F; }
    .cx-gp-note--warn { background: #FFF6E5; color: #7A4E07; }
    .cx-gp-note--bad { background: #FCEDEE; color: #8C2A33; }
    .cx-gp-grid2 { display: grid; gap: 14px; grid-template-columns: 1fr; }
    @media (min-width: 560px) { .cx-gp-grid2 { grid-template-columns: 1fr 1fr; } }
    .cx-gp-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
    .cx-gp-input { width: 100%; min-height: 46px; border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; font: inherit; color: var(--ink); background: var(--white); }
    .cx-gp-input:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(51, 103, 153, 0.18); }
    .cx-gp-error { margin: 6px 0 0; font-size: 13px; color: var(--rose); }
    .cx-gp-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
    .cx-gp-list { margin: 0; padding: 0; list-style: none; border: 1px solid var(--line); border-radius: 8px; }
    .cx-gp-list li { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 12px 14px; }
    .cx-gp-list li + li { border-top: 1px solid var(--line); }
    .cx-gp-list small { display: block; color: var(--ink-mute); font-size: 13px; }
    .cx-gp-h2 { font-size: 15px; font-weight: 600; margin: 0 0 8px; }
    .cx-gp-stars { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; }
</style>
