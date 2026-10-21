@php
    // Trend chart: billed (line) + net collected (area) over $daily.
    // "Collected" line plots collected minus refunded per day so a
    // refund day dips the area — matches how the KPI tile + all
    // other net-of-refunds surfaces read.
    $trendW = 900;
    $trendH = 180;
    $padX = 24;
    $padTop = 12;
    $padBottom = 22;
    $innerW = $trendW - ($padX * 2);
    $innerH = $trendH - $padTop - $padBottom;
    $trendMax = 1;
    foreach ($daily as $d) {
        $netDay = max(0, (int) $d['collected'] - (int) ($d['refunded'] ?? 0));
        $trendMax = max($trendMax, $d['billed'], $netDay);
    }
    $trendCount = max(1, count($daily) - 1);

    $billedCoords = [];
    $collectedCoords = [];
    foreach ($daily as $i => $d) {
        $netDay = max(0, (int) $d['collected'] - (int) ($d['refunded'] ?? 0));
        $x = round($padX + ($i / $trendCount) * $innerW, 2);
        $yBilled = round($padTop + $innerH - (($d['billed'] / $trendMax) * $innerH), 2);
        $yCollected = round($padTop + $innerH - (($netDay / $trendMax) * $innerH), 2);
        $billedCoords[] = "{$x},{$yBilled}";
        $collectedCoords[] = "{$x},{$yCollected}";
    }
    $billedLine = 'M '.implode(' L ', $billedCoords);
    $collectedLine = 'M '.implode(' L ', $collectedCoords);
    $collectedArea = $collectedLine.' L '.round($padX + $innerW, 2).','.round($padTop + $innerH, 2).' L '.$padX.','.round($padTop + $innerH, 2).' Z';

    // Forecast chart: stacked bars of on_books (dark) + projected (soft) over $forecast.
    $fW = 900;
    $fH = 160;
    $fPadX = 24;
    $fPadTop = 12;
    $fPadBottom = 22;
    $fInnerW = $fW - ($fPadX * 2);
    $fInnerH = $fH - $fPadTop - $fPadBottom;
    $fMax = 1;
    foreach ($forecast as $r) {
        $fMax = max($fMax, $r['on_books'] + $r['projected']);
    }
    $fCount = max(1, count($forecast));
    $barW = max(2, ($fInnerW / $fCount) * 0.72);
    $gap = ($fInnerW / $fCount) - $barW;

    $trendTotalBilled = array_sum(array_column($daily, 'billed'));
    // Net collected in the window: gross collected − refunded.
    $trendTotalCollected = max(0,
        array_sum(array_column($daily, 'collected'))
        - array_sum(array_column($daily, 'refunded')),
    );
    $forecastOnBooks = array_sum(array_column($forecast, 'on_books'));
    $forecastProjected = array_sum(array_column($forecast, 'projected'));
    $forecastTotal = $forecastOnBooks + $forecastProjected;

    $firstDate = $daily[0]['date'] ?? null;
    $lastDate = end($daily)['date'] ?? null;
    $forecastFirst = $forecast[0]['date'] ?? null;
    $forecastLast = end($forecast)['date'] ?? null;
@endphp

<section class="lp-dash-card" data-testid="finance-trend">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Revenue trend</span>
        <span class="lp-dash-card-meta">billed vs. net cash collected (after refunds), day by day</span>
    </div>

    <div class="flex flex-wrap items-center gap-4 mt-1 mb-3 lp-mono text-[11px]"
         style="color: var(--color-ink-mute); letter-spacing: 0.04em;">
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="width:10px; height:10px; border-radius:2px; background: var(--color-accent-deep); display:inline-block;"></span>
            Billed &middot; &#8358;{{ number_format((int) $trendTotalBilled) }}
        </span>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="width:10px; height:10px; border-radius:2px; background: var(--color-accent-soft, rgba(51, 103, 153,0.35)); display:inline-block;"></span>
            Collected &middot; &#8358;{{ number_format((int) $trendTotalCollected) }}
        </span>
    </div>

    @if ($trendMax <= 1)
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
            No revenue in this window. Try widening the range.
        </p>
    @else
        <svg viewBox="0 0 {{ $trendW }} {{ $trendH }}" preserveAspectRatio="none"
             style="width: 100%; height: 200px; display: block;"
             data-testid="finance-trend-svg" role="img" aria-label="Revenue trend">
            {{-- Baseline --}}
            <line x1="{{ $padX }}" y1="{{ $padTop + $innerH }}"
                  x2="{{ $padX + $innerW }}" y2="{{ $padTop + $innerH }}"
                  stroke="var(--color-line-soft)" stroke-width="1"/>
            <path d="{{ $collectedArea }}" fill="var(--color-accent-soft, rgba(51, 103, 153,0.18))"/>
            <path d="{{ $collectedLine }}" fill="none"
                  stroke="var(--color-accent-soft, rgba(51, 103, 153,0.55))"
                  stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="{{ $billedLine }}" fill="none"
                  stroke="var(--color-accent-deep)"
                  stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div class="flex items-baseline justify-between mt-1 lp-mono"
             style="font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
            <span>{{ $firstDate }}</span>
            <span>{{ $lastDate }}</span>
        </div>
    @endif
