{{-- Revenue this month (mockup 3A): one bar per day of cash collected
     (payments less refunds), today in Centurion blue, days still to come
     as a flat baseline. Compared with the same days last month. --}}
@php
    $rv = $monthChart ?? ['points' => [], 'labels' => [], 'max' => 0, 'total' => 0, 'prev_total' => 0, 'today_index' => 0, 'month_label' => '', 'iso' => []];
    $rvPoints = $rv['points'];
    $rvMax = max(1, (int) $rv['max']);
    $rvToday = (int) $rv['today_index'];
    $rvTotal = (int) $rv['total'];
    $rvPrev = (int) ($rv['prev_total'] ?? 0);
    $rvPrevMonth = \Illuminate\Support\Carbon::today()->subMonthNoOverflow()->format('F');
    $rvDiff = $rvTotal - $rvPrev;
    $rvCompare = $rvPrev > 0
        ? money(abs($rvDiff)).' '.($rvDiff >= 0 ? 'ahead of' : 'behind').' '.$rvPrevMonth.' at this point'
        : null;
    $rvLast = count($rvPoints) - 1;
@endphp
<section class="cx-card cx-rev" data-testid="widget-month-revenue">
    <div class="cx-rev-head">
        <h2 class="cx-h2">Revenue this month</h2>
        <span class="cx-rev-meta">{{ money($rvTotal) }} so far{{ $rvCompare ? ', '.$rvCompare : '' }}</span>
        <span class="cx-rev-month">{{ $rv['month_label'] }}</span>
    </div>
    @if ($rvPoints === [])
        <p class="cx-rev-meta" style="margin-top: 12px;">No payments recorded this month yet.</p>
    @else
        <div class="cx-rev-bars" role="img" aria-label="{{ money($rvTotal) }} collected so far in {{ $rv['month_label'] }}">
            @foreach ($rvPoints as $i => $v)
                @php
                    $isFuture = $i > $rvToday;
                    $h = $isFuture ? 2 : ($v > 0 ? max(4, (int) round(($v / $rvMax) * 100)) : 2);
                @endphp
                <div class="cx-rev-bar {{ $i === $rvToday ? 'is-today' : '' }} {{ $isFuture ? 'is-future' : '' }}"
                     style="height: {{ $isFuture || $v <= 0 ? '2px' : $h.'%' }};"
                     title="{{ ($rv['labels'][$i] ?? '') }}: {{ $isFuture ? 'still to come' : money((int) $v) }}"></div>
            @endforeach
        </div>
        @php $rvTodayPos = count($rvPoints) > 0 ? (($rvToday + 0.5) / count($rvPoints)) * 100 : 50; @endphp
        <div class="cx-rev-axis" style="position: relative;">
            <span>{{ \Illuminate\Support\Carbon::parse($rv['iso'][0] ?? now())->format('j M') }}</span>
            @if ($rvTodayPos > 10 && $rvTodayPos < 90)
                <span style="position: absolute; left: {{ round($rvTodayPos, 2) }}%; transform: translateX(-50%); color: var(--color-accent-deep); font-weight: 600;">Today</span>
            @endif
            <span>{{ \Illuminate\Support\Carbon::parse($rv['iso'][$rvLast] ?? now())->format('j M') }}</span>
        </div>
    @endif
</section>
