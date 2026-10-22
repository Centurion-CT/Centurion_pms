{{-- Two-step booking widget (approved mockups 2 + 3), shared by the
     room-type and single-room detail pages. One Livewire form; Alpine
     only toggles which half shows. Step 1: stay, extras, price. Step 2:
     guest details, payment, book. A failed submit jumps back to the step
     holding the first error.

     Expects: $bookable (RoomCategory|Room), $isCategory, $notAvailableText,
     $totalActive, $corporateContext, plus the component's public state
     ($isFree, $preview, $packages, $allowDeposit, discount fields). --}}
@php
    $couponOff = (int) ($appliedDiscountAmountOff ?? 0);
    $finalTotal = $preview ? max(0, $preview->totalDue - $couponOff) : null;
    $finalDeposit = $preview && $preview->depositAmount > 0 ? min($preview->depositAmount, $finalTotal) : 0;
    $stepOneError = $errors->hasAny(['checkin', 'checkout', 'adults', 'children']);
    $stepTwoError = ! $stepOneError && $errors->hasAny(['guest_name', 'guest_email', 'guest_phone', 'payment_choice']);
    $nightsLabel = $preview ? $preview->nights.' '.($preview->nights === 1 ? 'night' : 'nights') : null;
    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('D j M') : '';
@endphp
<form wire:submit="book" data-testid="booking-form" class="rd-widget"
      x-data="{ step: {{ $stepTwoError ? 2 : 1 }} }"
      x-init="Livewire.hook('morph.updated', () => {
          if ($el.querySelector('[data-step-one] .rd-err, [data-step-one] [data-testid=not-available]')) step = 1;
          else if ($el.querySelector('[data-step-two] .rd-err')) step = 2;
      })">

    {{-- ── Step 1: your stay ─────────────────────────────────── --}}
    <div data-step-one x-show="step === 1" class="rd-step-pane">
        <div class="rd-widget-head">
            <div class="rd-widget-price">₦{{ number_format($bookable->base_rate) }} <span class="per">/ night</span></div>
            <span class="rd-step">Step 1 of 2</span>
        </div>
        @if ($rating['count'] > 0)
            <div class="rd-widget-rating"><span class="star">★</span> <strong style="color: var(--ink);">{{ $rating['average'] }}</strong> from {{ $rating['count'] }} {{ $rating['count'] === 1 ? 'review' : 'reviews' }}</div>
        @endif

        <div class="rd-dates">
            <label><span class="rd-field-label">Check in</span>
                <input type="date" wire:model.live="checkin" required data-testid="checkin"></label>
            <label><span class="rd-field-label">Check out</span>
                <input type="date" wire:model.live="checkout" required data-testid="checkout"></label>
        </div>
        @error('checkin') <p data-error="checkin" class="rd-err">{{ $message }}</p> @enderror
        @error('checkout') <p data-error="checkout" class="rd-err">{{ $message }}</p> @enderror

        <div class="rd-field-row">
            <div><label class="rd-field-label" for="rd-adults">Adults</label>
                <input id="rd-adults" type="number" wire:model.number="adults" min="1" max="{{ $bookable->capacity_adults }}" required data-testid="adults"></div>
            <div><label class="rd-field-label" for="rd-children">Children</label>
                <input id="rd-children" type="number" wire:model.number="children" min="0" max="{{ $bookable->capacity_children }}" data-testid="children"></div>
        </div>
        @error('adults') <p data-error="adults" class="rd-err">{{ $message }}</p> @enderror
        @error('children') <p data-error="children" class="rd-err">{{ $message }}</p> @enderror

        @if ($isFree === false)
            <p data-testid="not-available" class="rd-warn">{{ $notAvailableText }}</p>
        @elseif ($isFree === true)
            <span class="rd-ok"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10.5l4 4 8-9"/></svg>Available for your dates</span>
        @endif
        <span class="rd-live" data-testid="rd-live-indicator" aria-live="polite"><span class="rd-live-dot" aria-hidden="true"></span>Live availability</span>

        @if ($isCategory && $bookable->hasBreakfastOption())
            <div class="rd-pay" data-testid="rate-tier-picker">
                <strong>Rate</strong>
                <label><input type="radio" wire:model.live="breakfast_included" value="0" data-testid="rate-standard">
                    <span class="rd-pay-text"><b>Room only</b></span><span class="rd-pay-amount">₦{{ number_format((int) $bookable->base_rate) }}/night</span></label>
                <label><input type="radio" wire:model.live="breakfast_included" value="1" data-testid="rate-breakfast">
                    <span class="rd-pay-text"><b>With breakfast</b></span><span class="rd-pay-amount">₦{{ number_format((int) $bookable->breakfast_included_rate) }}/night</span></label>
            </div>
        @endif

        @if ($packages->isNotEmpty())
            <div class="rd-packages" data-testid="packages-picker">
                <div class="rd-group-title">Add to your stay</div>
                @foreach ($packages as $pkg)
                    @php $maxQty = max(1, (int) $pkg->max_per_booking); @endphp
                    <label class="rd-pkg" data-testid="package-row-{{ $pkg->id }}">
                        @if ($maxQty === 1)
                            <input type="checkbox" wire:model.live="selectedPackages.{{ $pkg->id }}" value="1" data-testid="package-toggle-{{ $pkg->id }}">
                        @endif
                        <span class="rd-pkg-name">{{ $pkg->name }}@if ($pkg->description)<small>{{ $pkg->description }}</small>@endif</span>
                        @if ($maxQty > 1)
                            <input type="number" min="0" max="{{ $maxQty }}" class="rd-pkg-qty" wire:model.live="selectedPackages.{{ $pkg->id }}" data-testid="package-qty-{{ $pkg->id }}" aria-label="Quantity">
                        @endif
                        <span class="rd-pkg-price" data-testid="package-price-{{ $pkg->id }}" @if ($pkg->is_complimentary) style="color: var(--emerald);" @endif>
                            {{ $pkg->is_complimentary ? 'Complimentary' : '₦'.number_format($pkg->effectivePrice()) }}
                        </span>
                    </label>
                @endforeach
            </div>
        @endif

        <div class="rd-coupon" data-testid="coupon-block">
            @if ($appliedDiscountId)
                <div class="rd-coupon-applied" data-testid="coupon-applied">
                    <div class="row">
                        <span class="badge">{{ $discountAutoApplied ? 'Discount applied automatically' : 'Discount code applied' }}</span>
                        <button type="button" wire:click="clearDiscount" data-testid="coupon-clear" aria-label="Remove discount">×</button>
                    </div>
                    <div class="name">{{ $appliedDiscountName }}@if ($appliedDiscountCodeUsed)<span class="code">{{ $appliedDiscountCodeUsed }}</span>@endif</div>
                    <div class="off">−₦{{ number_format((int) $appliedDiscountAmountOff) }} off <span class="summary">{{ $appliedDiscountSummary }}</span></div>
                </div>
            @else
                <details @if ($discountError) open @endif>
                    <summary style="list-style: none; cursor: pointer; color: var(--blue-600); font-weight: 600; font-size: 14px;">Have a discount code?</summary>
                    <div class="rd-coupon-row" style="margin-top: 8px;">
                        <input id="rd-coupon" type="text" wire:model="discountCode" wire:keydown.enter.prevent="applyDiscount" data-testid="coupon-code" placeholder="e.g. SUMMER25" style="text-transform: uppercase;" aria-label="Discount code">
                        <button type="button" wire:click="applyDiscount" data-testid="coupon-apply">
                            <span wire:loading.remove wire:target="applyDiscount">Apply</span><span wire:loading wire:target="applyDiscount">…</span>
                        </button>
                    </div>
                </details>
                @if ($discountError)
                    <p class="rd-err" data-testid="coupon-error" style="margin: 0;">{{ $discountError }}</p>
                @endif
            @endif
        </div>

        @if ($preview)
            <div class="rd-summary" data-testid="price-preview">
                @php $rates = collect($preview->nightlyRates ?? [])->map(fn ($n) => (int) ($n->rate ?? $n))->unique()->values()->all(); @endphp
                <div class="row"><span class="mute">@if (count($rates) === 1)₦{{ number_format($rates[0]) }} × {{ $nightsLabel }}@else{{ ucfirst($nightsLabel) }}@endif</span><span>₦{{ number_format($preview->subtotal) }}</span></div>
                @if ($preview->discountAmount > 0)
                    <div class="row discount"><span>Long-stay discount, {{ $preview->discountPercent }}%</span><span>−₦{{ number_format($preview->discountAmount) }}</span></div>
                @endif
                @if ($preview->packagesTotal > 0)
                    <div class="row" data-testid="preview-packages-row"><span class="mute">Extras</span><span>₦{{ number_format($preview->packagesTotal) }}</span></div>
                @endif
                @if ($couponOff > 0)
                    <div class="row discount" data-testid="preview-coupon-row"><span>{{ $appliedDiscountName }}</span><span>−₦{{ number_format($couponOff) }}</span></div>
                @endif
                @if ($preview->paystackFee > 0)
                    <div class="row"><span class="mute">Card processing fee</span><span>₦{{ number_format($preview->paystackFee) }}</span></div>
                @endif
                @if ($preview->kevernaFee > 0)
                    <div class="row"><span class="mute">Service fee</span><span>₦{{ number_format($preview->kevernaFee) }}</span></div>
                @endif
                <div class="row total"><span>Total</span><span>₦<span data-testid="preview-total">{{ number_format($finalTotal) }}</span></span></div>
            </div>
        @endif

        <button type="button" class="rd-next-btn" data-testid="booking-continue" @click="step = 2" @disabled($isFree === false || ! $preview)>
            Continue to your details
        </button>
        <p class="rd-trust">{{ $preview ? "You won't be charged yet." : 'Pick your dates to see the total.' }}</p>
    </div>

    {{-- ── Step 2: your details + payment ────────────────────── --}}
    <div data-step-two x-show="step === 2" x-cloak class="rd-step-pane">
        <div class="rd-widget-head">
            <button type="button" class="rd-back" @click="step = 1">← Edit your stay</button>
            <span class="rd-step">Step 2 of 2</span>
        </div>
        <div class="rd-stay-card">
            <b>{{ $bookable->name }}</b>
            @if ($preview)
                <span>{{ $fmtDate($checkin) }} to {{ $fmtDate($checkout) }}, {{ $nightsLabel }}</span>
            @endif
            <span>{{ $adults }} {{ (int) $adults === 1 ? 'adult' : 'adults' }}@if ((int) $children > 0), {{ $children }} {{ (int) $children === 1 ? 'child' : 'children' }}@endif</span>
        </div>

        <div class="rd-group-title" style="margin-top: 4px;">Who's staying?</div>
        <div class="rd-field"><label class="rd-field-label" for="rd-name">Full name</label>
            <input id="rd-name" type="text" wire:model="guest_name" required maxlength="120" autocomplete="name" data-testid="guest-name"></div>
        @error('guest_name') <p data-error="guest_name" class="rd-err">{{ $message }}</p> @enderror

        <div class="rd-field"><label class="rd-field-label" for="rd-email">Email, for your confirmation</label>
            <input id="rd-email" type="email" wire:model.live.debounce.500ms="guest_email" required autocomplete="email" data-testid="guest-email"></div>
        @error('guest_email') <p data-error="guest_email" class="rd-err">{{ $message }}</p> @enderror

        @if ($corporateContext !== null)
            @php $cc = $corporateContext; @endphp
            <div class="rd-corp-banner" data-testid="corporate-banner" style="padding: 12px 14px; background: var(--blue-50); border: 1px solid #C9DAEC; border-radius: 8px; display: grid; gap: 6px; font-size: 14px;">
                <strong style="color: var(--blue-600);">Corporate account: {{ $cc['corporate']->name }}</strong>
                @if ($cc['member'] !== null)<span style="color: var(--ink-soft);">Welcome back, {{ $cc['member']->name }}.</span>@endif
                @if (! $cc['propertyAccepts'])
                    <span style="color: var(--ink-soft);">This property doesn't accept corporate billing yet, so your booking uses the public rate.</span>
                @elseif ($cc['rate'] !== null && $cc['totalSaving'] > 0)
                    <span>Your negotiated rate applies and the stay is billed to {{ $cc['corporate']->name }}'s wallet. You save <strong>₦{{ number_format($cc['totalSaving']) }}</strong>.</span>
                @else
                    <span>Your stay is billed to {{ $cc['corporate']->name }}'s corporate wallet. No card needed.</span>
                @endif
                @if ($cc['capWarning'] !== null)
                    <span data-testid="corp-cap-warn" style="color: var(--rose);">{{ $cc['capWarning'] }}</span>
                @endif
            </div>
        @endif

        <div class="rd-field"><label class="rd-field-label" for="rd-phone">Phone (WhatsApp)</label>
            <input id="rd-phone" type="tel" wire:model="guest_phone" required autocomplete="tel" data-testid="guest-phone" placeholder="+234 800 000 0000"></div>
        @error('guest_phone') <p data-error="guest_phone" class="rd-err">{{ $message }}</p> @enderror

        <div class="rd-field"><label class="rd-field-label" for="rd-special">Anything we should know? (optional)</label>
            <textarea id="rd-special" wire:model="special_requests" rows="2" maxlength="1000" data-testid="special-requests" placeholder="Arrival time, early check-in, dietary notes"></textarea></div>

        <div class="rd-pay">
            <strong>How would you like to pay?</strong>
            <label><input type="radio" wire:model="payment_choice" value="full" data-testid="payment-full">
                <span class="rd-pay-text"><b>Pay in full</b><span>Nothing more to pay at the desk.</span></span>
                @if ($finalTotal !== null)<span class="rd-pay-amount">₦{{ number_format($finalTotal) }}</span>@endif</label>
            @if ($allowDeposit && $preview && $preview->depositAmount > 0)
                <label><input type="radio" wire:model="payment_choice" value="deposit" data-testid="payment-deposit">
                    <span class="rd-pay-text"><b>Pay a deposit</b><span>₦{{ number_format($finalTotal - $finalDeposit) }} due when you arrive.</span></span>
                    <span class="rd-pay-amount">₦{{ number_format($finalDeposit) }}</span></label>
            @endif
        </div>
        @error('payment_choice') <p data-error="payment_choice" class="rd-err">{{ $message }}</p> @enderror

        <button type="submit" data-testid="book-button" @disabled($isFree === false) class="rd-book-btn">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M7 9V6a3 3 0 0 1 6 0v3"/></svg>
            <span wire:loading.remove wire:target="book">Book and pay securely</span>
            <span wire:loading wire:target="book">Securing your dates…</span>
        </button>
        <p class="rd-trust">
            Card, bank transfer or USSD through Paystack.
            @if ($isCategory && $totalActive > 1)<br>We'll assign your specific room when your booking is confirmed.@endif
        </p>
    </div>
</form>
