{{-- Centurion "Harbour" staff auth styles: login, forgot, reset, activate. --}}
<style>
    .cx-login {
        --navy: #16324B; --navy-deep: #0F2438; --navy-line: #2A4A68;
        --blue: #336799; --blue-200: #A9C5E1; --blue-300: #8DB6DE;
        --ink: #1A2430; --white: #FFFFFF; --rose: #F2A7A7;
        min-height: 100vh;
        display: grid;
        grid-template-columns: 1fr;
        background: var(--navy-deep);
        color: var(--white);
        font-family: 'Hanken Grotesk', system-ui, sans-serif;
    }
    @media (min-width: 960px) { .cx-login { grid-template-columns: 520px 1fr; } }

    .cx-slab { background: var(--navy); display: flex; flex-direction: column; padding: 28px 24px; min-height: 100vh; }
    @media (min-width: 640px) { .cx-slab { padding: 48px 56px; } }

    .cx-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; }
    .cx-brand-mark { width: 44px; height: 44px; border-radius: 12px; background: var(--white); display: grid; place-items: center; flex-shrink: 0; }
    .cx-brand-mark img { width: 30px; height: 30px; object-fit: contain; }
    .cx-brand-name { font-family: 'Bricolage Grotesque', system-ui, sans-serif; font-weight: 600; font-size: 18px; line-height: 1.2; }
    .cx-brand-sub { font-size: 13px; color: var(--blue-300); }

    .cx-body { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 48px 0; max-width: 410px; }
    .cx-title { font-family: 'Bricolage Grotesque', system-ui, sans-serif; font-weight: 600; font-size: clamp(34px, 4vw, 44px); line-height: 1.04; letter-spacing: -0.01em; margin: 0; }
    .cx-lede { font-size: 16px; color: var(--blue-200); margin: 14px 0 0; line-height: 1.5; }

    .cx-form { margin-top: 32px; display: grid; gap: 18px; }
    .cx-label-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
    .cx-label { font-size: 14px; color: var(--blue-200); }
    .cx-label-row a { font-size: 14px; color: var(--white); font-weight: 600; text-decoration: underline; text-underline-offset: 4px; text-decoration-color: var(--blue-300); }
    .cx-input-wrap { position: relative; }
    .cx-input {
        width: 100%; height: 48px; border-radius: 8px;
        background: var(--navy-deep); border: 1px solid var(--navy-line);
        color: var(--white); font: inherit; font-size: 15px; padding: 0 16px;
        outline: none; transition: border-color 150ms ease, box-shadow 150ms ease;
    }
    .cx-input::placeholder { color: rgba(169, 197, 225, 0.6); }
    .cx-input:focus { border-color: var(--blue-300); box-shadow: 0 0 0 3px rgba(141, 182, 222, 0.25); }
    .cx-input.is-invalid { border-color: var(--rose); }
    [data-password-field] .cx-input { padding-right: 48px; }
    .cx-toggle { position: absolute; right: 6px; top: 6px; width: 36px; height: 36px; border: 0; background: transparent; color: var(--blue-300); border-radius: 6px; display: grid; place-items: center; cursor: pointer; }
    .cx-toggle:focus-visible { outline: 2px solid var(--blue-300); }
    .cx-toggle .kv-slash { display: none; }
    [data-password-field].is-revealed .cx-toggle .kv-slash { display: inline; }
    .cx-error { margin: 6px 0 0; font-size: 13px; color: #FBDADA; }

    .cx-check { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--blue-200); cursor: pointer; }
    .cx-check input { width: 18px; height: 18px; accent-color: var(--blue); }

    .cx-submit {
        margin-top: 6px; height: 48px; border: 0; border-radius: 8px;
        background: var(--white); color: var(--navy); font: inherit; font-size: 16px; font-weight: 600;
        cursor: pointer; transition: background 150ms ease;
    }
    .cx-submit:hover { background: #EAF1F8; }
    .cx-submit:focus-visible { outline: 3px solid var(--blue-300); outline-offset: 2px; }
    .cx-submit[disabled] { opacity: 0.7; cursor: progress; }

    .cx-foot { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; color: var(--blue-300); }
    .cx-foot a { color: inherit; text-decoration: none; }
    .cx-foot a:hover { color: var(--white); }
    .cx-foot nav { display: flex; gap: 16px; }

    .cx-photo { display: none; position: relative; background: var(--navy-deep) center / cover no-repeat; }
    @media (min-width: 960px) { .cx-photo { display: block; } }
    .cx-photo-caption { position: absolute; right: 40px; bottom: 40px; max-width: 300px; background: rgba(15, 36, 56, 0.85); border-radius: 8px; padding: 12px 16px; font-size: 14px; }
    .cx-photo-caption strong { display: block; font-weight: 600; }
    .cx-photo-caption span { color: var(--blue-200); }

    @media (prefers-reduced-motion: reduce) { .cx-input, .cx-submit { transition: none; } }

    /* Shared by forgot / reset / activate. */
    .cx-back { display: inline-flex; align-items: center; gap: 6px; margin-top: 22px; font-size: 14px; font-weight: 600; color: var(--white); text-decoration: underline; text-underline-offset: 4px; text-decoration-color: var(--blue-300); background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit; }
    .cx-hint { margin: 6px 0 0; font-size: 13px; color: var(--blue-300); }
    .cx-meta { margin: 14px 0 0; font-size: 14px; color: var(--blue-200); }
    .cx-meta strong { color: var(--white); font-weight: 600; }
    .cx-sent { margin-top: 28px; display: flex; gap: 14px; align-items: flex-start; padding: 16px; border-radius: 8px; background: var(--navy-deep); border: 1px solid var(--navy-line); font-size: 15px; line-height: 1.5; color: var(--blue-200); }
    .cx-sent svg { flex-shrink: 0; color: var(--blue-300); margin-top: 2px; }
    .cx-sent strong { color: var(--white); font-weight: 600; word-break: break-all; }
</style>
