{{-- Guest wallet statement PDF, rendered by dompdf via
     CustomerWalletStatementPdfController. Mirrors the invoice PDF
     (invoices/pdf.blade.php) for visual consistency; layout tables
     only because dompdf's Flexbox / Grid support is patchy. --}}
@php
    $money = fn (int $n) => ($n < 0 ? '&minus; ' : '') . '&#8358;' . number_format(abs($n));
    $accent  = '#B08A29';
    $ink     = '#14171A';
    $inkSoft = '#3A4148';
    $inkMute = '#6E767D';
    $line    = '#E4E1D9';
    $tint    = '#F7F4EC';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Wallet statement · {{ $customer->name }}</title>
    <style>
        @page { margin: 0; }
        html, body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: {{ $ink }};
            line-height: 1.5;
        }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }

        .page { padding: 0 22mm 22mm; }
        .accent-band { background: {{ $accent }}; height: 8px; margin-bottom: 18mm; }

        .brand-name {
            font-size: 18px;
            font-weight: 700;
            color: {{ $ink }};
            letter-spacing: -0.005em;
        }
        .brand-contact { color: {{ $inkMute }}; font-size: 10px; margin-top: 4px; }

        .doc-eyebrow {
            font-size: 8.5px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: {{ $inkMute }};
            font-family: DejaVu Sans Mono, monospace;
        }
        .doc-title {
            font-size: 15px;
            font-weight: 700;
            color: {{ $ink }};
            margin-top: 2px;
        }
        .doc-meta { color: {{ $inkMute }}; font-size: 10px; margin-top: 4px; }

        .section-title {
            font-size: 8.5px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: {{ $inkMute }};
            font-family: DejaVu Sans Mono, monospace;
        }
        .party-name {
            color: {{ $ink }};
            font-weight: 700;
            font-size: 12px;
            margin-top: 5px;
        }
        .party-contact { color: {{ $inkSoft }}; font-size: 10.5px; }

        .balance-card {
            background: {{ $tint }};
            border: 1px solid {{ $line }};
            border-left: 3px solid {{ $accent }};
            padding: 14px 18px;
            margin-top: 20px;
        }
        .balance-card .lbl { color: {{ $inkMute }}; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.14em; }
        .balance-card .amt {
            font-size: 22px;
            font-weight: 700;
            color: {{ $ink }};
            font-family: DejaVu Sans Mono, monospace;
            margin-top: 3px;
        }
        .balance-card .sub { color: {{ $inkSoft }}; font-size: 10px; margin-top: 4px; }

        .tx th {
            text-align: left;
            font-size: 8.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: {{ $inkMute }};
            font-weight: 700;
            padding: 8px 8px;
            background: {{ $tint }};
            border-bottom: 1px solid {{ $line }};
        }
        .tx td {
            padding: 8px 8px;
            border-bottom: 1px solid #F0EDE4;
            font-size: 10px;
        }
        .tx .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .tx .mono { font-family: DejaVu Sans Mono, monospace; }
        .tx .desc { color: {{ $inkSoft }}; }
        .tx .in  { color: #4C6C1E; }
        .tx .out { color: #B85C42; }

        .totals { width: 55%; margin-left: 45%; margin-top: 12px; }
        .totals td { padding: 5px 10px; font-size: 10.5px; }
        .totals .lbl { color: {{ $inkSoft }}; }
        .totals .num {
            text-align: right;
            font-family: DejaVu Sans Mono, monospace;
            color: {{ $ink }};
        }
        .totals .rule-top td {
            border-top: 1.5px solid {{ $ink }};
            padding-top: 8px;
            font-weight: 700;
            font-size: 12.5px;
        }

        .footer {
            text-align: center;
            font-size: 9px;
            color: {{ $inkMute }};
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid {{ $line }};
        }
        .empty {
            padding: 30px;
            text-align: center;
            color: {{ $inkMute }};
            border: 1px dashed {{ $line }};
        }
    </style>
</head>
<body>

<div class="accent-band"></div>

<div class="page">

    {{-- ── Header ────────────────────────────────────────────────── --}}
    <table>
        <tr>
            <td style="width: 55%;">
                <div class="brand-name">{{ $tenant?->name ?? 'Your property' }}</div>
                <div class="brand-contact">
                    @if ($tenant?->email) {{ $tenant->email }}<br> @endif
                    @if ($tenant?->phone) {{ $tenant->phone }} @endif
                </div>
            </td>
            <td style="width: 45%; text-align: right;">
                <div class="doc-eyebrow">Wallet statement</div>
                <div class="doc-title">{{ $customer->name }}</div>
                <div class="doc-meta">
                    Generated {{ $generatedAt->toFormattedDateString() }}
                </div>
                @if ($from || $to)
                    <div class="doc-meta">
                        {{ $from?->toFormattedDateString() ?? 'Opening' }}
                        &rarr;
                        {{ $to?->toFormattedDateString() ?? $generatedAt->toFormattedDateString() }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Guest ─────────────────────────────────────────────────── --}}
    <table style="margin-top: 20px;">
        <tr>
            <td style="width: 60%; padding-right: 20px;">
                <div class="section-title">Statement for</div>
                <div class="party-name">{{ $customer->name }}</div>
                @if ($customer->email)
                    <div class="party-contact">{{ $customer->email }}</div>
                @endif
                @if ($customer->phone)
                    <div class="party-contact">{{ $customer->phone }}</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="balance-card">
                    <div class="lbl">Closing balance</div>
                    <div class="amt">{!! $money($closingBalance) !!}</div>
                    <div class="sub">as of {{ $generatedAt->toFormattedDateString() }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ── Transactions ──────────────────────────────────────────── --}}
    <div style="margin-top: 24px;">
        <div class="section-title" style="margin-bottom: 6px;">Transactions</div>

        @if ($transactions->isEmpty())
            <div class="empty">No wallet activity in this period.</div>
        @else
            <table class="tx" data-testid="wallet-tx">
                <thead>
                    <tr>
                        <th style="width: 90px;">Date</th>
                        <th style="width: 22%;">Type</th>
                        <th>Description</th>
                        <th style="text-align: right; width: 90px;">In</th>
                        <th style="text-align: right; width: 90px;">Out</th>
                        <th style="text-align: right; width: 90px;">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($from !== null)
                        <tr>
                            <td colspan="5" class="desc" style="font-style: italic;">
                                Opening balance on {{ $from->toFormattedDateString() }}
                            </td>
                            <td class="num">{!! $money($openingBalance) !!}</td>
                        </tr>
                    @endif
                    @foreach ($transactions as $tx)
                        <tr>
                            <td class="mono">{{ $tx->created_at->format('Y-m-d') }}</td>
                            <td>{{ $tx->subtypeLabel() }}</td>
                            <td class="desc">{{ $tx->description }}</td>
                            <td class="num in">
                                @if ($tx->amount_naira > 0){!! $money((int) $tx->amount_naira) !!}@endif
                            </td>
                            <td class="num out">
                                @if ($tx->amount_naira < 0){!! $money((int) $tx->amount_naira) !!}@endif
                            </td>
                            <td class="num">{!! $money((int) $tx->balance_after_naira) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- ── Totals ────────────────────────────────────────── --}}
            <table class="totals">
                <tr>
                    <td class="lbl">Total credits</td>
                    <td class="num">{!! $money((int) $totals['inflow']) !!}</td>
                </tr>
                <tr>
                    <td class="lbl">Total debits</td>
                    <td class="num">&minus; {!! $money((int) $totals['outflow']) !!}</td>
                </tr>
                <tr class="rule-top">
                    <td class="lbl" style="color: {{ $ink }};">Closing balance</td>
                    <td class="num">{!! $money((int) $closingBalance) !!}</td>
                </tr>
            </table>
        @endif
    </div>

    <div class="footer">
        Wallet statement for {{ $customer->name }} · {{ $tenant?->name ?? 'property' }}
    </div>

</div>

</body>
</html>
