{{--
    Reusable itemised price breakdown. Matches exactly what
    `CreateBooking::handle()` will apply on submit (see
    app/Actions/Bookings/CreateBooking.php:64-119) so what the
    operator previews is what the booking receives.

    Params:
      - $breakdown              : PricingBreakdown  (required)
      - $discountOff            : int               (default 0), DiscountEngine's amountOff
      - $discountLabel          : ?string           (default null), rule name for display
      - $discountCode           : ?string           (default null), manually-typed code (if any)
      - $discountAutoApplied    : bool              (default false), auto-apply vs manual
      - $corporateDiscountOff   : int               (default 0), CorporateBookingService applies at attach time
      - $corporateDiscountLabel : ?string           (default null), "Acme corp rate · 15% off"
      - $showDeposit            : bool              (default false), surface the on-hold deposit line
      - $compact                : bool              (default false), smaller variant for tight modal spaces
--}}
@props([
    'breakdown',
    'discountOff' => 0,
    'discountLabel' => null,
    'discountCode' => null,
    'discountAutoApplied' => false,
    'corporateDiscountOff' => 0,
    'corporateDiscountLabel' => null,
    'showDeposit' => false,
    'compact' => false,
])

@php
    /** @var \App\Services\Pricing\PricingBreakdown $breakdown */
    // Net total mirrors CreateBooking's `finalRoomTotal` for non-fee
    // channels (walk-in / OTA / phone), the operator-out-of-pocket
    // number, not the Paystack/Keverna-fee-inclusive one.
    $finalTotal = max(0, $breakdown->netSubtotal - (int) $discountOff - (int) $corporateDiscountOff);
    $bodyClass = $compact ? 'text-[12.5px]' : 'text-[13px]';
    $rowStyle  = 'display: flex; justify-content: space-between; align-items: baseline; gap: 12px; padding: 6px 0;';
@endphp