</section>

<section class="lp-dash-card" data-testid="finance-forecast">
    <div class="lp-dash-card-head">
        <span class="lp-dash-card-title">Forward book &amp; forecast</span>
        <span class="lp-dash-card-meta">
            next {{ $horizon }} days &middot; on-books + trailing-4-week weekday average
        </span>
    </div>

    <div class="flex flex-wrap items-center gap-4 mt-1 mb-3 lp-mono text-[11px]"
         style="color: var(--color-ink-mute); letter-spacing: 0.04em;">
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="width:10px; height:10px; border-radius:2px; background: var(--color-accent-deep); display:inline-block;"></span>
            On-books &middot; &#8358;{{ number_format((int) $forecastOnBooks) }}
        </span>
        <span style="display:inline-flex; align-items:center; gap:6px;">
            <span style="width:10px; height:10px; border-radius:2px; background: var(--color-accent-soft, rgba(51, 103, 153,0.35)); display:inline-block;"></span>
            Estimate on top &middot; &#8358;{{ number_format((int) $forecastProjected) }}
        </span>
        <span style="color: var(--color-ink); font-weight: 500;">
            Total &middot; &#8358;{{ number_format((int) $forecastTotal) }}
        </span>
    </div>

    @if ($fMax <= 1)
        <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
            Nothing forward-booked yet and no history to project from.
        </p>
    @else
        <svg viewBox="0 0 {{ $fW }} {{ $fH }}" preserveAspectRatio="none"
             style="width: 100%; height: 180px; display: block;"
             data-testid="finance-forecast-svg" role="img" aria-label="Revenue forecast">
            <line x1="{{ $fPadX }}" y1="{{ $fPadTop + $fInnerH }}"
                  x2="{{ $fPadX + $fInnerW }}" y2="{{ $fPadTop + $fInnerH }}"
                  stroke="var(--color-line-soft)" stroke-width="1"/>
            @foreach ($forecast as $i => $row)
                @php
                    $total = $row['on_books'] + $row['projected'];
                    if ($total <= 0) continue;
                    $x = round($fPadX + $i * ($fInnerW / $fCount) + ($gap / 2), 2);
                    $totalH = round(($total / $fMax) * $fInnerH, 2);
                    $onBooksH = round(($row['on_books'] / $fMax) * $fInnerH, 2);
                    $projectedH = $totalH - $onBooksH;
                    $yTotalTop = round($fPadTop + $fInnerH - $totalH, 2);
                    $yOnBooksTop = round($fPadTop + $fInnerH - $onBooksH, 2);
                @endphp
                @if ($projectedH > 0)
                    <rect x="{{ $x }}" y="{{ $yTotalTop }}"
                          width="{{ round($barW, 2) }}" height="{{ round($projectedH, 2) }}"
                          fill="var(--color-accent-soft, rgba(51, 103, 153,0.35))"/>
                @endif
                @if ($onBooksH > 0)
                    <rect x="{{ $x }}" y="{{ $yOnBooksTop }}"
                          width="{{ round($barW, 2) }}" height="{{ round($onBooksH, 2) }}"
                          fill="var(--color-accent-deep)"/>
                @endif
            @endforeach
        </svg>
        <div class="flex items-baseline justify-between mt-1 lp-mono"
             style="font-size: 10.5px; color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">
            <span>{{ $forecastFirst }}</span>
            <span>{{ $forecastLast }}</span>
        </div>
        <p class="text-[11.5px] mt-2" style="color: var(--color-ink-mute);">
            Estimate uses trailing 4 weeks of same-weekday revenue. Treat as directional, not gospel.
        </p>
    @endif
</section>
