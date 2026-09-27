@props([
    // Amount in the property's currency's smallest whole unit
    // (naira / cedi / rand, etc.). Nullable so a caller can pass an
    // absent computation without a `??` on every callsite.
    'amount' => null,
    // Force a specific currency instead of the resolved default —
    // used when e.g. rendering an invoice PDF that must lock the
    // currency in which the invoice was originally raised.
    'currency' => null,
    // 'K/M' suffix for hero KPIs (`₦1.2M`) instead of the full
    // number. Same semantic as the helper's third arg.
    'suffix' => false,
    // Explicit sign styling. `null` (default) auto-detects negative
    // amounts. Pass `'neutral'` to force a positive-shaped render
    // (used for balances where zero + zero-negative should read the
    // same). Pass `'always'` to prefix `+` on positives (deltas).
    'sign' => null,
    // Semantic tag; muted callers pass `'weak'` so the render is
    // dimmed (e.g. secondary comparison figures).
    'tone' => null,
])

@php
    // The helper does the currency + formatting math already, so we
    // delegate for the raw string then wrap it in the class-driven
    // component chrome (tabular figures, right-align, minus-sign).
    $isNegative = is_numeric($amount) && $amount < 0;
    $isPositive = is_numeric($amount) && $amount > 0;
    $absolute = is_numeric($amount) ? abs($amount) : $amount;

    $formatted = money($absolute, $currency, $suffix);

    // Use the proper Unicode minus (U+2212) which lines up better with
    // digit metrics + reads as a numeric sign, not a hyphen.
    $prefix = match (true) {
        $isNegative                  => "\u{2212}",
        $sign === 'always' && $isPositive => '+',
        default                      => '',
    };
@endphp

<span {{ $attributes->merge([
        'class' => 'kv-money'
            .($isNegative ? ' kv-money--neg' : '')
            .($sign === 'always' && $isPositive ? ' kv-money--pos' : '')
            .($tone === 'weak' ? ' kv-money--weak' : ''),
        'data-money' => $absolute,
    ]) }}>
    @if ($prefix !== '')<span class="kv-money-sign">{{ $prefix }}</span>@endif
    <span class="kv-money-value">{{ $formatted }}</span>
</span>

@once
    <style>
        /* Ledger-grade number rendering. tabular-nums locks digit widths
           so columns of amounts align edge-to-edge; lining-nums pins
           the numerals to a consistent baseline (belt-and-braces vs
           fonts that ship old-style figures under 'ss01'). Kept as a
           single .kv-money class so we can dot-chain into modifier
           states + data attributes for testing. */
        .kv-money {
            display: inline-flex;
            align-items: baseline;
            gap: 0;
            font-family: var(--font-body, ui-sans-serif, system-ui);
            font-weight: 600;
            font-variant-numeric: tabular-nums lining-nums;
            font-feature-settings: 'tnum' 1, 'lnum' 1;
            letter-spacing: -0.005em;
            white-space: nowrap;
        }
        .kv-money-sign {
            /* Signs stay in-line with the digits, not superscripted;
               U+2212 handles its own vertical metric so we don't nudge. */
            margin-right: 0;
        }
        .kv-money--neg { color: #B85C42; }
        .kv-money--pos { color: #4C956C; }
        .kv-money--weak { opacity: 0.55; }
    </style>
@endonce
