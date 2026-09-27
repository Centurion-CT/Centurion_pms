<div class="lp-dash">
    {{-- ── Hero: title + branch scope note ────────────────
         Identity block on the left; right side hosts a brief
         "3 modules live" tile so the operator sees the shape
         of the surface at a glance. --}}
    <section class="lp-res-idx-hero" data-testid="accounting-hub-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Accounting</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Money in, money out.
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Books for the property. Start with Expenses. Revenue snapshots, P&amp;L, and payroll land here as they roll out. All figures respect the branch you have selected on the sidebar.
                </p>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Accounting modules">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Modules</p>
                    <p class="lp-ih-hero-accrued-value" style="color: var(--color-ink);">
                        3
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        live · 1 in the roadmap
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- App tiles - first app is Expenses. Follow this pattern when
         adding future modules (Revenue, P&L, Payroll). --}}
    <section class="lp-dash-card" style="padding: 0;">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" style="padding: 20px;">
            @foreach ([
                [
                    'route' => 'tenant.accounting.expenses.index',
                    'testid' => 'accounting-app-expenses',
                    'name' => 'Expenses',
                    'sub' => 'Log spending, group by category, and export reports.',
                    'icon' => 'M4 6h16v4H4zM4 14h10v6H4zM17 14v6M17 17h4',
                    'from' => '#F97316', 'to' => '#C2410C',
                ],
                [
                    'route' => 'tenant.accounting.revenue.index',
                    'testid' => 'accounting-app-revenue',
                    'name' => 'Revenue snapshot',
                    'sub' => 'Billed vs collected, per-channel, daily trend.',
                    'icon' => 'M4 20h16M4 20V8l4 4 4-8 4 6 4-4v14',
                    'from' => '#10B981', 'to' => '#047857',
                ],
                [
                    'route' => 'tenant.accounting.profit-loss.index',
                    'testid' => 'accounting-app-profit-loss',
                    'name' => 'Profit & loss',
                    'sub' => 'Revenue minus expenses, per month, cash or accrual basis.',
                    'icon' => 'M4 4v16h16M8 14l3-4 4 3 5-7',
                    'from' => '#3B82F6', 'to' => '#1D4ED8',
                ],
            ] as $liveApp)
                <a href="{{ route($liveApp['route']) }}" wire:navigate
                   data-testid="{{ $liveApp['testid'] }}"
                   style="display: flex; flex-direction: column; align-items: flex-start;
                          gap: 10px; padding: 20px;
                          background: var(--color-card); border: 1px solid var(--color-line-soft);
                          border-radius: 16px; text-decoration: none;
                          transition: transform 140ms, box-shadow 140ms;">
                    <span style="display: inline-flex; align-items: center; justify-content: center;
                                 width: 44px; height: 44px; border-radius: 12px;
                                 background: linear-gradient(135deg, {{ $liveApp['from'] }}, {{ $liveApp['to'] }});
                                 color: #fff;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="{{ $liveApp['icon'] }}"/>
                        </svg>
                    </span>
                    <div>
                        <p style="font-size: 15px; font-weight: 600; color: var(--color-ink); margin: 0;">
                            {{ $liveApp['name'] }}
                        </p>
                        <p class="mt-1 text-[12.5px]" style="color: var(--color-ink-mute); line-height: 1.5;">
                            {{ $liveApp['sub'] }}
                        </p>
                    </div>
                    <span class="lp-mono text-[11px]"
                          style="color: var(--color-accent-deep); letter-spacing: 0.08em;
                                 text-transform: uppercase; font-weight: 600; margin-top: auto;">
                        Open →
                    </span>
                </a>
            @endforeach

            {{-- Placeholder tiles for future apps - visible but disabled
                 so the operator sees the roadmap. Remove as each ships. --}}
            @foreach ([
                ['name' => 'Payroll', 'sub' => 'Staff run, tax, payslips.', 'icon' => 'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12H4zM8 10h8M8 14h5', 'from' => '#8B5CF6', 'to' => '#5B21B6'],
            ] as $app)
                <div style="display: flex; flex-direction: column; align-items: flex-start;
                            gap: 10px; padding: 20px;
                            background: var(--color-card); border: 1px dashed var(--color-line);
                            border-radius: 16px; opacity: 0.6;">
                    <span style="display: inline-flex; align-items: center; justify-content: center;
                                 width: 44px; height: 44px; border-radius: 12px;
                                 background: linear-gradient(135deg, {{ $app['from'] }}, {{ $app['to'] }});
                                 color: #fff;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="{{ $app['icon'] }}"/>
                        </svg>
                    </span>
                    <div>
                        <p style="font-size: 15px; font-weight: 600; color: var(--color-ink); margin: 0;">
                            {{ $app['name'] }}
                        </p>
                        <p class="mt-1 text-[12.5px]" style="color: var(--color-ink-mute); line-height: 1.5;">
                            {{ $app['sub'] }}
                        </p>
                    </div>
                    <span class="lp-mono text-[10.5px]"
                          style="color: var(--color-ink-mute); letter-spacing: 0.12em;
                                 text-transform: uppercase; margin-top: auto;">
                        Coming soon
                    </span>
                </div>
            @endforeach
        </div>
    </section>
</div>
