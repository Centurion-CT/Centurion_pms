{{-- Dashboard home (approved mockup 3A, "Daylight"). Scoped to .cx-home. --}}
<style>
    .lp-page .lp-dash.cx-home { padding: 24px 32px 56px; gap: 24px; max-width: 1280px; }
    @media (max-width: 768px) { .lp-page .lp-dash.cx-home { padding: 16px 16px 40px; gap: 16px; } }

    .cx-home .cx-card { background: var(--color-card); border: 1px solid var(--color-line); border-radius: 12px; }
    .cx-home .cx-h2 { font-family: var(--font-display); font-weight: 600; font-size: 18px; line-height: 1.2; color: var(--color-ink); margin: 0; }

    /* Greeting + quick actions. */
    .cx-home .cx-dh { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
    .cx-home .cx-dh-date { margin: 0; font-size: 13px; color: var(--color-ink-mute); }
    .cx-home .cx-dh-title { margin: 4px 0 0; font-family: var(--font-display); font-weight: 600; font-size: clamp(24px, 2.6vw, 30px); line-height: 1.1; color: var(--color-ink); }
    .cx-home .cx-dh-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .cx-home .cx-dh-actions .lp-pill { height: 40px; border-radius: 8px; padding: 0 14px; font-size: 14px; border-color: var(--color-line); background: var(--color-card); }
    .cx-home .cx-dh-actions .lp-pill:hover { background: var(--color-paper); }

    /* Tonight's house. */
    .cx-home .cx-th { padding: 24px; display: grid; gap: 24px; }
    @media (min-width: 1100px) { .cx-home .cx-th { grid-template-columns: 250px minmax(0, 1fr); align-items: end; gap: 40px; } }
    .cx-home .cx-th-title { font-family: var(--font-display); font-weight: 600; font-size: 20px; margin: 0; color: var(--color-ink); }
    .cx-home .cx-th-lede { margin: 4px 0 0; font-size: 14px; line-height: 1.5; color: var(--color-ink-soft); }
    .cx-home .cx-th-big { display: flex; align-items: baseline; gap: 8px; margin-top: 20px; }
    .cx-home .cx-th-pct { font-family: var(--font-display); font-weight: 600; font-size: 56px; line-height: 1; color: var(--color-ink); font-variant-numeric: tabular-nums; }
    .cx-home .cx-th-pct-label { font-size: 14px; color: var(--color-ink-soft); }
    .cx-home .cx-th-sub { margin: 4px 0 0; font-size: 13px; color: var(--color-ink-mute); }
    .cx-home .cx-th-chart { min-width: 0; overflow-x: auto; }
    .cx-home .cx-th-groups { display: flex; gap: 12px; min-width: min-content; }
    .cx-home .cx-th-group { min-width: 0; display: flex; flex-direction: column; }
    .cx-home .cx-th-bars { height: 150px; display: flex; align-items: flex-end; gap: 6px; }
    .cx-home .cx-th-bar { flex: 1 1 0; min-width: 6px; border-radius: 4px 4px 2px 2px; }
    .cx-home .cx-th-group-label { margin-top: 8px; padding-top: 6px; border-top: 1px solid var(--color-line); font-size: 12px; color: var(--color-ink-mute); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cx-home .cx-th-legend { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-top: 16px; font-size: 13px; color: var(--color-ink-soft); }
    .cx-home .cx-th-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .cx-home .cx-sw { width: 10px; height: 10px; border-radius: 2px; flex-shrink: 0; }
    .cx-home .is-inhouse  { background: #16324B; }
    .cx-home .is-arriving { background: #5F8FBF; }
    .cx-home .is-booked   { background: #A9C5E1; }
    .cx-home .is-leaving  { background: #E3A83A; }
    .cx-home .is-overdue  { background: #C2414B; }
    .cx-home .is-dirty    { background-image: repeating-linear-gradient(135deg, #E3A83A 0 3px, #F6E3BC 3px 7px); }
    .cx-home .is-blocked  { background-image: repeating-linear-gradient(135deg, #8A96A3 0 3px, #D5DBE2 3px 7px); }
    .cx-home .is-free     { background: #E3E9EF; }

    /* Main grid: arrivals table + right rail. */
    .cx-home .cx-main-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; align-items: start; }
    .cx-home .cx-main-grid > *, .cx-home .cx-bottom-grid > * { min-width: 0; }
    @media (min-width: 1100px) { .cx-home .cx-main-grid { grid-template-columns: minmax(0, 1fr) 340px; } }
    .cx-home .cx-rail { display: grid; gap: 24px; }

    /* Arrivals and departures. */
    .cx-home .cx-ad-head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 20px 24px 14px; }
    .cx-home .cx-tabs { margin-left: auto; flex-wrap: wrap; max-width: 100%; display: flex; gap: 2px; padding: 4px; border-radius: 8px; background: var(--color-paper); }
    .cx-home .cx-tab { border: 0; background: transparent; padding: 5px 12px; border-radius: 6px; font: inherit; font-size: 13px; font-weight: 500; color: var(--color-ink-soft); cursor: pointer; white-space: nowrap; }
    .cx-home .cx-tab[aria-selected="true"] { background: var(--color-card); color: var(--color-ink); box-shadow: 0 1px 2px rgba(26, 36, 48, 0.08); }
    .cx-home .cx-tab:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 1px; }
    .cx-home .cx-ad-scroll { overflow-x: auto; position: relative; }
    .cx-home .cx-ad-table { width: 100%; border-collapse: collapse; font-size: 14px; color: var(--color-ink); }
    .cx-home .cx-ad-table th { text-align: left; font-size: 12px; font-weight: 500; color: var(--color-ink-mute); padding: 10px 12px; border-top: 1px solid var(--color-line); border-bottom: 1px solid var(--color-line); white-space: nowrap; }
    .cx-home .cx-ad-table td { padding: 12px; border-bottom: 1px solid var(--color-line-soft); vertical-align: middle; }
    .cx-home .cx-ad-table th:first-child, .cx-home .cx-ad-table td:first-child { padding-left: 24px; }
    .cx-home .cx-ad-table th:last-child, .cx-home .cx-ad-table td:last-child { padding-right: 24px; text-align: right; }
    .cx-home .cx-ad-table .is-num { text-align: right; white-space: nowrap; }
    .cx-home .cx-ad-guest { display: block; padding: 0; border: 0; background: transparent; font: inherit; text-align: left; cursor: pointer; color: inherit; }
    .cx-home .cx-ad-guest strong { display: block; font-weight: 600; }
    .cx-home .cx-ad-guest:hover strong { color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px; }
    .cx-home .cx-ad-sub { display: block; margin-top: 2px; font-size: 12px; color: var(--color-ink-mute); }
    .cx-home .cx-ad-muted { color: var(--color-ink-mute); }
    .cx-home .cx-due { color: #B4323C; font-weight: 600; }
    .cx-home .cx-ad-actions { display: inline-flex; gap: 6px; justify-content: flex-end; white-space: nowrap; }
    .cx-home .cx-btn-sm { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 12px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid var(--color-line); background: var(--color-card); color: var(--color-ink); white-space: nowrap; }
    .cx-home .cx-btn-sm:hover { background: var(--color-paper); }
    .cx-home .cx-btn-sm--primary { background: var(--color-accent); border-color: var(--color-accent); color: #FFFFFF; }
    .cx-home .cx-btn-sm--primary:hover { background: var(--color-accent-deep); }
    .cx-home .cx-btn-sm[disabled] { opacity: 0.6; cursor: progress; }
    @media (max-width: 640px) {
        .cx-home .cx-ad-head { padding: 16px 16px 12px; }
        .cx-home .cx-tabs { margin-left: 0; }
        .cx-home .cx-ad-table th:nth-child(3), .cx-home .cx-ad-table td:nth-child(3) { display: none; }
        .cx-home .cx-ad-table td:nth-child(2) .cx-ad-muted { display: none; }
        .cx-home .cx-ad-table th:first-child, .cx-home .cx-ad-table td:first-child { padding-left: 16px; }
        .cx-home .cx-ad-table th:last-child, .cx-home .cx-ad-table td:last-child { padding-right: 16px; }
        .cx-home .cx-ad-foot { padding: 12px 16px; }
        .cx-home .cx-th { padding: 18px; }
        .cx-home .cx-th-pct { font-size: 46px; }
    }
    .cx-home .cx-ad-empty { padding: 28px 24px; font-size: 14px; color: var(--color-ink-mute); }
    .cx-home .cx-ad-foot { display: flex; justify-content: space-between; gap: 12px; padding: 12px 24px; font-size: 13px; }
    .cx-home .cx-ad-foot a { color: var(--color-accent-deep); font-weight: 600; text-decoration: none; }
    .cx-home .cx-ad-foot a:hover { text-decoration: underline; text-underline-offset: 3px; }

    /* Collected today (navy). */
    .cx-home .cx-collected { background: var(--color-ink-deep); color: #FFFFFF; border-radius: 12px; padding: 20px; }
    .cx-home .cx-collected-label { margin: 0; font-size: 13px; color: #A9C5E1; }
    .cx-home .cx-collected-amount { margin: 6px 0 0; font-family: var(--font-display); font-weight: 600; font-size: 34px; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .cx-home .cx-collected-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 16px; }
    .cx-home .cx-collected-tile { display: block; padding: 10px 12px; border: 0; border-radius: 8px; background: rgba(255, 255, 255, 0.08); color: inherit; font: inherit; text-align: left; text-decoration: none; }
    .cx-home button.cx-collected-tile { cursor: pointer; }
    .cx-home .cx-collected-tile:hover { background: rgba(255, 255, 255, 0.14); }
    .cx-home .cx-collected-tile:focus-visible { outline: 2px solid #8DB6DE; outline-offset: 2px; }
    .cx-home .cx-collected-tile small { display: block; font-size: 12.5px; color: #A9C5E1; }
    .cx-home .cx-collected-tile strong { display: block; margin-top: 2px; font-size: 15px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .cx-home .cx-collected-tile .is-owed { color: #E3A83A; }

    /* Needs attention. */
    .cx-home .cx-na { padding: 20px; }
    .cx-home .cx-na-list { list-style: none; margin: 14px 0 0; padding: 0; display: grid; gap: 4px; }
    .cx-home .cx-na-row a { display: flex; gap: 12px; align-items: flex-start; padding: 8px; margin: 0 -8px; border-radius: 8px; color: var(--color-ink); text-decoration: none; }
    .cx-home .cx-na-row a:hover { background: var(--color-paper); }
    .cx-home .cx-na-icon { width: 28px; height: 28px; border-radius: 7px; display: grid; place-items: center; flex-shrink: 0; }
    .cx-home .cx-na-icon svg { width: 16px; height: 16px; }
    .cx-home .cx-na-icon.is-critical { background: #FBEAEC; color: #B4323C; }
    .cx-home .cx-na-icon.is-attention { background: #FFF3DD; color: #A8650A; }
    .cx-home .cx-na-icon.is-info { background: #EAF1F8; color: #285480; }
    .cx-home .cx-na-title { display: block; font-size: 14px; font-weight: 600; line-height: 1.35; }
    .cx-home .cx-na-sub { display: block; margin-top: 1px; font-size: 13px; color: var(--color-ink-mute); }
    .cx-home .cx-na-none { margin: 10px 0 0; font-size: 14px; color: var(--color-ink-mute); }

    /* Revenue this month. */
    .cx-home .cx-rev { padding: 22px 24px; }
    .cx-home .cx-rev-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; }
    .cx-home .cx-rev-meta { font-size: 14px; color: var(--color-ink-soft); }
    .cx-home .cx-rev-month { margin-left: auto; font-size: 13px; color: var(--color-ink-mute); }
    .cx-home .cx-rev-bars { height: 120px; display: flex; align-items: flex-end; gap: 3px; margin-top: 22px; }
    @media (min-width: 900px) { .cx-home .cx-rev-bars { gap: 6px; } }
    .cx-home .cx-rev-bar { flex: 1 1 0; min-width: 2px; border-radius: 3px 3px 1px 1px; background: #A9C5E1; }
    .cx-home .cx-rev-bar.is-today { background: var(--color-accent); }
    .cx-home .cx-rev-bar.is-future { background: var(--color-line); }
    .cx-home .cx-rev-axis { display: flex; justify-content: space-between; margin-top: 8px; font-size: 12px; color: var(--color-ink-mute); }

    .cx-home .cx-bottom-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; align-items: start; }
    @media (min-width: 1024px) { .cx-home .cx-bottom-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
</style>
