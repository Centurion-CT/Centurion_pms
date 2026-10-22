{{-- Tonight's house (mockup 3A): one bar per room, grouped by category.
     Height = share of the next 7 nights booked; colour = the room's state
     tonight. Built from the snapshot's heatmap blocks, so it agrees with
     the calendar. --}}
@php
    $thDates = array_slice($heatmap['dates'] ?? [], 0, 7);
    $thToday = $thDates[0] ?? \Illuminate\Support\Carbon::today()->toDateString();
    $thBlocks = $heatmap['blocks'] ?? [];
    $thArrivingRooms = $today['arrivals']->pluck('room_id')->filter()->flip();
    $thDirtyRooms = $attentionRows->pluck('inspection.room_id')->filter()->flip();
    $thSoldTypes = ['booked', 'pending', 'checkedin', 'due_out', 'overdue'];
    // Checked-in guests count from the booking itself, so a stay whose
    // availability rows are missing still shows as occupied.
    $thStays = [];
    foreach (($today['inHouse'] ?? collect()) as $stay) {
        if ($stay->room_id === null || $stay->checkout_date === null) {
            continue;
        }
        $out = $stay->checkout_date->toDateString();
        $thStays[$stay->room_id] = [
            'tonight' => $out < $thToday ? 'overdue' : ($out === $thToday ? 'due_out' : 'checkedin'),
            'until' => $out,
        ];
    }

    $thStateLabels = [
        'inhouse'  => 'In-house',
        'arriving' => 'Arriving',
        'booked'   => 'Reserved',
        'leaving'  => 'Leaving today',
        'overdue'  => 'Overstayed',
        'dirty'    => 'Needs cleaning',
        'blocked'  => 'Out of service',
        'free'     => 'Free',
    ];
    $thCounts = array_fill_keys(array_keys($thStateLabels), 0);
    $thGroups = [];
    foreach (($heatmap['grouped'] ?? collect()) as $group) {
        $bars = [];
        foreach ($group->rooms as $room) {
            $tonight = isset($thStays[$room->id])
                ? $thStays[$room->id]['tonight']
                : ($thBlocks[$room->id][$thToday] ?? null);
            $state = match (true) {
                $tonight === 'checkedin' => 'inhouse',
                $tonight === 'overdue' => 'overdue',
                $tonight === 'due_out' => 'leaving',
                isset($thArrivingRooms[$room->id]) => 'arriving',
                in_array($tonight, ['booked', 'pending'], true) => 'booked',
                $tonight === 'blocked' => 'blocked',
                isset($thDirtyRooms[$room->id]) => 'dirty',
                default => 'free',
            };
            $sold = 0;
            foreach ($thDates as $d) {
                if (in_array($thBlocks[$room->id][$d] ?? null, $thSoldTypes, true)
                    || (isset($thStays[$room->id]) && $d < $thStays[$room->id]['until'])) {
                    $sold++;
                }
            }
            $thCounts[$state]++;
            $bars[] = [
                'state' => $state,
                'height' => max(6, (int) round(($sold / max(1, count($thDates))) * 100)),
                'title' => 'Room '.$room->room_number.': '.$thStateLabels[$state].', '.$sold.' of '.count($thDates).' nights booked',
            ];
        }
        if ($bars !== []) {
            $thGroups[] = ['name' => $group->name, 'bars' => $bars];
        }
    }
    $thTotal = array_sum($thCounts);
    $thOccupied = $thCounts['inhouse'] + $thCounts['arriving'] + $thCounts['booked'] + $thCounts['overdue'];
    $thPct = $thTotal > 0 ? (int) round(($thOccupied / $thTotal) * 100) : 0;
    $thUnassigned = (int) ($pulse['unassigned_today'] ?? 0);
@endphp
<section class="cx-card cx-th" data-testid="widget-tonight">
    <div>
        <h2 class="cx-th-title">Tonight's house</h2>
        <p class="cx-th-lede">Each bar is a room. Taller bars are booked for more of the next 7 nights.</p>
        <div class="cx-th-big">
            <span class="cx-th-pct" data-testid="tonight-percent">{{ $thPct }}%</span>
            <span class="cx-th-pct-label">occupied tonight</span>
        </div>
        <p class="cx-th-sub">{{ $thOccupied }} of {{ $thTotal }} {{ $thTotal === 1 ? 'room' : 'rooms' }}</p>
    </div>

    <div style="min-width: 0;">
        @if ($thGroups === [])
            <p class="cx-th-lede">Add rooms to see tonight's house here.</p>
        @else
            <div class="cx-th-chart">
                <div class="cx-th-groups" role="img"
                     aria-label="{{ $thOccupied }} of {{ $thTotal }} rooms occupied tonight. {{ collect($thCounts)->filter()->map(fn ($n, $k) => $thStateLabels[$k].' '.$n)->implode(', ') }}.">
                    @foreach ($thGroups as $g)
                        <div class="cx-th-group" style="flex: {{ count($g['bars']) }} 1 0;">
                            <div class="cx-th-bars">
                                @foreach ($g['bars'] as $bar)
                                    <div class="cx-th-bar is-{{ $bar['state'] }}" style="height: {{ $bar['height'] }}%;" title="{{ $bar['title'] }}"></div>
                                @endforeach
                            </div>
                            <div class="cx-th-group-label" title="{{ $g['name'] }}">{{ $g['name'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="cx-th-legend">
            @foreach ($thStateLabels as $key => $label)
                @if ($thCounts[$key] > 0 || in_array($key, ['inhouse', 'arriving', 'free'], true))
                    <span><span class="cx-sw is-{{ $key }}" aria-hidden="true"></span>{{ $label }} {{ $thCounts[$key] }}@if ($key === 'arriving' && $thUnassigned > 0), plus {{ $thUnassigned }} unassigned @endif</span>
                @endif
            @endforeach
        </div>
    </div>
</section>
