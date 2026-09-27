<div class="lp-dash lp-pl-doc">
    {{-- Print-only masthead. Renders as the top block on the printed
         P&L so what an accountant / bank receives reads as a proper
         statement, property + period + basis + print date. --}}
    <div class="pl-print-masthead print-only" aria-hidden="true">
        @php
            $tenant = \App\Models\Tenant::current();
            $property = app(\App\Services\Properties\PropertyContext::class)->current();
        @endphp
        <div class="pl-print-masthead__brand">
            <span class="pl-print-masthead__property">
                {{ $property?->name ?? $tenant?->name ?? 'Property' }}
            </span>
            <span class="pl-print-masthead__doctype">Profit &amp; Loss statement</span>
        </div>
        <div class="pl-print-masthead__meta">
            <div>
                <span class="pl-print-masthead__label">Period</span>
                <span class="pl-print-masthead__value">{{ $rangeMeta['label'] }}</span>
            </div>
            <div>
                <span class="pl-print-masthead__label">Basis</span>
                <span class="pl-print-masthead__value">{{ $basis === 'cash' ? 'Cash' : 'Accrual' }}</span>
            </div>
            <div>
                <span class="pl-print-masthead__label">Prepared</span>
                <span class="pl-print-masthead__value">{{ now()->format('j M Y · g:i A') }}</span>
            </div>
            <div>
                <span class="pl-print-masthead__label">Currency</span>
                <span class="pl-print-masthead__value">NGN</span>
            </div>
        </div>
    </div>

    <div class="no-print">
        @include('_partials._back-button')

        {{-- ── Hero: title + Print / Export actions ────────
             Right side hosts the current window as a Fraunces
             label + basis mode. The KPI/Net card below carries
             the actual number. --}}
        <section class="lp-res-idx-hero mt-3" data-testid="pl-hero">
            <div class="lp-res-idx-hero-body">
                <div class="lp-res-idx-hero-identity">
                    <span class="lp-eyebrow-strip">Accounting · Profit &amp; Loss</span>
                    <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                        style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                        Profit &amp; Loss
                    </h1>
                    <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                        Revenue minus expenses over the selected window. Print for banks and accountants, or export the raw CSV.
                    </p>
                    <div class="lp-res-idx-hero-actions">
                        <button type="button"
                                onclick="window.print()"
                                data-testid="pl-print"
                                class="lp-res-action lp-res-action--primary">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 6V3h8v3M4 11H2v-4a1 1 0 011-1h10a1 1 0 011 1v4h-2M4 10h8v4H4z"/></svg>
                            Print
                        </button>
                        <a href="{{ route('tenant.accounting.profit-loss.export', request()->query()) }}"
                           data-testid="pl-export"
                           class="lp-res-action">
                            Export CSV
                        </a>
                    </div>
                </div>

                <aside class="lp-res-idx-hero-money" aria-label="Statement window">
                    <div class="lp-ih-hero-accrued">
                        <p class="lp-ih-hero-accrued-label">Window</p>
                        <p class="lp-display" style="font-size: clamp(17px, 2vw, 20px); font-weight: 600; color: var(--color-ink); letter-spacing: -0.005em; line-height: 1.15; margin: 4px 0 0;">
                            {{ $rangeMeta['label'] }}
                        </p>
                        <p class="lp-ih-hero-accrued-sub">
                            {{ $basis === 'cash' ? 'Cash basis · money received' : 'Accrual basis · booked' }}
                        </p>
                    </div>
                </aside>
            </div>
        </section>
    </div>

    {{-- ── Filter row: range + basis + branch scope ────────────── --}}
    <section class="lp-dash-card no-print" data-testid="pl-filters"
             style="padding: 14px 16px; display: grid; gap: 12px;">
        <div class="lp-chip-group">
            @foreach ([
                'today' => 'Today', 'week' => 'This week',
                'month' => 'This month', 'year' => 'This year', 'custom' => 'Custom',
            ] as $k => $label)
                <button type="button" wire:click="pickRange('{{ $k }}')"
                        data-testid="pl-range-{{ $k }}"
                        class="lp-chip @if ($range === $k) is-active @endif">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        @if ($range === 'custom')
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="lp-form-label">From</label>
                    <input type="date" wire:model.live="customFrom" class="lp-input"
                           data-testid="pl-custom-from">
                </div>
                <div>
                    <label class="lp-form-label">To</label>
                    <input type="date" wire:model.live="customTo" class="lp-input"
                           data-testid="pl-custom-to">
                </div>
            </div>
        @endif
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="lp-form-label">Basis</label>
                <div class="lp-chip-group">
                    <button type="button" wire:click="toggleBasis('cash')"
                            data-testid="pl-basis-cash"
                            class="lp-chip @if ($basis === 'cash') is-active @endif">
                        Cash · money received
                    </button>
                    <button type="button" wire:click="toggleBasis('accrual')"
                            data-testid="pl-basis-accrual"
                            class="lp-chip @if ($basis === 'accrual') is-active @endif">
                        Accrual · booked
                    </button>
                </div>
            </div>
            <div>
                <label class="lp-form-label">Branch scope</label>
                <select wire:model.live="filterBranch" class="lp-input"
                        data-testid="pl-branch">
                    <option value="current">This branch + HQ</option>
                    <option value="hq">HQ only</option>
                    <option value="all">All branches</option>
                </select>
            </div>
        </div>
    </section>

    {{-- ── Bottom-line summary ─────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="pl-summary"
             style="padding: 22px; display: grid; gap: 18px;
                    grid-template-columns: 1fr; align-items: start;">
        <div class="grid gap-4 sm:grid-cols-3" style="align-items: baseline;">
            <div>
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                    Net (period)
                </p>
                <p class="lp-num-lg mt-1"
                   style="font-size: 32px; letter-spacing: -0.02em;
                          color: {{ $net >= 0 ? 'var(--color-ink)' : '#B85C42' }};">
                    ₦{{ number_format($net) }}
                </p>
                @if (! is_null($netDelta))
                    <p class="text-[12.5px] mt-1"
                       style="color: {{ $netDelta >= 0 ? '#166534' : '#B85C42' }};">
                        {{ $netDelta >= 0 ? '↑' : '↓' }}
                        {{ abs($netDelta) }}%
                        <span style="color: var(--color-ink-mute); margin-left: 4px;">
                            vs previous · ₦{{ number_format($prevNet) }}
                        </span>
                    </p>
                @endif
            </div>
            <div>
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                    Margin
                </p>
                <p class="lp-num-lg mt-1"
                   style="font-size: 24px; color: {{ $margin !== null && $margin >= 0 ? 'var(--color-ink)' : '#B85C42' }};">
                    @if ($margin === null)
                        -
                    @else
                        {{ $margin }}%
                    @endif
                </p>
                <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                    Net ÷ revenue
                </p>
            </div>
            <div>
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                    Revenue vs Expenses
                </p>
                @php
                    $sum = max(1, $revenue['net'] + $expenses['total']);
                    $revShare = round(($revenue['net'] / $sum) * 100);
                    $expShare = 100 - $revShare;
                @endphp
                <div style="height: 10px; background: var(--color-card-deep);
                            border-radius: 999px; overflow: hidden;
                            display: flex; margin-top: 8px;">
                    <span style="width: {{ $revShare }}%; background: #166534;" title="Revenue"></span>
                    <span style="width: {{ $expShare }}%; background: #B85C42;" title="Expenses"></span>
                </div>
                <p class="text-[11.5px] mt-2" style="color: var(--color-ink-soft);">
                    ₦{{ number_format($revenue['net']) }} in ·
                    ₦{{ number_format($expenses['total']) }} out
                </p>
            </div>
        </div>
    </section>

    {{-- ── Revenue + Expenses breakdown side-by-side ─────────── --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <section class="lp-dash-card" data-testid="pl-revenue-breakdown">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Revenue</span>
                <span class="lp-dash-card-meta">
                    ₦{{ number_format($revenue['net']) }} net
                </span>
            </div>
            <table class="lp-table">
                <tbody>
                    <tr>
                        <td>Rooms</td>
                        <td class="lp-num" style="text-align: right;">
                            ₦{{ number_format($revenue['rooms']) }}
                        </td>
                    </tr>
                    <tr>
                        <td>POS</td>
                        <td class="lp-num" style="text-align: right;">
                            ₦{{ number_format($revenue['pos']) }}
                        </td>
                    </tr>
                    @if ($revenue['extras'] > 0)
                        <tr>
                            <td>Extras (extension, F&amp;B, damage)</td>
                            <td class="lp-num" style="text-align: right;">
                                ₦{{ number_format($revenue['extras']) }}
                            </td>
                        </tr>
                    @endif
                    <tr style="border-top: 2px solid var(--color-line);">
                        <td style="font-weight: 600;">Gross</td>
                        <td class="lp-num" style="text-align: right; font-weight: 600;">
                            ₦{{ number_format($revenue['gross']) }}
                        </td>
                    </tr>
                    @if ($revenue['refunded'] > 0)
                        <tr>
                            <td style="color: #B85C42;">Less refunds</td>
                            <td class="lp-num" style="text-align: right; color: #B85C42;">
                                −₦{{ number_format($revenue['refunded']) }}
                            </td>
                        </tr>
                    @endif
                    <tr style="border-top: 1px solid var(--color-line);">
                        <td style="font-weight: 600;">Net revenue</td>
                        <td class="lp-num" style="text-align: right; font-weight: 600;">
                            ₦{{ number_format($revenue['net']) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="lp-dash-card" data-testid="pl-expenses-breakdown">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Expenses</span>
                <span class="lp-dash-card-meta">
                    ₦{{ number_format($expenses['total']) }} out
                </span>
            </div>
            @if ($expenses['by_category']->isEmpty())
                <p class="text-[13.5px]" style="color: var(--color-ink-mute); padding: 16px 0;">
                    No expenses logged in this range.
                    <a href="{{ route('tenant.accounting.expenses.index') }}" wire:navigate
                       style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                        Log one →
                    </a>
                </p>
            @else
                <ul style="list-style: none; padding: 0; margin: 0;
                           display: flex; flex-direction: column; gap: 10px;">
                    @php $maxCat = max(1, $expenses['by_category']->max('total')); @endphp
                    @foreach ($expenses['by_category'] as $cat)
                        <li>
                            <div class="flex justify-between text-[13px]" style="align-items: baseline;">
                                <span style="color: var(--color-ink); font-weight: 500;">
                                    {{ $cat['name'] }}
                                </span>
                                <span class="lp-mono" style="color: var(--color-ink);">
                                    ₦{{ number_format($cat['total']) }}
                                </span>
                            </div>
                            <div style="height: 6px; background: var(--color-card-deep);
                                        border-radius: 999px; margin-top: 4px; overflow: hidden;">
                                <div style="width: {{ ($cat['total'] / $maxCat) * 100 }}%;
                                            height: 100%;
                                            background: {{ $cat['color'] ?: 'var(--color-ink)' }};"></div>
                            </div>
                            <p class="text-[11px]" style="color: var(--color-ink-mute); margin-top: 3px;">
                                {{ $cat['count'] }} {{ Str::plural('entry', $cat['count']) }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- ── Monthly trend: revenue vs expenses bars ─────────────── --}}
    @if ($monthly->count() >= 1)
        <section class="lp-dash-card" data-testid="pl-monthly-trend">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Month over month</span>
                <span class="lp-dash-card-meta">Revenue vs expenses</span>
            </div>
            <div style="display: flex; align-items: flex-end; gap: 12px;
                        height: 220px; padding: 12px 0;">
                @foreach ($monthly as $m)
                    @php
                        $revH = round(($m['revenue'] / $maxAbs) * 100);
                        $expH = round(($m['expenses'] / $maxAbs) * 100);
                    @endphp
                    <div style="flex: 1; display: flex; flex-direction: column;
                                align-items: center; gap: 6px; min-width: 0;">
                        <div style="width: 100%; display: flex; gap: 4px;
                                    height: 180px; align-items: flex-end;">
                            <div title="Revenue ₦{{ number_format($m['revenue']) }}"
                                 style="flex: 1; background: #166534; border-radius: 3px 3px 0 0;
                                        height: {{ $revH }}%; min-height: 2px;"></div>
                            <div title="Expenses ₦{{ number_format($m['expenses']) }}"
                                 style="flex: 1; background: #B85C42; border-radius: 3px 3px 0 0;
                                        height: {{ $expH }}%; min-height: 2px;"></div>
                        </div>
                        <div class="lp-mono text-[10px]"
                             style="color: var(--color-ink-mute); text-transform: uppercase;
                                    letter-spacing: 0.08em;">
                            {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m['month'])->format('M y') }}
                        </div>
                        <div class="lp-mono text-[10.5px]"
                             style="color: {{ $m['net'] >= 0 ? '#166534' : '#B85C42' }};
                                    font-weight: 600;">
                            {{ $m['net'] >= 0 ? '+' : '−' }}₦{{ number_format(abs($m['net'])) }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-4 text-[11.5px] mt-2"
                 style="color: var(--color-ink-soft);">
                <span style="display: inline-flex; align-items: center; gap: 6px;">
                    <span style="width: 10px; height: 10px; background: #166534; border-radius: 2px;"></span>
                    Revenue
                </span>
                <span style="display: inline-flex; align-items: center; gap: 6px;">
                    <span style="width: 10px; height: 10px; background: #B85C42; border-radius: 2px;"></span>
                    Expenses
                </span>
            </div>
        </section>
    @endif

    {{-- Print-only footer. Repeats the property + period on the last
         page so a physically-separated sheet is still identifiable. --}}
    <div class="pl-print-footer print-only" aria-hidden="true">
        {{ $property?->name ?? $tenant?->name ?? 'Property' }} &middot;
        Profit &amp; Loss &middot; {{ $rangeMeta['label'] }} &middot;
        {{ $basis === 'cash' ? 'Cash basis' : 'Accrual basis' }} &middot;
        Prepared {{ now()->format('j M Y') }}
    </div>

    <style>
        /* Print-only elements are hidden on screen by default. */
        .lp-page .print-only { display: none; }

        @media print {
            /* Only the P&L doc paints on paper. Sidebar / topbar /
               notification bell / navigation are all hidden via
               .no-print on their wrappers (dashboard shell tags them). */
            body, .lp-page, .lp-page .lp-shell-dash, .lp-page .lp-main {
                background: #fff !important;
                color: #000 !important;
            }
            .lp-page .lp-sidebar,
            .lp-page .lp-topbar,
            .lp-page .lp-nav-spinner,
            .lp-page .no-print {
                display: none !important;
            }

            /* Show print-only chrome. */
            .lp-page .print-only { display: block !important; }

            /* Trim the shell so the doc fills the printable area. */
            .lp-page .lp-dash.lp-pl-doc {
                padding: 0 !important;
                max-width: none !important;
            }

            /* Cards drop shadow + card chrome, they read as sections in
               a printed statement, not floating cards on a page. */
            .lp-page .lp-pl-doc .lp-dash-card {
                background: transparent !important;
                border: 0 !important;
                box-shadow: none !important;
                padding: 12px 0 !important;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .lp-page .lp-pl-doc [data-testid="pl-revenue-breakdown"],
            .lp-page .lp-pl-doc [data-testid="pl-expenses-breakdown"],
            .lp-page .lp-pl-doc [data-testid="pl-monthly-trend"] {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .lp-page .lp-pl-doc [data-testid="pl-monthly-trend"] {
                page-break-before: auto;
            }

            /* Tables read tight on paper. */
            .lp-page .lp-pl-doc table {
                width: 100% !important;
                border-collapse: collapse;
            }
            .lp-page .lp-pl-doc td,
            .lp-page .lp-pl-doc th {
                border-bottom: 1px solid rgba(0,0,0,0.14) !important;
                padding: 6px 8px !important;
                color: #000 !important;
                background: transparent !important;
            }

            /* Room for a real running header on every printed page. */
            @page {
                margin: 18mm 14mm 22mm 14mm;
            }
        }

        /* Print masthead + footer chrome. Not in the print @media
           because we still want to see them if an operator uses the
           browser's Print Preview to check the layout. */
        .lp-page .pl-print-masthead {
            padding: 20px 0 16px;
            border-bottom: 2px solid #000;
            margin-bottom: 20px;
        }
        .lp-page .pl-print-masthead__brand {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .lp-page .pl-print-masthead__property {
            font-family: 'Fraunces', Georgia, serif;
            font-weight: 600;
            font-size: 22px;
            letter-spacing: -0.014em;
            color: #000;
        }
        .lp-page .pl-print-masthead__doctype {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 11px;
            letter-spacing: 0.20em;
            text-transform: uppercase;
            color: #333;
        }
        .lp-page .pl-print-masthead__meta {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px 24px;
            margin-top: 14px;
        }
        .lp-page .pl-print-masthead__label {
            display: block;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 9.5px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #555;
        }
        .lp-page .pl-print-masthead__value {
            display: block;
            font-size: 12.5px;
            color: #000;
            margin-top: 2px;
        }
        .lp-page .pl-print-footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid rgba(0,0,0,0.2);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 10px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #555;
        }
    </style>
</div>
