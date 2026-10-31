{{-- Summary PDF report, rendered by dompdf. Same constraints as the
     invoice PDF: table-based layout, inline styles, DejaVu Sans only,
     inline SVG for the trend chart (dompdf supports basic SVG). --}}
@php
    $money = fn (int $n) => '&#8358;' . number_format($n);
    $accent = '#B08A29';
    $ink = '#14171A';
    $inkSoft = '#3A4148';
    $inkMute = '#6E767D';
    $line = '#E4E1D9';
    $tint = '#F7F4EC';

    // Delta helpers, same maths as the on-screen Summary growth card.
    $delta = function (int|float $cur, int|float $prev): ?float {
        if ((float) $prev === 0.0) return $cur === $prev ? 0.0 : null;
        return round((((float) $cur - (float) $prev) / (float) $prev) * 100, 1);
    };

    // Mini trend chart geometry.
    $tW = 720; $tH = 130;
    $tPadX = 12; $tPadTop = 8; $tPadBottom = 18;
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
    $roomLine = 'M ' . implode(' L ', $roomPts);
    $posLine = 'M ' . implode(' L ', $posPts);

    // Per-outlet breakdown, front office row + POS outlets, scaled to biggest.
    $breakdown = [];
    if ($frontOfficeRevenue > 0 || $posByOutlet !== []) {
        if ($frontOfficeRevenue > 0) {
            $breakdown[] = ['name' => 'Front office (rooms)', 'sub' => 'Room revenue', 'revenue' => $frontOfficeRevenue];
        }
        foreach ($posByOutlet as $r) {
            $breakdown[] = [
                'name' => (string) $r['outlet_name'],
                'sub' => (string) $r['outlet_type'] . ' · ' . number_format((int) $r['sales_count']) . ' sales',
                'revenue' => (int) $r['revenue'],
            ];
        }
    }
    $breakdownMax = 0;
    foreach ($breakdown as $r) { $breakdownMax = max($breakdownMax, $r['revenue']); }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $tenant->name }}, {{ $periodLabel }} summary · {{ $from->format('M j') }}–{{ $to->format('M j, Y') }}</title>
    <style>
        @page { margin: 16mm 14mm; }
        html, body {
            margin: 0; padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: {{ $ink }};
            line-height: 1.45;
        }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .accent-band { background: {{ $accent }}; height: 6px; margin-bottom: 10mm; }
        .h1 { font-size: 20px; font-weight: 700; color: {{ $ink }}; }
        .eyebrow { font-size: 9px; color: {{ $inkMute }}; letter-spacing: 0.08em; text-transform: uppercase; }
        .card {
            border: 1px solid {{ $line }};
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 7mm;
        }
        .card-title { font-size: 12px; font-weight: 700; color: {{ $ink }}; margin-bottom: 6px; }
        .kpi-grid td { width: 25%; padding: 4px 8px 4px 0; }
        .kpi-value { font-size: 15px; font-weight: 700; color: {{ $ink }}; }
        .delta-up   { color: #4C6C1E; font-size: 9px; }
        .delta-down { color: #B85C42; font-size: 9px; }
        .breakdown-bar { background: {{ $line }}; height: 6px; border-radius: 3px; margin-top: 3px; overflow: hidden; }
        .breakdown-bar-fill { background: {{ $accent }}; height: 100%; opacity: 0.78; }
        .list-table td { padding: 5px 4px; border-bottom: 1px solid {{ $line }}; }
        .list-table tr:last-child td { border-bottom: none; }
        .amount { font-family: DejaVu Sans Mono, monospace; text-align: right; white-space: nowrap; }
        .mute { color: {{ $inkMute }}; }
        .soft { color: {{ $inkSoft }}; }
    </style>
</head>
<body>
    <div class="accent-band"></div>

    {{-- ── Header ────────────────────────────────────────────────── --}}
    <table>
        <tr>
            <td>
                <div class="eyebrow">{{ $periodLabel }} summary</div>
                <div class="h1" style="margin-top: 3px;">{{ $tenant->name }}</div>
                <div class="soft" style="margin-top: 2px;">
                    {{ $from->format('F j, Y') }} &rarr; {{ $to->format('F j, Y') }}
                </div>
            </td>
            <td style="text-align: right;">
                <div class="eyebrow">Generated</div>
                <div class="soft">{{ now()->format('M j, Y · g:i A') }}</div>
            </td>
        </tr>
    </table>

    <div style="height: 8mm;"></div>

    {{-- ── KPI strip ─────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-title">Headline numbers</div>
        <table class="kpi-grid">
            <tr>
                @foreach ($kpis as $k)
                    @php $d = $delta($k['value'], $k['previous']); @endphp
                    <td>
                        <div class="eyebrow">{{ $k['label'] }}</div>
                        <div class="kpi-value">
                            @if (! empty($k['money']))
                                {!! $money((int) $k['value']) !!}
                            @elseif (! empty($k['suffix']))
                                {{ $k['value'] }}<span style="font-size: 10px; color: {{ $inkSoft }};">{{ $k['suffix'] }}</span>
                            @else
                                {{ number_format((int) $k['value']) }}
                            @endif
                        </div>
                        @if (! is_null($d))
                            <div class="{{ $d >= 0 ? 'delta-up' : 'delta-down' }}">
                                {{ $d >= 0 ? '+' : '' }}{{ number_format($d, 1) }}% vs prior
                            </div>
                        @endif
                    </td>
                @endforeach
            </tr>
        </table>
    </div>

    {{-- ── Revenue trend ─────────────────────────────────────────── --}}
    @if ($trendMax > 1)
        <div class="card">
            <div class="card-title">Revenue trend</div>
            <div class="mute" style="font-size: 9px; margin-bottom: 4px;">
                <span style="color: {{ $accent }};">━</span> Rooms &nbsp;&nbsp;
                <span style="color: #7A9C3E;">━</span> POS
            </div>
            <svg viewBox="0 0 {{ $tW }} {{ $tH }}" width="100%" height="130">
                <path d="{{ $roomLine }}" fill="none" stroke="{{ $accent }}" stroke-width="1.4"/>
                <path d="{{ $posLine }}" fill="none" stroke="#7A9C3E" stroke-width="1.4"/>
                <text x="{{ $tPadX }}" y="{{ $tH - 4 }}" font-size="8" fill="{{ $inkMute }}" font-family="DejaVu Sans Mono">
                    {{ \Illuminate\Support\Carbon::parse($daily[0]['date'])->format('M j') }}
                </text>
                <text x="{{ $tW - $tPadX }}" y="{{ $tH - 4 }}" text-anchor="end" font-size="8" fill="{{ $inkMute }}" font-family="DejaVu Sans Mono">
                    {{ \Illuminate\Support\Carbon::parse($daily[count($daily)-1]['date'])->format('M j') }}
                </text>
            </svg>
        </div>
    @endif

    {{-- ── Revenue breakdown ─────────────────────────────────────── --}}
    <div class="card">
        <div class="card-title">Revenue breakdown</div>
        @if ($breakdown === [])
            <div class="mute">No revenue captured in this window.</div>
        @else
            <table class="list-table">
                @foreach ($breakdown as $r)
                    @php $pct = $breakdownMax > 0 ? round(($r['revenue'] / $breakdownMax) * 100, 1) : 0; @endphp
                    <tr>
                        <td style="width: 55%;">
                            <div>{{ $r['name'] }}</div>
                            <div class="eyebrow">{{ $r['sub'] }}</div>
                            <div class="breakdown-bar" style="margin-top: 4px;">
                                <div class="breakdown-bar-fill" style="width: {{ $pct }}%;"></div>
                            </div>
                        </td>
                        <td class="amount" style="width: 45%;">
                            {!! $money((int) $r['revenue']) !!}
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    {{-- ── Top sellers ───────────────────────────────────────────── --}}
    @if ($topSellers !== [])
        <div class="card">
            <div class="card-title">Top sellers (POS)</div>
            <table class="list-table">
                <tr>
                    <td class="eyebrow" style="width: 55%;">Item</td>
                    <td class="eyebrow">Outlet</td>
                    <td class="eyebrow amount">Units</td>
                    <td class="eyebrow amount">Revenue</td>
                </tr>
                @foreach ($topSellers as $s)
                    <tr>
                        <td>{{ $s['product_name'] }}</td>
                        <td class="soft">{{ $s['outlet_name'] }}</td>
                        <td class="amount soft">{{ number_format((int) $s['units']) }}</td>
                        <td class="amount">{!! $money((int) $s['revenue']) !!}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    {{-- ── Watch-outs ────────────────────────────────────────────── --}}
    <div class="card">
        <div class="card-title">Watch-outs</div>
        <table>
            <tr>
                <td style="width: 25%; padding-right: 8px;">
                    <div class="eyebrow">Voided sales</div>
                    <div class="kpi-value">{{ number_format((int) $watchouts['voidedSales']['count']) }}</div>
                    <div class="mute" style="font-size: 9px;">{!! $money((int) $watchouts['voidedSales']['amount']) !!} reversed</div>
                </td>
                <td style="width: 25%; padding-right: 8px;">
                    <div class="eyebrow">Wasted stock</div>
                    <div class="kpi-value">{{ number_format((int) $watchouts['wastedStock']['units']) }} <span style="font-size: 10px; font-weight: 500; color: {{ $inkSoft }};">units</span></div>
                    <div class="mute" style="font-size: 9px;">~{!! $money((int) $watchouts['wastedStock']['cost']) !!} at cost</div>
                </td>
                <td style="width: 25%; padding-right: 8px;">
                    <div class="eyebrow">Stock adjustments</div>
                    <div class="kpi-value">{{ number_format((int) $watchouts['stockAdjustments']) }}</div>
                    <div class="mute" style="font-size: 9px;">manual quantity edits</div>
                </td>
                <td style="width: 25%;">
                    <div class="eyebrow">Price cuts</div>
                    <div class="kpi-value">{{ number_format((int) $watchouts['priceCuts']) }}</div>
                    <div class="mute" style="font-size: 9px;">products lowered</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ── Recent changes ────────────────────────────────────────── --}}
    @if ($changes !== [])
        <div class="card">
            <div class="card-title">Changes in period</div>
            <table class="list-table">
                <tr>
                    <td class="eyebrow" style="width: 20%;">When</td>
                    <td class="eyebrow" style="width: 20%;">Who</td>
                    <td class="eyebrow" style="width: 25%;">What</td>
                    <td class="eyebrow" style="width: 35%;">Change</td>
                </tr>
                @foreach ($changes as $c)
                    <tr>
                        <td class="soft">
                            {{ $c['when']?->format('M j') }}
                            <div class="mute" style="font-size: 9px;">{{ $c['when']?->format('g:i A') }}</div>
                        </td>
                        <td>{{ $c['causer_name'] ?? 'System' }}</td>
                        <td>{{ $c['subject_label'] }}</td>
                        <td>{{ $c['summary'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <div class="mute" style="text-align: center; margin-top: 8mm; font-size: 9px;">
        {{ $tenant->name }} · Summary report generated by {{ config('brand.name') }}
    </div>
</body>
</html>
