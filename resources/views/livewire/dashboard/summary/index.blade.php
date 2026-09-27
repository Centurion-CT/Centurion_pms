<div class="lp-dash">

    {{-- ── Hero: title + topline revenue side panel ─────────
         Right side hosts the collected revenue for the window
         (rooms + POS) in Fraunces so the operator sees the till
         at a glance. Filters, chart, KPIs, and change log below
         are untouched. --}}
    @php
        $summaryTopline = (int) collect($daily)->sum(fn ($row) => (int) $row['roomRevenue'] + (int) $row['posRevenue']);
        $summaryRoomTotal = (int) collect($daily)->sum('roomRevenue');
        $summaryPosTotal = (int) collect($daily)->sum('posRevenue');
        $summaryRangeDays = (int) ($from->diffInDays($to, absolute: true) + 1);
    @endphp
    <section class="lp-res-idx-hero" data-testid="summary-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Business</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Summary
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    One screen for everything happening on the property, rooms, restaurant, bar, lounge, pool. Filter by department or period; changes made in the window surface at the bottom.
                </p>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Topline revenue">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Topline</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ $summaryTopline > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};"
                       data-testid="summary-hero-topline">
                        ₦{{ number_format($summaryTopline) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        ₦{{ number_format($summaryRoomTotal) }} rooms ·
                        ₦{{ number_format($summaryPosTotal) }} POS
                        · {{ $summaryRangeDays }} {{ Str::plural('day', $summaryRangeDays) }}
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Filter bar ────────────────────────────────────────────── --}}
    @php
        // Inline eyebrow label style, reused across rows for alignment.
        $eyebrow = 'flex-shrink: 0; width: 60px; color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase; font-size: 10.5px;';
        $rangeDays = (int) ($from->diffInDays($to, absolute: true) + 1);
    @endphp
    <section class="lp-dash-card" data-testid="summary-filters"
             style="display: flex; flex-direction: column; gap: 12px; padding: 14px 18px;">

        {{-- Row 1: Period chips + date range readout --}}
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <span class="lp-mono" style="{{ $eyebrow }}">Period</span>
            <div class="lp-chips" data-testid="summary-period" style="flex: 1; min-width: 0;">
                @foreach ($periodChips as $chip)
                    <button type="button"
                            wire:click="setPeriod('{{ $chip['key'] }}')"
                            class="lp-chip @if ($period === $chip['key']) is-active @endif"
                            data-testid="summary-period-{{ $chip['key'] }}">
                        {{ $chip['label'] }}
                    </button>
                @endforeach
            </div>
            @if ($period === 'custom')
                <div class="flex items-center gap-2">
                    <input type="date" wire:model.live="fromDate" data-testid="summary-from"
                           class="lp-input" style="font-size: 12.5px; padding: 5px 10px; width: auto;">
                    <span style="color: var(--color-ink-mute);">&rarr;</span>
                    <input type="date" wire:model.live="toDate" data-testid="summary-to"
                           class="lp-input" style="font-size: 12.5px; padding: 5px 10px; width: auto;">
                </div>
            @else
                <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); letter-spacing: 0.04em; white-space: nowrap;">
                    {{ $from->format('M j') }} &rarr; {{ $to->format('M j, Y') }} &middot; {{ $rangeDays }} {{ $rangeDays === 1 ? 'day' : 'days' }}
                </span>
            @endif
        </div>

        {{-- Row 2: Department chips (left) + Staff select (right) --}}
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <span class="lp-mono" style="{{ $eyebrow }}">Dept</span>
            <div class="lp-chips" data-testid="summary-dept" style="flex: 1; min-width: 0;">
                <button type="button"
                        wire:click="setDepartment('all')"
                        class="lp-chip @if ($department === 'all') is-active @endif"
                        data-testid="summary-dept-all">All</button>
                <button type="button"
                        wire:click="setDepartment('front_office')"
                        class="lp-chip @if ($department === 'front_office') is-active @endif"
                        data-testid="summary-dept-front_office">Front office</button>
                @foreach ($outlets as $outlet)
                    <button type="button"
                            wire:click="setDepartment('outlet-{{ $outlet->id }}')"
                            class="lp-chip @if ($department === 'outlet-'.$outlet->id) is-active @endif"
                            data-testid="summary-dept-outlet-{{ $outlet->id }}">
                        {{ $outlet->name }}
                    </button>
                @endforeach
            </div>

            <div style="display: flex; align-items: center; gap: 8px; margin-left: auto;">
                <span class="lp-mono" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase; font-size: 10.5px;">Staff</span>
                <select wire:model.live="operatorFilter"
                        data-testid="summary-staff"
                        class="lp-input"
                        style="font-size: 12.5px; padding: 5px 26px 5px 10px; width: auto;">
                    <option value="">All staff</option>
                    @foreach ($operators as $op)
                        <option value="{{ $op->id }}">{{ $op->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </section>

    {{-- ── Revenue trend chart ───────────────────────────────────── --}}
    @php
        // Trend chart geometry, SVG scales via viewBox so it responds
        // to card width. Two overlapping lines: rooms + POS revenue.
        $tW = 900; $tH = 200;
        $tPadX = 28; $tPadTop = 16; $tPadBottom = 26;
        $tInnerW = $tW - ($tPadX * 2);
        $tInnerH = $tH - $tPadTop - $tPadBottom;
        $trendMax = 1;
        foreach ($daily as $d) {
            $trendMax = max($trendMax, $d['roomRevenue'], $d['posRevenue']);
        }
        $tCount = max(1, count($daily) - 1);
        $roomPts = [];
        $posPts = [];
        foreach ($daily as $i => $d) {
            $x = round($tPadX + ($i / $tCount) * $tInnerW, 2);
            $yr = round($tPadTop + $tInnerH - (($d['roomRevenue'] / $trendMax) * $tInnerH), 2);
            $yp = round($tPadTop + $tInnerH - (($d['posRevenue'] / $trendMax) * $tInnerH), 2);
            $roomPts[] = "{$x},{$yr}";
            $posPts[] = "{$x},{$yp}";
        }
        $roomLine = 'M '.implode(' L ', $roomPts);
        $posLine = 'M '.implode(' L ', $posPts);
        $roomArea = $roomLine.' L '.round($tPadX + $tInnerW, 2).','.round($tPadTop + $tInnerH, 2).' L '.$tPadX.','.round($tPadTop + $tInnerH, 2).' Z';
        $posArea = $posLine.' L '.round($tPadX + $tInnerW, 2).','.round($tPadTop + $tInnerH, 2).' L '.$tPadX.','.round($tPadTop + $tInnerH, 2).' Z';
        $trendRoomTotal = array_sum(array_column($daily, 'roomRevenue'));
        $trendPosTotal = array_sum(array_column($daily, 'posRevenue'));
    @endphp
    <section class="lp-dash-card" data-testid="summary-trend">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Revenue trend</span>
            <span class="lp-dash-card-meta">{{ $scope['label'] }} &middot; day by day</span>
        </div>
        <div class="flex flex-wrap items-center gap-4 mt-1 mb-3 lp-mono text-[11px]"
             style="color: var(--color-ink-mute); letter-spacing: 0.04em;">
            <span style="display:inline-flex; align-items:center; gap:6px;">
                <span style="width:10px; height:10px; border-radius:2px; background: var(--color-accent-deep); display:inline-block;"></span>
                Rooms &middot; &#8358;{{ number_format((int) $trendRoomTotal) }}
            </span>
            <span style="display:inline-flex; align-items:center; gap:6px;">
                <span style="width:10px; height:10px; border-radius:2px; background: var(--color-accent-soft, rgba(173, 203, 82,0.35)); display:inline-block;"></span>
                POS &middot; &#8358;{{ number_format((int) $trendPosTotal) }}
            </span>
        </div>
        @if ($trendMax <= 1)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No revenue in this window, widen the range or clear the staff filter.</p>
        @else
            <svg viewBox="0 0 {{ $tW }} {{ $tH }}" preserveAspectRatio="none"
                 style="width: 100%; height: 200px; display: block;">
                <path d="{{ $roomArea }}" fill="var(--color-accent-deep)" fill-opacity="0.10"/>
                <path d="{{ $posArea }}" fill="var(--color-accent-soft, rgba(173, 203, 82,0.35))" fill-opacity="0.18"/>
                <path d="{{ $roomLine }}" fill="none" stroke="var(--color-accent-deep)" stroke-width="1.6"/>
                <path d="{{ $posLine }}" fill="none" stroke="var(--color-accent-soft, rgba(173, 203, 82,0.65))" stroke-width="1.6"/>
                <text x="{{ $tPadX }}" y="{{ $tH - 6 }}" font-size="10" fill="var(--color-ink-mute)" font-family="ui-monospace, monospace">
                    {{ \Illuminate\Support\Carbon::parse($daily[0]['date'] ?? $from->toDateString())->format('M j') }}
                </text>
                <text x="{{ $tW - $tPadX }}" y="{{ $tH - 6 }}" text-anchor="end" font-size="10" fill="var(--color-ink-mute)" font-family="ui-monospace, monospace">
                    {{ \Illuminate\Support\Carbon::parse($daily[count($daily)-1]['date'] ?? $to->toDateString())->format('M j') }}
                </text>
            </svg>
        @endif
    </section>

    {{-- ── KPI strip ─────────────────────────────────────────────── --}}
    <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="summary-kpis">
        @foreach ($kpis['cards'] as $card)
            @php
                // Pick the series values for this card's spark, if any.
                $sparkValues = [];
                if (! empty($card['spark'])) {
                    foreach ($daily as $row) {
                        $sparkValues[] = match ($card['spark']) {
                            'totalRevenue' => (int) $row['roomRevenue'] + (int) $row['posRevenue'],
                            'roomRevenue' => (int) $row['roomRevenue'],
                            'posRevenue' => (int) $row['posRevenue'],
                            'posCount' => (int) $row['posCount'],
                            'bookings' => (int) $row['bookings'],
                            default => 0,
                        };
                    }
                }
                $sparkMax = $sparkValues !== [] ? max($sparkValues) : 0;
            @endphp
            @php
                $isLinked = ! empty($card['link']);
                $valueColor = ($card['tone'] ?? null) === 'danger' ? '#B85C42' : 'var(--color-ink)';
            @endphp
            <div class="lp-dash-card" style="padding: 18px 20px; {{ $isLinked ? 'position: relative;' : '' }}">
                @if ($isLinked)
                    <a href="{{ $card['link'] }}" wire:navigate
                       aria-label="{{ $card['label'] }}"
                       style="position: absolute; inset: 0; z-index: 1; border-radius: inherit;"></a>
                @endif
                <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
                    {{ $card['label'] }}
                </span>
                <div style="margin-top: 6px; color: {{ $valueColor }}; font-weight: 600; font-size: 22px;">
                    @if (! empty($card['text']))
                        <span style="font-size: 16px; font-weight: 500;">{{ $card['text'] }}</span>
                    @elseif (! empty($card['money']))
                        @php $val = (int) $card['value']; @endphp
                        @if ($val < 0)−@endif&#8358;{{ number_format(abs($val)) }}
                    @elseif (! empty($card['suffix']))
                        {{ $card['value'] }}<span style="font-size: 14px; color: var(--color-ink-soft);">{{ $card['suffix'] }}</span>
                    @else
                        {{ number_format((int) $card['value']) }}
                    @endif
                </div>
                @if (! is_null($card['delta']))
                    <span class="lp-mono text-[11px]" style="color: {{ $card['delta'] >= 0 ? 'var(--color-good)' : 'var(--color-warn)' }};">
                        {{ $card['delta'] >= 0 ? '+' : '' }}{{ number_format($card['delta'], 1) }}% vs prior
                    </span>
                @endif
                @if (! empty($card['sub']))
                    <span class="text-[11px]" style="display: block; color: var(--color-ink-mute); margin-top: 2px;">
                        {{ $card['sub'] }}
                    </span>
                @endif
                @if ($sparkValues !== [] && $sparkMax > 0)
                    @php
                        $sW = 200; $sH = 32; $sBarW = max(1.5, $sW / count($sparkValues) * 0.7);
                        $sGap = ($sW / count($sparkValues)) - $sBarW;
                    @endphp
                    <svg viewBox="0 0 {{ $sW }} {{ $sH }}" preserveAspectRatio="none"
                         style="width: 100%; height: 32px; margin-top: 8px; display: block;"
                         data-testid="summary-kpi-spark">
                        @foreach ($sparkValues as $i => $v)
                            @php
                                $h = $v > 0 ? max(1.5, ($v / $sparkMax) * $sH) : 0;
                                $x = $i * ($sBarW + $sGap);
                                $y = $sH - $h;
                            @endphp
                            <rect x="{{ round($x, 2) }}" y="{{ round($y, 2) }}"
                                  width="{{ round($sBarW, 2) }}" height="{{ round($h, 2) }}"
                                  fill="var(--color-accent-deep)" fill-opacity="0.55" rx="1"/>
                        @endforeach
                    </svg>
                @endif
            </div>
        @endforeach
    </section>

    {{-- ── Growth vs prior window ────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="summary-growth">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Growth vs prior period</span>
            <span class="lp-dash-card-meta">
                {{ $prevFrom->toFormattedDateString() }} &rarr; {{ $prevTo->toFormattedDateString() }}
                &middot; same length window
            </span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($growth as $key => $g)
                <div style="border: 1px solid var(--color-line); border-radius: 8px; padding: 14px;"
                     data-testid="summary-growth-{{ $key }}">
                    <span class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.08em; text-transform: uppercase;">
                        {{ $g['label'] }}
                    </span>
                    <div style="display: flex; align-items: baseline; gap: 8px; margin-top: 6px;">
                        <span style="color: var(--color-ink); font-weight: 600; font-size: 18px;">
                            @if (! empty($g['money']))
                                &#8358;{{ number_format((int) $g['current']) }}
                            @elseif (! empty($g['suffix']))
                                {{ $g['current'] }}<span style="font-size: 12px; color: var(--color-ink-soft);">{{ $g['suffix'] }}</span>
                            @else
                                {{ number_format((int) $g['current']) }}
                            @endif
                        </span>
                        @if (! is_null($g['delta']))
                            <span class="lp-mono text-[11px]" style="color: {{ $g['delta'] >= 0 ? 'var(--color-good)' : 'var(--color-warn)' }};">
                                {{ $g['delta'] >= 0 ? '+' : '' }}{{ number_format($g['delta'], 1) }}%
                            </span>
                        @endif
                    </div>
                    <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); display: block; margin-top: 2px;">
                        prior:
                        @if (! empty($g['money']))
                            &#8358;{{ number_format((int) $g['previous']) }}
                        @elseif (! empty($g['suffix']))
                            {{ $g['previous'] }}{{ $g['suffix'] }}
                        @else
                            {{ number_format((int) $g['previous']) }}
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Watch-outs (leak signals) ─────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="summary-watchouts">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Watch-outs</span>
            <span class="lp-dash-card-meta">
                voided sales &middot; waste &middot; stock adjustments &middot; downward price edits
            </span>
        </div>
        @php
            $watchTiles = [
                [
                    'testid' => 'summary-watch-voided',
                    'label'  => 'Voided sales',
                    'value'  => number_format($watchouts['voidedSales']['count']),
                    'sub'    => '₦'.number_format($watchouts['voidedSales']['amount']).' reversed',
                    'dot'    => ($watchouts['voidedSales']['count'] ?? 0) > 0 ? '#B85C42' : 'var(--color-accent-deep)',
                ],
                [
                    'testid' => 'summary-watch-wasted',
                    'label'  => 'Wasted stock',
                    'value'  => number_format($watchouts['wastedStock']['units']),
                    'suffix' => 'units',
                    'sub'    => '~₦'.number_format($watchouts['wastedStock']['cost']).' at current cost',
                    'dot'    => ($watchouts['wastedStock']['units'] ?? 0) > 0 ? '#F97316' : 'var(--color-accent-deep)',
                ],
                [
                    'testid' => 'summary-watch-adjustments',
                    'label'  => 'Stock adjustments',
                    'value'  => number_format($watchouts['stockAdjustments']),
                    'sub'    => 'manual quantity edits',
                    'dot'    => '#7A6EA7',
                ],
                [
                    'testid' => 'summary-watch-price-cuts',
                    'label'  => 'Price cuts',
                    'value'  => number_format($watchouts['priceCuts']),
                    'sub'    => 'products lowered in price',
                    'dot'    => ($watchouts['priceCuts'] ?? 0) > 0 ? '#A8650A' : 'var(--color-accent-deep)',
                ],
            ];
        @endphp
        <div class="grid gap-2 grid-cols-2 lg:grid-cols-4">
            @foreach ($watchTiles as $tile)
                <div class="lp-dash-card"
                     data-testid="{{ $tile['testid'] }}"
                     style="padding: 14px 18px; display: flex; align-items: center; gap: 14px;">
                    <span aria-hidden style="width: 8px; height: 8px; border-radius: 999px;
                                              background: {{ $tile['dot'] }};
                                              box-shadow: 0 0 0 3px {{ $tile['dot'] }}22;
                                              flex-shrink: 0;"></span>
                    <div style="min-width: 0; flex: 1;">
                        <p class="lp-num-lg text-[22px]" style="color: var(--color-ink);">
                            {{ $tile['value'] }}@if (! empty($tile['suffix']))<span style="font-size: 12px; color: var(--color-ink-soft); font-weight: 500;"> {{ $tile['suffix'] }}</span>@endif
                        </p>
                        <p class="lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                           style="color: var(--color-ink-mute); margin-top: 2px;">
                            {{ $tile['label'] }}
                            <span style="text-transform: none; letter-spacing: 0; color: var(--color-ink-mute);
                                         font-size: 11px; margin-left: 4px;">
                                {{ $tile['sub'] }}
                            </span>
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Client-side breakdown panel for the Revenue breakdown rows. --}}
    @if (! empty($breakdownPanels ?? []))
        @include('livewire.dashboard.summary._breakdown-panel', ['breakdownPanels' => $breakdownPanels])
    @endif

    {{-- ── Revenue breakdown ─────────────────────────────────────── --}}
    @php
        // Build a normalised list of rows for the horizontal bar chart:
        // front-office (rooms) as one row when in All / Front-office
        // scope, followed by every POS outlet. Scaled to the biggest
        // bar so relative dominance is instantly readable.
        $breakdownRows = [];
        if (in_array($scope['kind'], ['all', 'front_office'], true)) {
            $breakdownRows[] = [
                'id' => 'sum:rooms',
                'name' => 'Front office (rooms)',
                'sub' => 'Room revenue',
                'revenue' => (int) $revenueBreakdown['frontOfficeRevenue'],
            ];
            // Only surface the extras row when there's actually
            // something to show - otherwise it just adds noise.
            if ((int) ($revenueBreakdown['chargesRevenue'] ?? 0) > 0) {
                $breakdownRows[] = [
                    'id' => 'sum:extras',
                    'name' => 'Front office (extras)',
                    'sub' => 'F&B, laundry, extension, overstay',
                    'revenue' => (int) $revenueBreakdown['chargesRevenue'],
                ];
            }
        }
        foreach ($revenueBreakdown['posByOutlet'] as $r) {
            $breakdownRows[] = [
                'id' => 'sum:outlet:'.(int) $r['outlet_id'],
                'name' => (string) $r['outlet_name'],
                'sub' => (string) $r['outlet_type'].' · '.number_format((int) $r['sales_count']).' sales',
                'revenue' => (int) $r['revenue'],
            ];
        }
        $breakdownMax = 0;
        foreach ($breakdownRows as $r) { $breakdownMax = max($breakdownMax, $r['revenue']); }
    @endphp
    <section class="lp-dash-card" data-testid="summary-revenue-breakdown">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Revenue breakdown</span>
            <span class="lp-dash-card-meta">{{ $scope['label'] }} &middot; {{ $from->toFormattedDateString() }} &rarr; {{ $to->toFormattedDateString() }}</span>
        </div>
        @if ($breakdownRows === [])
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No revenue in this window.</p>
        @else
            <div style="display: flex; flex-direction: column; gap: 10px;">
                @foreach ($breakdownRows as $r)
                    @php
                        $pct = $breakdownMax > 0 ? round(($r['revenue'] / $breakdownMax) * 100, 2) : 0;
                    @endphp
                    <div data-testid="summary-breakdown-row"
                         @if (! empty($r['id']))
                            x-on:click="$dispatch('summary-breakdown-open', { id: '{{ $r['id'] }}' })"
                            style="cursor: pointer;"
                         @endif>
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px;">
                            <div>
                                <span style="color: var(--color-ink); font-weight: 500; font-size: 13.5px;">
                                    {{ $r['name'] }}
                                </span>
                                <span class="lp-mono text-[10.5px]" style="display: block; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                                    {{ $r['sub'] }}
                                </span>
                            </div>
                            <span class="lp-table-amount">&#8358;{{ number_format($r['revenue']) }}</span>
                        </div>
                        <div style="height: 8px; background: var(--color-line); border-radius: 4px; margin-top: 6px; overflow: hidden;">
                            <div style="height: 100%; width: {{ $pct }}%; background: var(--color-accent-deep); opacity: 0.7; border-radius: 4px;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ── Top sellers ────────────────────────────────── --}}
    @if ($scope['kind'] !== 'front_office')
        <section class="lp-dash-card" data-testid="summary-top-sellers" style="padding: 14px 16px;">
            <div class="lp-dash-card-head" style="margin-bottom: 12px;">
                <span class="lp-dash-card-title">Top sellers</span>
                <span class="lp-dash-card-meta">
                    @if ($scope['kind'] === 'outlet') {{ $scope['label'] }} · @endif ranked by revenue
                </span>
            </div>
            @if (empty($topSellers))
                @include('livewire.dashboard._partials._empty', [
                    'icon'  => 'wallet',
                    'title' => 'No product sales in this window.',
                    'sub'   => 'Widen the range or clear the department filter.',
                ])
            @else
                <ul class="lp-ledger">
                    @foreach ($topSellers as $idx => $row)
                        @php $tone = $idx === 0 ? 'paid' : ($idx < 3 ? 'info' : 'muted'); @endphp
                        <li class="lp-ledger-item">
                            <span class="lp-ledger-marker lp-ledger-marker--{{ $tone }}" aria-hidden></span>
                            <div class="lp-ledger-body">
                                <div class="lp-gr-primary">
                                    <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11px;">
                                        #{{ $idx + 1 }}
                                    </span>
                                    <span class="lp-ledger-desc" style="font-weight: 500; color: var(--color-ink);">
                                        {{ $row['product_name'] }}
                                    </span>
                                    <span class="lp-category-type">{{ $row['outlet_name'] }}</span>
                                </div>
                                <p class="lp-ledger-meta">
                                    <span class="lp-mono">{{ number_format($row['units']) }}</span>
                                    {{ Str::plural('unit', (int) $row['units']) }} sold
                                </p>
                            </div>
                            <div class="lp-ledger-right">
                                <span class="lp-invoice-total">₦{{ number_format($row['revenue']) }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- ── Changes in period ─────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="summary-changes">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Changes in period</span>
            <span class="lp-dash-card-meta">
                price &middot; cost &middot; tax &middot; stock &middot; {{ $scope['label'] }}
            </span>
        </div>
        @if (empty($changes))
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                No edits or stock movements recorded for this scope in the selected window.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">When</th>
                            <th>Who</th>
                            <th>What</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($changes as $entry)
                            <tr data-testid="summary-change-row" data-audit-source="{{ $entry['source'] }}">
                                <td style="color: var(--color-ink-soft); vertical-align: top;">
                                    {{ $entry['when']?->toFormattedDateString() }}
                                    <span class="lp-mono text-[11px]" style="display: block; color: var(--color-ink-mute);">
                                        {{ $entry['when']?->format('g:i A') }}
                                    </span>
                                </td>
                                <td style="color: var(--color-ink); font-weight: 500; vertical-align: top;">
                                    {{ $entry['causer_name'] ?? 'System' }}
                                </td>
                                <td style="vertical-align: top;">
                                    <span style="color: var(--color-ink); font-weight: 500;">{{ $entry['subject_label'] }}</span>
                                    <span class="lp-mono text-[10.5px]" style="display: block; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                                        {{ $entry['subject_type'] }}
                                    </span>
                                </td>
                                <td style="vertical-align: top;">
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px 6px; align-items: center;">
                                        @foreach ($entry['kinds'] as $k)
                                            <span class="lp-tag @if ($k === 'price') lp-tag--confirmed @elseif ($k === 'stock') lp-tag--checked-in @elseif ($k === 'cost') lp-tag--pending @elseif ($k === 'tax') lp-tag--on-hold @endif"
                                                  style="font-size: 10px;">{{ $k }}</span>
                                        @endforeach
                                        <span style="color: var(--color-ink); font-size: 13.5px;">{{ $entry['summary'] }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="padding: 12px 16px 4px; text-align: right;">
                <a href="{{ route('tenant.inventory.audit-log') }}" wire:navigate
                   class="lp-mono text-[11px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.04em; text-decoration: underline;">
                    See full change history &rarr;
                </a>
            </div>
        @endif
    </section>

    {{-- ── Subscribers card ──────────────────────────────────────── --}}
    <livewire:dashboard.summary.subscribers />
</div>
