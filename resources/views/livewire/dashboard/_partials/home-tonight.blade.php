{{-- Tonight's house (mockup 3A): one bar per room, grouped by category.
     Height = share of the next 7 nights booked; colour = the room's state
     tonight. Built from the snapshot's heatmap blocks, so it agrees with
     the calendar. --}}
@php
    $th = \App\Support\Dashboard\TonightHouse::from(
        ['heatmap' => $heatmap ?? [], 'today' => $today ?? []],
        $attentionRows->pluck('inspection.room_id')->all(),
    );
    $thStateLabels = \App\Support\Dashboard\TonightHouse::LABELS;
    $thCounts = $th['counts'];
    $thGroups = $th['groups'];
    $thTotal = $th['total'];
    $thOccupied = $th['occupied'];
    $thPct = $th['pct'];
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
