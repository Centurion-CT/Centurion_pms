@php
    $horizons = [30, 60, 90];
    // Query string for the export link so the CSV honours the current
    // preset / custom range picker exactly.
    $exportQs = http_build_query(array_filter([
        'preset'  => $preset,
        'from'    => $preset === 'custom' ? $customFrom : null,
        'to'      => $preset === 'custom' ? $customTo : null,
        'horizon' => $forecastHorizon,
    ]));
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + Export CSV + window side panel ────
         Editorial hero, right column is the current window as
         a Fraunces "from → to" label + day count. Range picker
         and forecast horizon sit in the controls row below. --}}
    @php
        $financeRangeDays = (int) ($from->diffInDays($to, absolute: true) + 1);
    @endphp
    <section class="lp-res-idx-hero" data-testid="finance-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Finance
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Revenue, transactions, channel performance and a rough forecast in one screen for managers and finance.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.finance.export').'?'.$exportQs }}"
                       data-testid="finance-export"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M8 2v9M4 7l4 4 4-4M2 13h12"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Finance window">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Window</p>
                    <p class="lp-display" style="font-size: clamp(18px, 2.2vw, 22px); font-weight: 600; color: var(--color-ink); letter-spacing: -0.005em; line-height: 1.15; margin: 4px 0 0;">
                        {{ $from->format('j M') }} → {{ $to->format('j M Y') }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        {{ $financeRangeDays }} {{ Str::plural('day', $financeRangeDays) }}
                        · forecast {{ $forecastHorizon }}d ahead
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Range + forecast horizon ─────────────────────── --}}
    <section class="lp-dash-card lp-reports-controls" style="padding: 12px 16px;">
        <div class="lp-reports-range" data-testid="finance-range">
            <label for="finance-preset" class="lp-rate-sub-label" style="margin-bottom: 0;">Range</label>
            <select id="finance-preset" wire:model.live="preset" class="lp-input"
                    data-testid="finance-preset"
                    style="font-size: 12.5px; padding: 6px 28px 6px 12px; width: auto; height: 34px;">
                @foreach ($presets as $p)
                    <option value="{{ $p['key'] }}">{{ $p['label'] }}</option>
                @endforeach
            </select>
            @if ($preset === 'custom')
                <input type="date" wire:model.live="customFrom"
                       data-testid="finance-from" class="lp-input"
                       style="font-size: 12.5px; padding: 6px 10px; width: auto; height: 34px;">
                <input type="date" wire:model.live="customTo"
                       data-testid="finance-to" class="lp-input"
                       style="font-size: 12.5px; padding: 6px 10px; width: auto; height: 34px;">
            @endif
        </div>
        <div class="lp-reports-range" data-testid="finance-horizon">
            <label class="lp-rate-sub-label" style="margin-bottom: 0;">Forecast</label>
            <div class="lp-tabstrip" role="tablist" style="padding: 2px;">
                @foreach ($horizons as $h)
                    <button type="button"
                            wire:click="setHorizon({{ $h }})"
                            role="tab"
                            aria-selected="{{ $forecastHorizon === $h ? 'true' : 'false' }}"
                            class="lp-tabstrip-btn {{ $forecastHorizon === $h ? 'is-active' : '' }}"
                            data-testid="finance-horizon-{{ $h }}"
                            style="font-size: 11.5px; padding: 4px 10px;">
                        {{ $h }}d
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Shared client-side breakdown panel. Any table row on this
         page can pop it open by dispatching `finance-breakdown-open`
         with an id that keys into the pre-hydrated payload. --}}
    @include('livewire.dashboard.finance._partials.breakdown-panel', ['breakdowns' => $breakdowns])

    {{-- KPI-level detail panel — server-side, loaded on click, so
         we don't pay the query cost until the operator actually
         opens it. Loader lives inside this partial. --}}
    @include('livewire.dashboard.finance._partials.kpi-panel', ['kpiPanel' => $kpiPanel])

    @include('livewire.dashboard.finance._partials.kpi-strip', ['k' => $kpis])
    @include('livewire.dashboard.finance._partials.revenue-chart', [
        'daily' => $daily,
        'forecast' => $forecast,
        'horizon' => $forecastHorizon,
    ])
    @include('livewire.dashboard.finance._partials.channel-table', ['channels' => $channels])

    @include('livewire.dashboard.finance._partials.pos-sales', [
        'posSummary' => $posSummary,
        'posByOutlet' => $posByOutlet,
        'posTopProducts' => $posTopProducts,
    ])

    @include('livewire.dashboard.finance._partials.cashflow-table', ['daily' => $daily])
    @include('livewire.dashboard.finance._partials.ledger', ['ledger' => $ledger])

    {{-- ── By category + top rooms (money-facing variant) ──────── --}}
    <section class="lp-dash-card" data-testid="finance-by-category">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">By category</span>
            <span class="lp-dash-card-meta">room types earning the most</span>
        </div>
        @if (empty($byCategory))
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No category-level revenue for this window.</p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Bookings</th>
                            <th>Nights</th>
                            <th style="text-align: right;">Revenue</th>
                            <th>Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byCategory as $row)
                            <tr data-testid="finance-category-row-{{ $row['id'] }}"
                                x-on:click="$dispatch('finance-breakdown-open', { id: 'category:{{ $row['id'] }}' })"
                                style="cursor: pointer;">
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $row['name'] }}</td>
                                <td style="color: var(--color-ink-soft);">{{ number_format($row['bookings']) }}</td>
                                <td style="color: var(--color-ink-soft);">{{ number_format($row['nights']) }}</td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">&#8358;{{ number_format($row['revenue']) }}</span>
                                </td>
                                <td>
                                    <div class="flex items-center gap-3" style="min-width: 140px;">
                                        <div style="flex: 1; height: 6px; background: var(--color-card-deep); border-radius: 3px; overflow: hidden;">
                                            <div style="width: {{ $row['share'] }}%; height: 100%; background: var(--color-accent-deep);"></div>
                                        </div>
                                        <span class="lp-mono text-[11px]" style="color: var(--color-ink-soft);">{{ $row['share'] }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="lp-dash-card" data-testid="finance-top-rooms">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Top rooms</span>
            <span class="lp-dash-card-meta">highest-earning individual units</span>
        </div>
        @if (empty($topRooms))
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No room-level revenue yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Bookings</th>
                            <th style="text-align: right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topRooms as $row)
                            <tr data-testid="finance-room-row-{{ $row['id'] }}"
                                x-on:click="$dispatch('finance-breakdown-open', { id: 'room:{{ $row['id'] }}' })"
                                style="cursor: pointer;">
                                <td style="color: var(--color-ink); font-weight: 500;">{{ $row['label'] }}</td>
                                <td style="color: var(--color-ink-soft);">{{ number_format($row['bookings']) }}</td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">&#8358;{{ number_format($row['revenue']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
