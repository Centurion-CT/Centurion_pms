@php
    $tabs = [
        ['key' => 'revenue',   'label' => 'Revenue'],
        ['key' => 'checkin',   'label' => 'Check-in'],
        ['key' => 'occupancy', 'label' => 'Occupancy'],
        ['key' => 'inventory', 'label' => 'Inventory'],
        ['key' => 'staff',     'label' => 'Staff activity'],
    ];
    $showRange = $tab !== 'inventory';
@endphp

<div class="lp-dash">

    {{-- ── Hero: title + range summary ──────────────────────
         Identity block on the left; right side carries the
         current window date range as an eyebrow + Fraunces
         label for a strong sense of "what am I looking at". --}}
    <section class="lp-res-idx-hero" data-testid="reports-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Operate</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Reports
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Revenue, check-in performance, occupancy, and inventory health in one screen. Numbers here are <strong style="color: var(--color-ink);">booked revenue</strong> (by check-in date, not cash). For till receipts, see Payments, Summary, or Finance.
                </p>
            </div>
            @if ($showRange)
                <aside class="lp-res-idx-hero-money" aria-label="Report window">
                    <div class="lp-ih-hero-accrued">
                        <p class="lp-ih-hero-accrued-label">Window</p>
                        <p class="lp-display" style="font-size: clamp(18px, 2.2vw, 22px); font-weight: 600; color: var(--color-ink); letter-spacing: -0.005em; line-height: 1.15; margin: 4px 0 0;">
                            {{ $from->format('j M') }} → {{ $to->format('j M Y') }}
                        </p>
                        <p class="lp-ih-hero-accrued-sub">
                            {{ $from->diffInDays($to) + 1 }} day{{ $from->diffInDays($to) === 0 ? '' : 's' }}
                        </p>
                    </div>
                </aside>
            @endif
        </div>
    </section>

    {{-- ── Tabs + date range ────────────────────────────── --}}
    <section class="lp-dash-card lp-reports-controls" style="padding: 12px 16px;">
        <div class="lp-tabstrip" data-testid="report-tabs" role="tablist">
            @foreach ($tabs as $t)
                <button type="button"
                        wire:click="setTab('{{ $t['key'] }}')"
                        role="tab"
                        aria-selected="{{ $tab === $t['key'] ? 'true' : 'false' }}"
                        class="lp-tabstrip-btn {{ $tab === $t['key'] ? 'is-active' : '' }}"
                        data-testid="report-tab-{{ $t['key'] }}">
                    {{ $t['label'] }}
                </button>
            @endforeach
        </div>

        @if ($showRange)
            <div class="lp-reports-range" data-testid="report-range">
                <label for="report-preset" class="lp-rate-sub-label" style="margin-bottom: 0;">Range</label>
                <select id="report-preset" wire:model.live="preset" class="lp-input"
                        data-testid="report-preset"
                        style="font-size: 12.5px; padding: 6px 28px 6px 12px; width: auto; height: 34px;">
                    @foreach ($presets as $p)
                        <option value="{{ $p['key'] }}">{{ $p['label'] }}</option>
                    @endforeach
                </select>
                @if ($preset === 'custom')
                    <input type="date" wire:model.live="customFrom"
                           data-testid="report-from" class="lp-input"
                           style="font-size: 12.5px; padding: 6px 10px; width: auto; height: 34px;">
                    <input type="date" wire:model.live="customTo"
                           data-testid="report-to" class="lp-input"
                           style="font-size: 12.5px; padding: 6px 10px; width: auto; height: 34px;">
                @endif
            </div>
        @endif
    </section>

    {{-- Shared click-a-row breakdown panel, rendered whenever the
         active tab publishes a `breakdowns` payload map (Revenue +
         Check-in for now). --}}
    @if (! empty($breakdowns ?? []))
        @include('livewire.dashboard.reports._breakdown-panel', ['breakdowns' => $breakdowns])
    @endif

    {{-- ── Tab body ─────────────────────────────────────────────── --}}
    @if ($tab === 'revenue')
        @include('livewire.dashboard.reports._revenue', ['d' => $reportData, 'from' => $from, 'to' => $to])
    @elseif ($tab === 'checkin')
        @include('livewire.dashboard.reports._checkin', ['d' => $reportData])
    @elseif ($tab === 'occupancy')
        @include('livewire.dashboard.reports._occupancy', ['d' => $reportData])
    @elseif ($tab === 'inventory')
        @include('livewire.dashboard.reports._inventory', ['d' => $reportData])
    @elseif ($tab === 'staff')
        @include('livewire.dashboard.reports._staff-activity', [
            'activityRows' => $activityRows,
            'staffOptions' => $staffOptions,
            'logNameOptions' => $logNameOptions,
            'staffKpis' => $staffKpis,
            'departments' => $departments,
            'exportUrl' => $exportUrl,
            'from' => $from,
            'to' => $to,
        ])
    @endif
</div>
