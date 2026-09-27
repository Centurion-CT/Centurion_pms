{{-- Thermal-receipt-shaped POS receipt.

     Target: 80mm thermal roll (72mm printable content area). The
     markup is deliberately single-column so long product names get
     their own line (a 4-column table wraps ugly on any thermal
     paper narrower than about 90mm).

     Per-line idiom used across the industry:
        Product name
          qty × unit_price                    subtotal

     @page rule in resources/css/app.css sets the paper size for print.
     Everything else on the dashboard is hidden by the print isolation
     block below the CSS defs.  --}}
@php
    $property = $sale->outlet->property;
    $addressLine = collect([$property?->address, $property?->city, $property?->state])
        ->filter()
        ->implode(', ');
    $contactPhone = $sale->outlet->tenant?->phone;
@endphp
<div class="lp-receipt" data-testid="sale-receipt">
    <div class="lp-receipt__head">
        <p class="lp-receipt__brand">{{ $property?->name ?? $sale->outlet->name }}</p>
        @if ($addressLine !== '')
            <p class="lp-receipt__addr">{{ $addressLine }}</p>
        @endif
        @if ($contactPhone)
            <p class="lp-receipt__addr">Tel: {{ $contactPhone }}</p>
        @endif
        <p class="lp-receipt__outlet">{{ $sale->outlet->name }}</p>
        <p class="lp-receipt__ref">{{ $sale->reference }}</p>
        <p class="lp-receipt__ref">{{ optional($sale->sold_at)->format('D, j M Y · g:i A') ?? '' }}</p>
    </div>

    <div class="lp-receipt__sep">================================</div>

    <div class="lp-receipt__lines">
        @foreach ($sale->lines as $line)
            <div class="lp-receipt__line">
                <p class="lp-receipt__line-name">
                    {{ $line->name }}
                    @if ($line->is_complimentary)
                        <span class="lp-receipt__comp">(COMP)</span>
                    @endif
                </p>
                <p class="lp-receipt__line-sub">
                    <span>{{ $line->quantity }} × &#8358;{{ number_format($line->unit_price) }}</span>
                    <span>&#8358;{{ number_format($line->subtotal) }}</span>
                </p>
            </div>
        @endforeach
    </div>

    <div class="lp-receipt__sep">--------------------------------</div>

    <div class="lp-receipt__totals">
        <div><span>Subtotal</span><span>&#8358;{{ number_format($sale->subtotal) }}</span></div>
        @if ($sale->tax_amount > 0)
            <div><span>Tax</span><span>&#8358;{{ number_format($sale->tax_amount) }}</span></div>
        @endif
        <div class="grand"><span>TOTAL</span><span>&#8358;{{ number_format($sale->total) }}</span></div>
    </div>

    <div class="lp-receipt__sep">--------------------------------</div>

    <div class="lp-receipt__meta">
        @if ($sale->booking)
            <p>Charge to room</p>
            <p>{{ $sale->booking->guest_name }}</p>
            <p>Ref {{ $sale->booking->booking_ref }}</p>
        @elseif ($sale->is_complimentary)
            <p>COMPLIMENTARY SALE</p>
            @if ($sale->complimentary_reason)
                <p>{{ $sale->complimentary_reason }}</p>
            @endif
        @else
            @foreach ($sale->payments->where('status', 'succeeded') as $p)
                <p>
                    <span>{{ strtoupper($p->method) }}</span>
                    <span>&#8358;{{ number_format($p->amount) }}</span>
                </p>
            @endforeach
            @if ($sale->payments->isEmpty())
                <p>Payment: -</p>
            @endif
        @endif
        <p>&nbsp;</p>
        <p>Served by {{ $sale->operator?->name ?? '-' }}</p>
        @if ($sale->customer_name_snapshot || $sale->customer)
            <p>Customer: {{ $sale->customer?->name ?? $sale->customer_name_snapshot }}</p>
        @endif
        @if ($sale->notes)
            <p style="font-style: italic;">{{ $sale->notes }}</p>
        @endif
    </div>

    <div class="lp-receipt__sep">================================</div>

    <p class="lp-receipt__thanks">Thank you.</p>
</div>
