{{-- ── KPI tiles ────────────────────────────────────────────────── --}}
<div class="grid gap-3 grid-cols-2 lg:grid-cols-4" data-testid="occupancy-kpis">
    <div class="lp-kpi">
        <span class="label">Occupancy</span>
        <span class="value">{{ $d['occupancyPercent'] }}<span style="font-size: 0.55em; color: var(--color-ink-mute); margin-left: 4px; font-weight: 500;">%</span></span>
        <span class="sub">{{ number_format((int) $d['occupiedRoomNights']) }} of {{ number_format((int) $d['availableRoomNights']) }} room-nights</span>
    </div>
    <div class="lp-kpi">
        <span class="label">ADR</span>
        <span class="value">₦{{ number_format((int) $d['adr']) }}</span>
        <span class="sub">average daily rate per night sold</span>
    </div>
    <div class="lp-kpi">
        <span class="label">RevPAR</span>
        <span class="value">₦{{ number_format((int) $d['revpar']) }}</span>
        <span class="sub">revenue per available room-night</span>
    </div>
    <div class="lp-kpi">
        <span class="label">Inventory</span>
        <span class="value">{{ number_format((int) $d['activeRooms']) }}</span>
        <span class="sub">active rooms × {{ $d['days'] }} day{{ $d['days'] === 1 ? '' : 's' }}</span>
    </div>
</div>

{{-- ── By category ──────────────────────────────────────────────── --}}
<section class="lp-dash-card" data-testid="occupancy-by-category">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Occupancy by category</span>
        <span class="lp-dash-card-meta">spot under-utilised classes</span>
    </div>
    @if (empty($d['byCategory']))
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
            No active rooms in any category yet.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Active rooms</th>
                        <th>Occupied nights</th>
                        <th>Available nights</th>
                        <th>Occupancy</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($d['byCategory'] as $row)
                        <tr>
                            <td style="color: var(--color-ink); font-weight: 500;">
                                {{ $row['name'] }}
                                @if (! empty($row['type']))
                                    <span class="text-[11px] mt-0.5" style="display:block; color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">{{ $row['type'] }}</span>
                                @endif
                            </td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['active_rooms']) }}</td>
                            <td style="color: var(--color-ink-soft);">{{ number_format((int) $row['occupied_nights']) }}</td>
                            <td style="color: var(--color-ink-mute);">{{ number_format((int) $row['available_nights']) }}</td>
                            <td>
                                <div class="flex items-center gap-3" style="min-width: 140px;">
                                    <div style="flex: 1; height: 6px; background: var(--color-card-deep); border-radius: 3px; overflow: hidden;">
                                        <div style="width: {{ $row['occupancy'] }}%; height: 100%; background: var(--color-accent-deep);"></div>
                                    </div>
                                    <span class="lp-mono text-[11px]" style="color: var(--color-ink-soft);">{{ $row['occupancy'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
