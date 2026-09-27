{{-- PDF invoice, rendered by dompdf via InvoicePdfController.

     dompdf constraints that shaped this template:
       - No Flexbox / Grid → layout tables only.
       - Only built-in DejaVu Sans / DejaVu Sans Mono without registering
         extra fonts. Symbols like ₦ / arrows / bullets need to survive
         those fonts (they do in DejaVu Sans).
       - Cell widths need to be explicit (percent or px), auto-sizing
         is inconsistent between dompdf versions.
       - `@page` margin is the outer print margin; inner sections use
         their own padding.
--}}
@php
    $money = fn (int $n) => money($n);
    $accent = '#B08A29';        // brand olive-gold, the app's `--color-accent-deep`
    $ink = '#14171A';
    $inkSoft = '#3A4148';
    $inkMute = '#6E767D';
    $line = '#E4E1D9';
    $tint = '#F7F4EC';

    $statusStyles = match ($invoice->status->value) {
        'paid'    => ['bg' => '#DCE9C9', 'fg' => '#4C6C1E'],
        'void'    => ['bg' => '#F1D8D0', 'fg' => '#B85C42'],
        'issued'  => ['bg' => '#F3E4B6', 'fg' => '#8A6A00'],
        'partial' => ['bg' => '#F1DEB6', 'fg' => '#8B4D0F'],
        default   => ['bg' => '#E9E5D7', 'fg' => '#6E767D'],
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->number ?? 'Invoice' }}</title>
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

        /* Outer page canvas: our own inner margins so the accent band
           can bleed to the paper edge, and totals get an even inset. */
        .page {
            padding: 0 22mm 22mm;
        }

        .accent-band {
            background: {{ $accent }};
            height: 8px;
            margin-bottom: 18mm;
        }

        /* ── Header ─────────────────────────────────────────────────── */
        .brand-name {
            font-size: 18px;
            font-weight: 700;
            color: {{ $ink }};
            letter-spacing: -0.005em;
        }
        .brand-contact {
            color: {{ $inkMute }};
            font-size: 10px;
            line-height: 1.5;
            margin-top: 4px;
        }
        .inv-eyebrow {
            font-size: 8.5px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: {{ $inkMute }};
            font-family: DejaVu Sans Mono, monospace;
        }
        .inv-number {
            font-size: 15px;
            font-weight: 700;
            color: {{ $ink }};
            font-family: DejaVu Sans Mono, monospace;
            margin-top: 2px;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 8.5px;
            letter-spacing: 0.10em;
            text-transform: uppercase;
            font-weight: 700;
            background: {{ $statusStyles['bg'] }};
            color: {{ $statusStyles['fg'] }};
        }
        .inv-meta {
            color: {{ $inkMute }};
            font-size: 10px;
            margin-top: 4px;
        }

        /* ── Bill-to / Stay ─────────────────────────────────────────── */
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
        .party-contact {
            color: {{ $inkSoft }};
            font-size: 10.5px;
        }

        /* ── Line items ─────────────────────────────────────────────── */
        .lines th {
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
        .lines td {
            padding: 10px 8px;
            border-bottom: 1px solid #F0EDE4;
            font-size: 10.5px;
        }
        .lines .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .lines .desc { padding-right: 12px; color: {{ $ink }}; }
        .lines .ink-soft { color: {{ $inkSoft }}; }

        /* ── Totals ─────────────────────────────────────────────────── */
        .totals {
            width: 55%;
            margin-left: 45%;
        }
        .totals td {
            padding: 5px 10px;
            font-size: 10.5px;
        }
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

        /* ── Bank details card ──────────────────────────────────────── */
        .bank {
            background: {{ $tint }};
            border: 1px solid {{ $line }};
            border-left: 3px solid {{ $accent }};
            padding: 12px 16px;
        }
        .bank td { padding: 3px 0; font-size: 10.5px; }
        .bank .lbl { color: {{ $inkMute }}; width: 130px; }
        .bank .val { color: {{ $ink }}; font-weight: 700; }
        .bank .mono { font-family: DejaVu Sans Mono, monospace; }

        /* ── Payments received ──────────────────────────────────────── */
        .payments th {
            text-align: left;
            font-size: 8.5px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: {{ $inkMute }};
            font-weight: 700;
            padding: 6px 6px;
            border-bottom: 1px solid {{ $line }};
        }
        .payments td {
            padding: 7px 6px;
            border-bottom: 1px solid #F0EDE4;
            font-size: 10.5px;
            color: {{ $inkSoft }};
        }

        /* ── Signatures ─────────────────────────────────────────────── */
        .sig-line {
            border-top: 1px solid {{ $ink }};
            padding-top: 5px;
            font-size: 10.5px;
            color: {{ $inkSoft }};
        }

        .notes {
            font-size: 10.5px;
            color: {{ $inkSoft }};
            white-space: pre-line;
            padding: 10px 0 0;
        }

        .footer {
            text-align: center;
            font-size: 9px;
            color: {{ $inkMute }};
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid {{ $line }};
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
                <div class="inv-eyebrow">Invoice</div>
                <div class="inv-number">{{ $invoice->number ?? 'Draft' }}</div>
                <div style="margin-top: 6px;">
                    <span class="badge">{{ strtoupper($invoice->status->value) }}</span>
                </div>
                @if ($invoice->issued_at)
                    <div class="inv-meta">Issued {{ $invoice->issued_at->toFormattedDateString() }}</div>
                @endif
                @if ($invoice->due_at)
                    <div class="inv-meta">Due {{ $invoice->due_at->toFormattedDateString() }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- ── Bill-to / Stay ─────────────────────────────────────────
         Corporate-guaranteed bookings bill the org first (that's who's
         actually paying the invoice), with the individual staff
         traveller as a Guest sub-line for reference. Mirrors the
         on-screen invoice show layout. --}}
    @php
        $pdfCorp = $invoice->booking?->corporate;
    @endphp
    <table style="margin-top: 24px;">
        <tr>
            <td style="width: 50%; padding-right: 20px;">
                <div class="section-title">Bill to</div>
                @if ($pdfCorp)
                    <div class="party-name">{{ $pdfCorp->name }}</div>
                    @if ($pdfCorp->billing_email)
                        <div class="party-contact">{{ $pdfCorp->billing_email }}</div>
                    @elseif ($pdfCorp->primary_email)
                        <div class="party-contact">{{ $pdfCorp->primary_email }}</div>
                    @endif
                    @if ($pdfCorp->primary_phone)
                        <div class="party-contact">{{ $pdfCorp->primary_phone }}</div>
                    @endif
                    @if ($pdfCorp->address || $pdfCorp->city)
                        <div class="party-contact">
                            {{ $pdfCorp->address }}@if ($pdfCorp->address && $pdfCorp->city), @endif{{ $pdfCorp->city }}
                        </div>
                    @endif
                    @if ($pdfCorp->tax_id)
                        <div class="party-contact" style="font-family: DejaVu Sans Mono, monospace; font-size: 10px;">
                            TAX ID · {{ $pdfCorp->tax_id }}
                        </div>
                    @endif
                    <div style="margin-top: 10px;">
                        <div class="party-contact" style="font-size: 10px; letter-spacing: 0.05em; text-transform: uppercase; color: #6E767D;">
                            Guest
                        </div>
                        <div class="party-contact" style="color: #14171A;">{{ $invoice->guest_name }}</div>
                        @if ($invoice->guest_phone)
                            <div class="party-contact">{{ $invoice->guest_phone }}</div>
                        @endif
                    </div>
                @else
                    <div class="party-name">{{ $invoice->guest_name }}</div>
                    @if ($invoice->guest_email)
                        <div class="party-contact">{{ $invoice->guest_email }}</div>
                    @endif
                    @if ($invoice->guest_phone)
                        <div class="party-contact">{{ $invoice->guest_phone }}</div>
                    @endif
                @endif
            </td>
            @if ($invoice->booking)
                <td style="width: 50%;">
                    <div class="section-title">Stay</div>
                    <div class="party-name" style="font-family: DejaVu Sans Mono, monospace; font-size: 11px;">
                        {{ $invoice->booking->booking_ref }}
                    </div>
                    <div class="party-contact">
                        {{ $invoice->booking->checkin_date?->toFormattedDateString() }}
                        &rarr;
                        {{ $invoice->booking->checkout_date?->toFormattedDateString() }}
                    </div>
                    <div class="party-contact">
                        {{ $invoice->booking->nights }} night{{ $invoice->booking->nights === 1 ? '' : 's' }}
                    </div>
                </td>
            @endif
        </tr>
    </table>

    {{-- ── Line items ────────────────────────────────────────────── --}}
    <table class="lines" style="margin-top: 24px;" data-testid="invoice-lines">
        <thead>
            <tr>
                <th class="desc">Description</th>
                <th style="text-align: right; width: 45px;">Qty</th>
                <th style="text-align: right; width: 90px;">Unit</th>
                <th style="text-align: right; width: 100px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="desc">{{ $line->description }}</td>
                    <td class="num ink-soft">{{ $line->quantity }}</td>
                    <td class="num ink-soft">{!! $money((int) $line->unit_price) !!}</td>
                    <td class="num" style="color: {{ $ink }};">{!! $money((int) $line->line_total) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── Totals ────────────────────────────────────────────────── --}}
    <table class="totals" style="margin-top: 12px;">
        <tr>
            <td class="lbl">Subtotal</td>
            <td class="num">{!! $money((int) $invoice->subtotal) !!}</td>
        </tr>
        @if ($invoice->amount_paid > 0)
            <tr>
                <td class="lbl">Already paid</td>
                <td class="num">&minus; {!! $money((int) $invoice->amount_paid) !!}</td>
            </tr>
        @endif
        <tr class="rule-top">
            <td class="lbl" style="color: {{ $ink }};">Balance owed</td>
            <td class="num">{!! $money((int) $invoice->balanceOwed()) !!}</td>
        </tr>
    </table>

    {{-- ── Notes ─────────────────────────────────────────────────── --}}
    @if ($invoice->notes)
        <div style="margin-top: 24px;">
            <div class="section-title">Notes</div>
            <div class="notes">{{ $invoice->notes }}</div>
        </div>
    @endif

    {{-- ── Bank details ──────────────────────────────────────────── --}}
    @if ($invoice->bank_name || $invoice->bank_account_number)
        <div style="margin-top: 24px;">
            <div class="section-title" style="margin-bottom: 6px;">Pay into</div>
            <div class="bank">
                <table>
                    @if ($invoice->bank_name)
                        <tr><td class="lbl">Bank</td><td class="val">{{ $invoice->bank_name }}</td></tr>
                    @endif
                    @if ($invoice->bank_account_name)
                        <tr><td class="lbl">Account name</td><td class="val">{{ $invoice->bank_account_name }}</td></tr>
                    @endif
                    @if ($invoice->bank_account_number)
                        <tr><td class="lbl">Account number</td><td class="val mono">{{ $invoice->bank_account_number }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    @endif

    {{-- ── Payments received (only if any exist) ─────────────────── --}}
    @if ($invoice->payments->isNotEmpty())
        <div style="margin-top: 24px;">
            <div class="section-title" style="margin-bottom: 8px;">Payments received</div>
            <table class="payments">
                <thead>
                    <tr>
                        <th style="width: 30%;">When</th>
                        <th style="width: 20%;">Method</th>
                        <th>Reference</th>
                        <th style="text-align: right; width: 100px;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->payments as $pay)
                        <tr>
                            <td>{{ ($pay->paid_at ?? $pay->created_at)?->toFormattedDateString() }}</td>
                            <td>{{ ucfirst($pay->method ?? 'paystack') }}</td>
                            <td class="mono" style="font-family: DejaVu Sans Mono, monospace; color: {{ $inkMute }};">
                                {{ $pay->reference_note ?? $pay->paystack_reference }}
                            </td>
                            <td class="num" style="text-align: right; font-family: DejaVu Sans Mono, monospace; color: {{ $ink }};">
                                {!! $money((int) $pay->amount) !!}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── Signature block ───────────────────────────────────────── --}}
    <table style="margin-top: 40px;">
        <tr>
            <td style="width: 50%; padding-right: 24px;">
                <div style="height: 30px;"></div>
                <div class="sig-line">Guest signature</div>
                <div style="color: {{ $inkMute }}; font-size: 10px; margin-top: 3px;">
                    Date: _______________
                </div>
            </td>
            <td style="width: 50%; padding-left: 24px;">
                <div style="height: 30px;"></div>
                <div class="sig-line">Received by ({{ $invoice->issuedBy?->name ?? 'Front desk' }})</div>
                <div style="color: {{ $inkMute }}; font-size: 10px; margin-top: 3px;">
                    Date: _______________
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Thank you for your stay at {{ $tenant?->name ?? 'our property' }}.
    </div>

</div>

</body>
</html>
