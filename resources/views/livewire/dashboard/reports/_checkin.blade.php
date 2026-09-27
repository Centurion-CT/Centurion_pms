{{-- ── KPI tiles ────────────────────────────────────────────────── --}}
<div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="checkin-kpis">
    <div class="lp-kpi">
        <span class="label">Arrivals expected</span>
        <span class="value">{{ number_format((int) $d['arrivals']) }}</span>
        <span class="sub">across this window</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Checked in</span>
        <span class="value">{{ number_format((int) $d['checkedIn']) }}</span>
        <span class="sub" style="color: var(--color-accent-deep);">
            {{ $d['checkinRate'] }}% check-in rate
        </span>
    </div>
    <div class="lp-kpi" style="background: {{ ((int) $d['noShows']) > 0 ? 'rgba(168, 101, 10, 0.10)' : 'var(--color-card-deep)' }};">
        <span class="label">No-shows</span>
        <span class="value" style="color: {{ ((int) $d['noShows']) > 0 ? '#A8650A' : 'var(--color-ink)' }};">
            {{ number_format((int) $d['noShows']) }}
        </span>
        <span class="sub">{{ $d['noShowRate'] }}% no-show rate</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Cancellations</span>
        <span class="value">{{ number_format((int) $d['cancellations']) }}</span>
        <span class="sub">
            @if (((int) $d['pendingArrivals']) > 0)
                {{ $d['pendingArrivals'] }} still pending arrival
            @else
                window settled
            @endif
        </span>
    </div>
</div>

{{-- ── Per-category breakdown ─────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="checkin-by-category">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Check-in performance by category</span>
        <span class="lp-dash-card-meta">arrivals · checked in · no-shows · cancelled</span>
    </div>
    @if (empty($d['byCategory']))
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
            No arrivals booked in this window.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Total</th>
                        <th>Checked in</th>
                        <th>No-shows</th>
                        <th>Cancelled</th>
                        <th>Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['byCategory'] as $row)
                        <tr data-testid="checkin-category-row-{{ $row['id'] }}"
                            x-on:click="$dispatch('reports-breakdown-open', { id: 'checkincat:{{ $row['id'] }}' })"
                            style="cursor: pointer;">
                            <td style="color: var(--color-ink); font-weight: 500;">{{ $row['name'] }}</td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['total']) }}</td>
                            <td>
                                <span class="lp-mono text-[12px]" style="color: var(--color-accent-deep);">
                                    {{ number_format((int) $row['checked_in']) }}
                                </span>
                            </td>
                            <td>
                                <span class="lp-mono text-[12px]" style="color: {{ ((int) $row['no_shows']) > 0 ? '#A8650A' : 'var(--color-ink-mute)' }};">
                                    {{ number_format((int) $row['no_shows']) }}
                                </span>
                            </td>
                            <td>
                                <span class="lp-mono text-[12px]" style="color: var(--color-ink-mute);">
                                    {{ number_format((int) $row['cancelled']) }}
                                </span>
                            </td>
                            <td>
                                <div class="flex items-center gap-3" style="min-width: 140px;">
                                    <div style="flex: 1; height: 6px; background: var(--color-card-deep); border-radius: 3px; overflow: hidden;">
                                        <div style="width: {{ $row['checkin_rate'] }}%; height: 100%; background: var(--color-accent-deep);"></div>
                                    </div>
                                    <span class="lp-mono text-[11px]" style="color: var(--color-ink-soft);">{{ $row['checkin_rate'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