<div class="lp-price-breakdown"
     data-testid="price-breakdown"
     style="border: 1px solid var(--color-line-soft); border-radius: 10px;
            padding: {{ $compact ? '12px 14px' : '14px 16px' }};
            background: var(--color-card);">

    {{-- Per-night rate table. Long-stay mode still emits one row per
         night (evenly-distributed effective rate) so the header makes
         sense either way. --}}
    <div>
        <p class="lp-mono {{ $compact ? 'text-[10.5px]' : 'text-[11px]' }}"
           style="color: var(--color-ink-mute); letter-spacing: 0.14em;
                  text-transform: uppercase; margin: 0 0 8px;">
            @if ($breakdown->longStayPricing)
                Long-stay pricing · {{ $breakdown->nights }} {{ \Illuminate\Support\Str::plural('night', $breakdown->nights) }}
            @else
                Nightly rates · {{ $breakdown->nights }} {{ \Illuminate\Support\Str::plural('night', $breakdown->nights) }}
            @endif
        </p>
        <ul style="display: grid; gap: 2px; list-style: none; padding: 0; margin: 0;">
            @foreach ($breakdown->nightlyRates as $nr)
                <li class="{{ $bodyClass }}"
                    style="{{ $rowStyle }} color: var(--color-ink-soft);">
                    <span>
                        {{ $nr->date->format('D · j M') }}
                        @if ($nr->source === 'override' && $nr->overrideLabel)
                            <span class="lp-mono text-[10px]"
                                  style="color: var(--color-ink-mute); margin-left: 4px;">
                                · {{ $nr->overrideLabel }}
                            </span>
                        @endif
                    </span>
                    <span class="lp-mono" style="color: var(--color-ink);">
                        &#8358;{{ number_format($nr->rate) }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    <div style="margin: 10px 0 0; border-top: 1px solid var(--color-line-soft);"></div>

    {{-- Subtotal --}}
    <div class="{{ $bodyClass }}" style="{{ $rowStyle }} color: var(--color-ink);">
        <span>Subtotal</span>
        <span class="lp-mono">&#8358;{{ number_format($breakdown->subtotal) }}</span>
    </div>

    {{-- Length-of-stay auto discount (weekly ≥7 nights, monthly ≥28) --}}
    @if ($breakdown->discountAmount > 0)
        <div class="{{ $bodyClass }}"
             data-testid="price-breakdown-stay-discount"
             style="{{ $rowStyle }} color: var(--color-accent-deep);">
            <span>
                {{ $breakdown->nights >= 28 ? 'Monthly' : 'Weekly' }} discount
                ({{ $breakdown->discountPercent }}%)
            </span>
            <span class="lp-mono">
                &minus;&#8358;{{ number_format($breakdown->discountAmount) }}
            </span>
        </div>
    @endif

    {{-- Auto-apply / manual coupon Discount --}}
    @if ((int) $discountOff > 0)
        <div class="{{ $bodyClass }}"
             data-testid="price-breakdown-discount"
             style="{{ $rowStyle }} color: var(--color-accent-deep);">
            <span>
                @if ($discountAutoApplied)
                    Auto-applied · {{ $discountLabel ?? 'Discount' }}
                @else
                    Coupon · {{ $discountLabel ?? $discountCode ?? 'Discount' }}
                    @if ($discountCode && $discountLabel && $discountLabel !== $discountCode)
                        <span class="lp-mono text-[10px]" style="color: var(--color-ink-mute); margin-left: 4px;">
                            ({{ $discountCode }})
                        </span>
                    @endif
                @endif
            </span>
            <span class="lp-mono">
                &minus;&#8358;{{ number_format((int) $discountOff) }}
            </span>
        </div>
    @endif

    {{-- Corporate rate + LoS discount. Applies whenever the guest is
         a matched corp member — CorporateBookingService::attachToBooking
         will land the same amount on the folio, so previewing it here
         keeps the total honest. --}}
    @if ((int) $corporateDiscountOff > 0)
        <div class="{{ $bodyClass }}"
             data-testid="price-breakdown-corporate-discount"
             style="{{ $rowStyle }} color: var(--color-accent-deep);">
            <span>{{ $corporateDiscountLabel ?? 'Corporate rate' }}</span>
            <span class="lp-mono">
                &minus;&#8358;{{ number_format((int) $corporateDiscountOff) }}
            </span>
        </div>
    @endif

    {{-- Net total, what CreateBooking will land as room_total for
         non-fee channels. --}}
    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--color-line-soft);">
        <div class="{{ $compact ? 'text-[14px]' : 'text-[15px]' }}"
             data-testid="price-breakdown-total"
             style="{{ $rowStyle }} color: var(--color-ink); font-weight: 600; padding: 0;">
            <span>Net total</span>
            <span class="lp-mono">&#8358;{{ number_format($finalTotal) }}</span>
        </div>
        <p class="lp-mono {{ $compact ? 'text-[10.5px]' : 'text-[11px]' }}"
           style="color: var(--color-ink-mute); margin: 4px 0 0; text-align: right;">
            @if ($breakdown->longStayPricing)
                monthly rate &middot; no extra length-of-stay discount
            @else
                what the guest owes &middot; before any package add-ons
            @endif
        </p>
    </div>

    {{-- Optional deposit hint, surfaces the amount held-deposit
         bookings will need before check-in. --}}
    @if ($showDeposit && $breakdown->depositAmount > 0)
        <div style="margin-top: 10px; padding: 8px 10px;
                    background: var(--color-card-deep); border-radius: 6px;">
            <div class="{{ $bodyClass }}"
                 data-testid="price-breakdown-deposit"
                 style="{{ $rowStyle }} color: var(--color-ink); padding: 0;">
                <span>Deposit if on-hold</span>
                <span class="lp-mono">&#8358;{{ number_format($breakdown->depositAmount) }}</span>
            </div>
        </div>
    @endif
</div>
