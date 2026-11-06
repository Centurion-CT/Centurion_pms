{{-- The day at a glance: one strip of numbers, built from the same
     snapshot as the calendar. Tonight's rooms sit in the right rail. --}}
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
    $mcPoints = $monthChart['points'] ?? [];
    $collectedToday = (int) ($mcPoints[(int) ($monthChart['today_index'] ?? 0)] ?? 0);
    $outstandingAll = (int) ($pulse['outstanding'] ?? 0);
    $monthTotal = (int) ($monthChart['total'] ?? 0);
@endphp
<section class="lp-stats" aria-label="Today" data-testid="widget-stats">
    <div>
        <p class="k">Occupied tonight</p>
        <p class="v" data-testid="tonight-percent">{{ $thPct }}%</p>
        <p class="n">{{ $thOccupied }} of {{ $thTotal }} {{ $thTotal === 1 ? 'room' : 'rooms' }}</p>
    </div>
    <div>
        <p class="k">Arriving</p>
        <p class="v">{{ (int) ($pulse['arriving'] ?? 0) }}</p>
        <p class="n">{{ $thUnassigned > 0 ? $thUnassigned.' unassigned' : 'Today' }}</p>
    </div>
    <div>
        <p class="k">Leaving</p>
        <p class="v">{{ (int) ($pulse['departing'] ?? 0) }}</p>
        <p class="n">{{ $thCounts['overdue'] > 0 ? $thCounts['overdue'].' past checkout' : 'Today' }}</p>
    </div>
    <div>
        <p class="k">Rooms free</p>
        <p class="v">{{ $thCounts['free'] }}</p>
        <p class="n">{{ $thCounts['dirty'] > 0 ? $thCounts['dirty'].' need cleaning' : 'Tonight' }}</p>
    </div>
    <button type="button" x-on:click="outstandingOpen = true" data-testid="pulse-tile-outstanding">
        <p class="k">To collect</p>
        <p class="v {{ $outstandingAll > 0 ? 'is-owed' : '' }}">{{ money($outstandingAll) }}</p>
        <p class="n">See who owes</p>
    </button>
    <div data-testid="widget-collected">
        <p class="k">Collected today</p>
        <p class="v">{{ money($collectedToday) }}</p>
        <p class="n">{{ money($monthTotal) }} this month</p>
    </div>
</section>
