<div class="lp-dash">
    @include('_partials._back-button')

    {{-- ── Hero: title + Export + collected side panel ─────
         Right side shows collected as the Fraunces topline
         (also the number the trust bar celebrates below). --}}
    <section class="lp-res-idx-hero mt-3" data-testid="revenue-hero">
        <div class="lp-res-idx-hero-body">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">Accounting · Revenue</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    Revenue snapshot
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Booked vs. collected across {{ $rangeMeta['label'] }}. Numbers respect the branch on the sidebar.
                </p>
                <div class="lp-res-idx-hero-actions">
                    <a href="{{ route('tenant.accounting.revenue.export', request()->query()) }}"
                       data-testid="revenue-export"
                       class="lp-res-action lp-res-action--primary">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M8 2v9M4 7l4 4 4-4M2 13h12"/></svg>
                        Export CSV
                    </a>
                </div>
            </div>

            <aside class="lp-res-idx-hero-money" aria-label="Collected in window">
                <div class="lp-ih-hero-accrued">
                    <p class="lp-ih-hero-accrued-label">Collected</p>
                    <p class="lp-ih-hero-accrued-value" style="color: {{ (int) ($kpis['collected'] ?? 0) > 0 ? 'var(--color-ink)' : 'var(--color-ink-mute)' }};">
                        ₦{{ number_format((int) $kpis['collected']) }}
                    </p>
                    <p class="lp-ih-hero-accrued-sub">
                        vs ₦{{ number_format((int) $kpis['billed']) }} billed
                        @if (! is_null($kpis['collectedDelta']))
                            · {{ $kpis['collectedDelta'] >= 0 ? '+' : '' }}{{ round($kpis['collectedDelta']) }}% vs prior
                        @endif
                    </p>
                </div>
            </aside>
        </div>
    </section>

    {{-- ── Zero-commission trust bar ────────────────────────────────
         The single most on-brand line in the whole product: the
         operator's take of the period next to Keverna's cut. Fraunces
         on ink, gold aurora sweep on the ₦0 so the reader's eye lands
         there. Reads only the "collected" KPI so it stays honest
         whatever range the operator picks. --}}
    @if (($kpis['collected'] ?? 0) > 0)
        <section class="kv-trust-bar" data-testid="revenue-trust-bar" aria-live="polite">
            <div class="kv-trust-bar__eyebrow">
                <span class="kv-trust-bar__dash" aria-hidden="true"></span>
                Zero commission &middot; {{ $rangeMeta['label'] }}
            </div>
            <div class="kv-trust-bar__line">
                <span class="kv-trust-bar__word">You kept</span>
                <span class="kv-trust-bar__amount" data-testid="revenue-trust-kept">
                    ₦{{ number_format($kpis['collected']) }}
                </span>
                <span class="kv-trust-bar__word">this period.</span>
            </div>
            <div class="kv-trust-bar__line kv-trust-bar__line--mute">
                <span class="kv-trust-bar__word">Keverna took</span>
                <span class="kv-trust-bar__zero" data-testid="revenue-trust-fee">₦0</span>
                <span class="kv-trust-bar__word">.</span>
            </div>
        </section>
    @endif

    {{-- ── Range picker ─────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="revenue-range"
             style="padding: 14px 16px;">
        <div class="lp-chip-group">
            @foreach ([
                'today' => 'Today',
                'week'  => 'This week',
                'month' => 'This month',
                'year'  => 'This year',
                'custom'=> 'Custom',
            ] as $k => $label)
                <button type="button" wire:click="pickRange('{{ $k }}')"
                        data-testid="revenue-range-{{ $k }}"
                        class="lp-chip @if ($range === $k) is-active @endif">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        @if ($range === 'custom')
            <div class="grid gap-3 sm:grid-cols-2 mt-3">
                <div>
                    <label class="lp-form-label">From</label>
                    <input type="date" wire:model.live="customFrom" class="lp-input"
                           data-testid="revenue-custom-from">
                </div>
                <div>
                    <label class="lp-form-label">To</label>
                    <input type="date" wire:model.live="customTo" class="lp-input"
                           data-testid="revenue-custom-to">
                </div>
            </div>
        @endif
    </section>

    {{-- ── KPI strip ────────────────────────────────────────────── --}}
    <section class="grid gap-3 sm:grid-cols-4" data-testid="revenue-kpis">
        <div class="lp-dash-card" style="padding: 16px;">
            <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                Collected
            </p>
            <p class="lp-num-lg mt-1" style="color: var(--color-ink); font-size: 22px;">
                ₦{{ number_format($kpis['collected']) }}
            </p>
            @if (! is_null($kpis['collectedDelta']))
                <p class="text-[11.5px] mt-1"
                   style="color: {{ $kpis['collectedDelta'] >= 0 ? '#166534' : '#B85C42' }};">
                    {{ $kpis['collectedDelta'] >= 0 ? '↑' : '↓' }}
                    {{ abs(round($kpis['collectedDelta'])) }}% vs previous
                </p>
            @endif
        </div>
        <div class="lp-dash-card" style="padding: 16px;">
            <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                Billed
            </p>
            <p class="lp-num-lg mt-1" style="color: var(--color-ink); font-size: 22px;">
                ₦{{ number_format($kpis['billed']) }}
            </p>
            @if (! is_null($kpis['billedDelta']))
                <p class="text-[11.5px] mt-1"
                   style="color: {{ $kpis['billedDelta'] >= 0 ? '#166534' : '#B85C42' }};">
                    {{ $kpis['billedDelta'] >= 0 ? '↑' : '↓' }}
                    {{ abs(round($kpis['billedDelta'])) }}% vs previous
                </p>
            @endif
        </div>
        <div class="lp-dash-card" style="padding: 16px;">
            <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                Refunded
            </p>
            <p class="lp-num-lg mt-1" style="color: {{ $kpis['refunded'] > 0 ? '#B85C42' : 'var(--color-ink)' }}; font-size: 22px;">
                ₦{{ number_format($kpis['refunded']) }}
            </p>
            <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                {{ $kpis['bookings'] }} {{ Str::plural('booking', $kpis['bookings']) }}
            </p>
        </div>
        <div class="lp-dash-card" style="padding: 16px;">
            <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                Net
            </p>
            <p class="lp-num-lg mt-1" style="color: var(--color-ink); font-size: 22px;">
                ₦{{ number_format($kpis['net']) }}
            </p>
            <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                Avg booking ₦{{ number_format($kpis['avgBookingValue']) }}
            </p>
        </div>
    </section>

    {{-- ── Trend chart ──────────────────────────────────────────── --}}
    <section class="lp-dash-card" data-testid="revenue-trend">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Daily collected</span>
            <span class="lp-dash-card-meta">{{ count($sparkPoints) }} days</span>
        </div>
        @if (count($sparkPoints) < 2)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute); padding: 16px 0;">
                Pick a range longer than a day to see the trend.
            </p>
        @else
            @php
                $w = 100; $h = 100;
                $step = count($sparkPoints) > 1 ? ($w / (count($sparkPoints) - 1)) : $w;
                $pts = [];
                foreach ($sparkPoints as $i => $p) {
                    $x = round($i * $step, 2);
                    $y = $p['y'];
                    $pts[] = "$x,$y";
                }
                $polyline = implode(' ', $pts);
                $area = 'M0,100 L'.implode(' L', $pts).' L'.round(($i ?? 0) * $step, 2).',100 Z';
            @endphp
            <div style="width: 100%; height: 220px; position: relative;">
                <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none"
                     style="width: 100%; height: 100%;">
                    <defs>
                        <linearGradient id="rev-grad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--color-accent-deep, #7DAE3F)" stop-opacity="0.35"/>
                            <stop offset="100%" stop-color="var(--color-accent-deep, #7DAE3F)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    <path d="{{ $area }}" fill="url(#rev-grad)"/>
                    <polyline points="{{ $polyline }}"
                              fill="none"
                              stroke="var(--color-ink, #14171A)"
                              stroke-width="1.4"
                              vector-effect="non-scaling-stroke"
                              stroke-linejoin="round"
                              stroke-linecap="round"/>
                </svg>
            </div>
            <div class="flex justify-between text-[11px] mt-2"
                 style="color: var(--color-ink-mute);">
                <span>{{ \Illuminate\Support\Carbon::parse($sparkPoints[0]['date'])->format('j M') }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse($sparkPoints[array_key_last($sparkPoints)]['date'])->format('j M') }}</span>
            </div>
        @endif
    </section>

    {{-- ── Two-col: source split | channel breakdown ─────────── --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="lp-dash-card" data-testid="revenue-source-split">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Where the money came from</span>
                <span class="lp-dash-card-meta">Rooms vs POS</span>
            </div>
            @php
                $totalSrc = max(1, $source_split['rooms'] + $source_split['pos']);
                $roomShare = round(($source_split['rooms'] / $totalSrc) * 100);
                $posShare = 100 - $roomShare;
            @endphp
            <div style="display: flex; gap: 12px; margin: 8px 0 14px;">
                <div style="flex: 1;">
                    <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">Rooms</p>
                    <p class="lp-num-lg mt-1" style="color: var(--color-ink); font-size: 20px;">
                        ₦{{ number_format($source_split['rooms']) }}
                    </p>
                    <p class="text-[11px] mt-0.5" style="color: var(--color-ink-mute);">{{ $roomShare }}%</p>
                </div>
                <div style="flex: 1;">
                    <p class="lp-mono text-[10.5px]" style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">POS</p>
                    <p class="lp-num-lg mt-1" style="color: var(--color-ink); font-size: 20px;">
                        ₦{{ number_format($source_split['pos']) }}
                    </p>
                    <p class="text-[11px] mt-0.5" style="color: var(--color-ink-mute);">
                        {{ $posShare }}% · {{ $source_split['pos_count'] }} {{ Str::plural('sale', $source_split['pos_count']) }}
                    </p>
                </div>
            </div>
            <div style="height: 8px; background: var(--color-card-deep); border-radius: 999px; overflow: hidden;
                        display: flex;">
                <span style="width: {{ $roomShare }}%; background: var(--color-ink);"></span>
                <span style="width: {{ $posShare }}%; background: var(--color-accent-deep, #7DAE3F);"></span>
            </div>

            @if (! empty($topDays))
                <p class="lp-mono text-[10.5px] mt-5"
                   style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                    Top collection days
                </p>
                <ul style="list-style: none; padding: 0; margin: 6px 0 0;
                           display: flex; flex-direction: column; gap: 6px;">
                    @foreach ($topDays as $d)
                        @continue($d['collected'] <= 0)
                        <li class="flex justify-between text-[13px]">
                            <span style="color: var(--color-ink-soft);">
                                {{ \Illuminate\Support\Carbon::parse($d['date'])->format('D, j M') }}
                            </span>
                            <span class="lp-mono" style="color: var(--color-ink); font-weight: 600;">
                                ₦{{ number_format($d['collected']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="lp-dash-card lg:col-span-2" data-testid="revenue-channels">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">By booking channel</span>
                <span class="lp-dash-card-meta">Billed &amp; delta vs previous</span>
            </div>
            @if (empty($channel))
                <p class="text-[13.5px]" style="color: var(--color-ink-mute); padding: 16px 0;">
                    No bookings in this period.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="lp-table" data-testid="revenue-channel-table">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th style="text-align: right;">Bookings</th>
                                <th style="text-align: right;">Revenue</th>
                                <th style="text-align: right;">Share</th>
                                <th style="text-align: right;">Avg</th>
                                <th style="text-align: right;">vs prev</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($channel as $row)
                                <tr data-testid="revenue-channel-{{ $row['source'] }}">
                                    <td>
                                        <span style="font-weight: 500;">{{ $row['label'] }}</span>
                                    </td>
                                    <td class="lp-num" style="text-align: right;">{{ $row['bookings'] }}</td>
                                    <td class="lp-num" style="text-align: right; font-weight: 600;">
                                        ₦{{ number_format($row['revenue']) }}
                                    </td>
                                    <td class="lp-num" style="text-align: right; color: var(--color-ink-mute);">
                                        {{ round($row['share']) }}%
                                    </td>
                                    <td class="lp-num" style="text-align: right; color: var(--color-ink-soft);">
                                        ₦{{ number_format($row['avgValue']) }}
                                    </td>
                                    <td class="lp-num" style="text-align: right;
                                        color: {{ is_null($row['delta']) ? 'var(--color-ink-mute)' : ($row['delta'] >= 0 ? '#166534' : '#B85C42') }};">
                                        @if (is_null($row['delta']))
                                            -
                                        @else
                                            {{ $row['delta'] >= 0 ? '+' : '−' }}{{ abs(round($row['delta'])) }}%
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
