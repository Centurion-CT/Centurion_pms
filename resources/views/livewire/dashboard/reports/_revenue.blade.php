@php
    $delta = $d['totalDelta'];
    $deltaSign = $delta === null ? null : ($delta >= 0 ? '+' : '');
    $deltaColor = $delta === null ? 'var(--color-ink-mute)' : ($delta >= 0 ? 'var(--color-accent-deep)' : '#B85C42');
@endphp

{{-- ── KPI tiles ────────────────────────────────────────────────── --}}
<div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="revenue-kpis">
    <div class="lp-kpi">
        {{-- Label is "Booked revenue" (not "Total revenue") because
             this figure is accrual — SUM(booking.total_amount) by
             checkin_date for confirmed / in-house / checked-out
             bookings. Cancelled bookings and cash timing are
             deliberately ignored: this is a check-in performance
             report (RevPAR / ADR side), NOT the till.

             For cash-basis numbers (net of refunds, by paid_at)
             see Payments, Summary, Finance's Net collected, and
             the Dashboard Revenue MTD tile — all four reconcile
             to the same figure. --}}
        <span class="label">Booked revenue</span>
        <span class="value">₦{{ number_format((int) $d['total']) }}</span>
        <span class="sub" style="color: {{ $deltaColor }};">
            @if ($delta === null)
                no prior data
            @else
                {{ $deltaSign }}{{ $delta }}% vs previous period
            @endif
        </span>
        <span class="sub" style="color: var(--color-ink-mute); margin-top: 2px;">
            by check-in date · not cash
        </span>
    </div>
    <div class="lp-kpi">
        <span class="label">Bookings</span>
        <span class="value">{{ number_format((int) $d['bookings']) }}</span>
        <span class="sub">{{ number_format((int) $d['nights']) }} room-night{{ $d['nights'] === 1 ? '' : 's' }}</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Average booking value</span>
        <span class="value">₦{{ number_format((int) $d['avgValue']) }}</span>
        <span class="sub">per confirmed booking</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Previous period</span>
        <span class="value" style="font-size: 20px;">₦{{ number_format((int) $d['prevTotal']) }}</span>
        <span class="sub">prior {{ (int) max(1, $from->diffInDays($to, absolute: true) + 1) }}-day window</span>
    </div>
</div>

{{-- ── Source breakdown ────────────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="revenue-by-source">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">By channel</span>
        <span class="lp-dash-card-meta">where the booking came from</span>
    </div>
    @if (empty($d['bySource']))
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">No revenue in this window.</p>
    @else
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th>Bookings</th>
                        <th style="text-align: right;">Revenue</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['bySource'] as $row)
                        <tr data-testid="revenue-source-row-{{ $row['source'] }}"
                            x-on:click="$dispatch('reports-breakdown-open', { id: 'channel:{{ $row['source'] }}' })"
                            style="cursor: pointer;">
                            <td style="color: var(--color-ink); font-weight: 500;">{{ $row['label'] }}</td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['bookings']) }}</td>
                            <td style="text-align: right;">
                                <span class="lp-table-amount">₦{{ number_format((int) $row['revenue']) }}</span>
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

{{-- ── Category breakdown ──────────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="revenue-by-category">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">By category</span>
        <span class="lp-dash-card-meta">which room types are earning</span>
    </div>
    @if (empty($d['byCategory']))
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
            No category-level revenue yet for this window.
        </p>
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
                    @foreach ($d['byCategory'] as $row)
                        <tr data-testid="revenue-category-row-{{ $row['id'] }}"
                            x-on:click="$dispatch('reports-breakdown-open', { id: 'revcat:{{ $row['id'] }}' })"
                            style="cursor: pointer;">
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $row['name'] }}
                                @if (! empty($row['type']))
                                    <span class="text-[11px] mt-0.5" style="display:block; color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">{{ $row['type'] }}</span>
                                @endif
                            </td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['bookings']) }}</td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['nights']) }}</td>
                            <td style="text-align: right;">
                                <span class="lp-table-amount">₦{{ number_format((int) $row['revenue']) }}</span>
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

{{-- ── Top rooms ───────────────────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="revenue-top-rooms">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Top rooms</span>
        <span class="lp-dash-card-meta">your highest-earning individual units</span>
    </div>
    @if (empty($d['topRooms']))
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
                    @foreach ($d['topRooms'] as $row)
                        <tr data-testid="revenue-room-row-{{ $row['id'] }}"
                            x-on:click="$dispatch('reports-breakdown-open', { id: 'revroom:{{ $row['id'] }}' })"
                            style="cursor: pointer;">
                            <td style="color: var(--color-ink); font-weight: 500;">{{ $row['label'] }}</td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['bookings']) }}</td>
                            <td style="text-align: right;">
                                <span class="lp-table-amount">₦{{ number_format((int) $row['revenue']) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
