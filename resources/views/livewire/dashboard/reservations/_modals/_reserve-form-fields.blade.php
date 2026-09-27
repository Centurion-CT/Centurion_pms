{{--
    Shared reserve-form fields. Included by both the standalone
    reserve-sheet modal and the day-detail modal's "reserve" mode so
    the form logic lives in exactly one place.

    Caller supplies the <form wire:submit> wrapper and the trailing
    button row (Cancel / Advanced link / Back to summary / Submit).
--}}
<div>
    <label for="r-phone" class="lp-form-label">Phone</label>
    <input id="r-phone" type="tel" required maxlength="20"
           wire:model.live.debounce.500ms="reservePhone"
           data-testid="reserve-phone" class="lp-input"
           placeholder="080…  (matches existing customer)">
    @if ($reserveMatchedCustomerId !== null)
        <p class="text-[11.5px] mt-1" data-testid="reserve-matched-customer"
           style="color: var(--color-accent-deep);">
            ✓ Existing customer &middot; wallet balance
            &#8358;{{ number_format($reserveWalletBalance) }}
        </p>
    @endif
    @if ($reserveMatchedCorpId !== null)
        <div class="mt-2" data-testid="reserve-matched-corp"
             style="padding: 10px 12px; border-radius: 10px;
                    background: rgba(122, 168, 116, 0.08);
                    border: 1px solid rgba(122, 168, 116, 0.35);">
            <p class="text-[12px]" style="color: var(--color-ink); margin: 0;">
                <strong>Corporate staff match</strong> · {{ $reserveMatchedCorpName }}
            </p>
            @if ($reserveMatchedCorpReservationId !== null && $reserveCorpReservationIsCovered)
                <p class="text-[11.5px] mt-1" style="color: var(--color-ink-soft); margin: 0;">
                    Falls on the {{ $reserveMatchedCorpReservationWindow }} reservation.
                    Allocation:
                    @foreach ($reserveMatchedCorpReservationCategories as $i => $catRow)
                        {{ $catRow['rooms'] }}× {{ $catRow['name'] }}@if ($i !== count($reserveMatchedCorpReservationCategories) - 1), @endif
                    @endforeach
                </p>
                <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px;
                              cursor: pointer;">
                    <input type="checkbox"
                           wire:model.live="reserveUseCorporateReservation"
                           data-testid="reserve-use-corp-reservation"
                           style="accent-color: var(--color-accent-deep);">
                    <span class="text-[12px]" style="color: var(--color-ink);">
                        Use as corporate reservation
                        <span style="color: var(--color-ink-mute); font-weight: 400;">
                            (fills a slot on the reservation; corp rate + LoS discount apply)
                        </span>
                    </span>
                </label>
            @elseif ($reserveMatchedCorpReservationId !== null)
                {{-- Discovery mode: a live reservation exists for this
                     member but the operator hasn't set dates that
                     overlap it yet. Show the hint so they know which
                     window to pick. --}}
                <p class="text-[11.5px] mt-1" data-testid="reserve-corp-reservation-hint"
                   style="color: var(--color-ink-soft); margin: 0;">
                    <strong style="color: var(--color-ink);">Expected on the {{ $reserveMatchedCorpReservationWindow }} reservation.</strong>
                    Allocation:
                    @foreach ($reserveMatchedCorpReservationCategories as $i => $catRow)
                        {{ $catRow['rooms'] }}× {{ $catRow['name'] }}@if ($i !== count($reserveMatchedCorpReservationCategories) - 1), @endif
                    @endforeach.
                    Set check-in / check-out to that window to fill the slot.
                </p>
            @else
                <p class="text-[11.5px] mt-1" style="color: var(--color-ink-soft); margin: 0;">
                    Corp rates + LoS discount will apply automatically. Set up a corporate
                    reservation for this window if you want to reserve rooms ahead.
                </p>
            @endif
        </div>
    @endif
    @error('reservePhone') <p class="lp-form-error">{{ $message }}</p> @enderror
</div>

<div>
    <label for="r-name" class="lp-form-label">Guest name</label>
    <input id="r-name" type="text" wire:model="reserveGuestName" required maxlength="120"
           data-testid="reserve-guest-name" class="lp-input"
           placeholder="{{ $reserveMatchedCustomerId ? 'Auto-filled from customer' : 'e.g. Ada Okoye' }}">
    @error('reserveGuestName') <p class="lp-form-error">{{ $message }}</p> @enderror
</div>

{{-- Category-only toggle. When ON, replaces the room picker with a
     category picker + free-units hint; skips discount / breakfast /
     payment (the reservation lands as Pending, front desk assigns a
     room + collects payment at check-in). --}}
<label data-testid="reserve-category-only-toggle"
       style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;
              padding: 10px 12px; border: 1px solid var(--color-hairline);
              border-radius: 10px; background: var(--color-surface-2);">
    <input type="checkbox" wire:model.live="reserveCategoryOnly"
           data-testid="reserve-category-only-checkbox"
           style="margin-top: 3px; accent-color: var(--color-accent-deep);">
    <span style="display: grid; gap: 3px;">
        <span style="font-weight: 600; color: var(--color-ink); font-size: 13px;">
            Reserve against category only
        </span>
        <span style="font-size: 12px; color: var(--color-ink-soft); line-height: 1.4;">
            Hold a slot in a category. Front desk picks the room at check-in.
        </span>
    </span>
</label>

@if ($reserveCategoryOnly)
    @php
        $reserveCategories = $this->categoriesForReserve();
        $reserveCategoryFree = $this->categoryFreeUnitsForReserve();
        $reservePickedCategory = $reserveCategoryId
            ? $reserveCategories->firstWhere('id', (int) $reserveCategoryId)
            : null;
    @endphp
    <div>
        <label for="r-category" class="lp-form-label">Category</label>
        <select id="r-category" wire:model.live="reserveCategoryId"
                data-testid="reserve-category-picker" class="lp-input">
            <option value="">Pick a category</option>
            @foreach ($reserveCategories as $cat)
                <option value="{{ $cat->id }}">
                    {{ $cat->name }}
                    @if ($cat->property) ({{ $cat->property->name }}) @endif
                    · ₦{{ number_format((int) $cat->base_rate) }}/night
                </option>
            @endforeach
        </select>
        @error('reserveCategoryId') <p class="lp-form-error">{{ $message }}</p> @enderror

        @if ($reservePickedCategory && $reserveCategoryFree !== null)
            <p data-testid="reserve-category-free-hint" class="mt-1"
               style="font-size: 12px; color: {{ $reserveCategoryFree > 0 ? 'var(--color-ink-soft)' : 'var(--color-danger)' }};">
                @if ($reserveCategoryFree > 0)
                    {{ $reserveCategoryFree }} unit{{ $reserveCategoryFree === 1 ? '' : 's' }} free for those dates.
                @else
                    Fully booked for those dates. Pick different dates or a different category.
                @endif
            </p>
        @endif
    </div>
@else
    <div>
        <label class="lp-form-label">
            Room
            <span style="color: var(--color-ink-mute); font-weight: 400;">
                &middot; only rooms available for the picked dates
            </span>
        </label>
        @include('livewire.dashboard._partials.searchable-room-picker', [
            'rooms' => $availableRooms,
            'modelPath' => 'reserveRoomId',
            'idPrefix' => 'reserve-room',
            'currentId' => $reserveRoomId,
            'placeholder' => $availableRooms->isEmpty()
                ? 'No rooms available for these dates'
                : 'Pick an available room',
            'disabled' => $availableRooms->isEmpty(),
        ])
        @error('reserveRoomId') <p class="lp-form-error">{{ $message }}</p> @enderror
    </div>
@endif

{{-- Breakfast tier, only when the picked room's category offers it.
     Category-only path skips this - discount + breakfast + payment
     all assume a concrete Room and rerun when front desk pins one
     at check-in. --}}
@if (! $reserveCategoryOnly)
@php
    $reservePickedRoom = $reserveRoomId
        ? $availableRooms->firstWhere('id', (int) $reserveRoomId)
        : null;
@endphp
@if ($reservePickedRoom && $reservePickedRoom->category?->hasBreakfastOption())
    @include('livewire.dashboard._partials.breakfast-rate-radio', [
        'category' => $reservePickedRoom->category,
        'modelPath' => 'reserveBreakfastIncluded',
        'idPrefix' => 'reserve',
    ])
@endif
@endif

<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label for="r-checkin" class="lp-form-label">Check-in</label>
        <input id="r-checkin" type="date" wire:model.live="reserveCheckin" required
               data-testid="reserve-checkin" class="lp-input">
        @error('reserveCheckin') <p class="lp-form-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="r-checkout" class="lp-form-label">Check-out</label>
        <input id="r-checkout" type="date" wire:model.live="reserveCheckout" required
               data-testid="reserve-checkout" class="lp-input">
        @error('reserveCheckout') <p class="lp-form-error">{{ $message }}</p> @enderror
    </div>
</div>

@if (! $reserveCategoryOnly)
@php
    $reservePreview = $this->reservePricingPreview();
@endphp
@if ($reservePreview !== null)
    @include('livewire.dashboard._partials.price-breakdown', [
        'breakdown' => $reservePreview['breakdown'],
        'discountOff' => $reservePreview['discountOff'],
        'discountLabel' => $reservePreview['discountLabel'],
        'discountAutoApplied' => $reservePreview['discountAutoApplied'],
        'corporateDiscountOff' => $reservePreview['corporateDiscountOff'] ?? 0,
        'corporateDiscountLabel' => $reservePreview['corporateDiscountLabel'] ?? null,
        'showDeposit' => $reservePaymentStatus === 'on_hold',
        'compact' => true,
    ])

    {{-- Manual front-desk discount. Hidden when a corporate rate is
         in play so the two negotiated discounts don't stack awkwardly
         — the corp rate is the pre-agreed contract; the manual is for
         ad-hoc walk-in flex. The server also zeros the manual state
         (see refreshReserveEstimate) so a discount picked earlier
         doesn't post silently if the operator dates into a corp
         window mid-flow.

         Everything past the mode toggle runs in Alpine — slider moves
         + input keystrokes update the "new total" line instantly,
         no per-tick server round-trip. State is @entangle'd to
         Livewire so submitReserve reads the current values. When the
         operator changes room/dates (the only inputs that shift the
         auto-apply Discount + net subtotal), `preTotal` is refreshed
         from the server-provided reservePricingPreview() below. --}}
    @if ((int) ($reservePreview['corporateDiscountOff'] ?? 0) === 0)
    <div style="border-top: 1px solid var(--color-line-soft); padding-top: 12px; margin-top: 6px;"
         x-data="{
            mode: @entangle('reserveManualDiscountType'),
            pct: @entangle('reserveManualDiscountPercent'),
            fixed: @entangle('reserveManualDiscountFixedInput'),
            preTotal: {{ (int) ($reservePreview['preManualTotal'] ?? 0) }},
            /* Corporate discount is applied AFTER manual server-side,
               so the newTotal preview subtracts it from the post-
               manual total to match what CreateBooking + attachToBooking
               will actually land as booking.total_amount. */
            corpOff: {{ (int) ($reservePreview['corporateDiscountOff'] ?? 0) }},
            get amount() {
                if (this.mode === 'fixed') {
                    return Math.max(0, Math.min(Number(this.fixed) || 0, this.preTotal));
                }
                const p = Math.max(0, Math.min(100, Number(this.pct) || 0));
                return Math.floor(this.preTotal * p / 100);
            },
            get newTotal() { return Math.max(0, this.preTotal - this.amount - this.corpOff); },
            money(n) { return '₦' + Number(n || 0).toLocaleString(); },
         }">
        <div class="flex items-center justify-between">
            <label for="r-manual-discount" class="lp-form-label" style="margin: 0;">
                Manual discount
                <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
            </label>
            {{-- Mode toggle: %  /  ₦. Alpine-only, no Livewire trip
                 — @entangle syncs on the next server action. --}}
            <div class="lp-mode-toggle inline-flex" role="group" aria-label="Discount type"
                 style="background: var(--color-card-deep); border-radius: 999px; padding: 2px; gap: 2px;">
                <button type="button"
                        @click="mode = 'percent'; fixed = 0"
                        data-testid="reserve-manual-discount-type-percent"
                        :aria-pressed="mode === 'percent'"
                        :style="'padding: 3px 10px; border: 0; cursor: pointer; font-size: 11px;'
                              + 'border-radius: 999px; font-family: inherit;'
                              + 'background: ' + (mode === 'percent' ? 'var(--color-card)' : 'transparent') + ';'
                              + 'color: ' + (mode === 'percent' ? 'var(--color-ink)' : 'var(--color-ink-mute)') + ';'
                              + 'font-weight: ' + (mode === 'percent' ? '600' : '400') + ';'">
                    %
                </button>
                <button type="button"
                        @click="mode = 'fixed'; pct = 0"
                        data-testid="reserve-manual-discount-type-fixed"
                        :aria-pressed="mode === 'fixed'"
                        :style="'padding: 3px 10px; border: 0; cursor: pointer; font-size: 11px;'
                              + 'border-radius: 999px; font-family: inherit;'
                              + 'background: ' + (mode === 'fixed' ? 'var(--color-card)' : 'transparent') + ';'
                              + 'color: ' + (mode === 'fixed' ? 'var(--color-ink)' : 'var(--color-ink-mute)') + ';'
                              + 'font-weight: ' + (mode === 'fixed' ? '600' : '400') + ';'">
                    ₦
                </button>
            </div>
        </div>

        {{-- Percent mode: slider + numeric entry. x-model keeps
             Alpine's `pct` in sync (client-side, no wire trip); the
             @entangle above bridges to Livewire when a server action
             fires (submit, room/date change). @change fires once on
             slider release / number-input blur — that single trip
             refreshes `reserveEstimatedTotal` so downstream Alpine
             (deposit hint, wallet-covers check) sees the new total.
             No per-tick churn during the slide. --}}
        <div x-show="mode === 'percent'" class="flex items-center gap-3 mt-2">
            <input id="r-manual-discount" type="range" min="0" max="100" step="0.5"
                   x-model.number="pct"
                   @change="$wire.$refresh()"
                   @keydown.enter.prevent
                   data-testid="reserve-manual-discount-slider"
                   style="flex: 1; accent-color: var(--color-accent-deep);"
                   aria-label="Manual discount percent">
            <div style="position: relative; width: 84px;">
                <input type="number" min="0" max="100" step="0.01"
                       x-model.number="pct"
                       @change="$wire.$refresh()"
                       @keydown.enter.prevent
                       data-testid="reserve-manual-discount-input"
                       class="lp-input"
                       style="width: 100%; text-align: right; padding-right: 22px;"
                       aria-label="Manual discount percent (direct entry)">
                <span style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
                             font-size: 11px; color: var(--color-ink-mute); pointer-events: none;">%</span>
            </div>
        </div>

        {{-- Fixed mode: flat ₦ amount. --}}
        <div x-show="mode === 'fixed'" x-cloak class="mt-2" style="position: relative;">
            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                         font-size: 13px; color: var(--color-ink-mute); pointer-events: none;">₦</span>
            <input type="number" min="0" step="1"
                   x-model.number="fixed"
                   @change="$wire.$refresh()"
                   @keydown.enter.prevent
                   data-testid="reserve-manual-discount-fixed-input"
                   class="lp-input"
                   style="width: 100%; padding-left: 24px;"
                   placeholder="e.g. 5000"
                   aria-label="Manual discount, flat ₦ amount">
        </div>

        <p x-show="amount > 0" x-cloak
           class="text-[12px] mt-2" data-testid="reserve-manual-discount-line"
           style="color: var(--color-accent-deep);">
            −<span x-text="money(amount)"></span> off ·
            new total <strong>&#8358;<span x-text="Number(newTotal).toLocaleString()"></span></strong>
            <span style="color: var(--color-ink-mute);">
                · recorded against your operator profile for audit
            </span>
        </p>
    </div>
    @endif
@endif
@endif {{-- /not-category-only pricing preview wrapper --}}

<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label for="r-adults" class="lp-form-label">Adults</label>
        <input id="r-adults" type="number" min="1" step="1" wire:model="reserveAdults" required
               data-testid="reserve-adults" class="lp-input">
        @error('reserveAdults') <p class="lp-form-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="r-payment" class="lp-form-label">Payment</label>
        {{-- Plain wire:model (deferred). The conditional deposit /
             method / wallet blocks below toggle via Alpine x-show,
             so switching the status does NOT round-trip the server
             or re-render the modal, it's an instant local flip.
             Wallet path is hidden on the category-only route since
             the guest may not be linked to a customer yet. --}}
        <select id="r-payment" wire:model="reservePaymentStatus" required
                data-testid="reserve-payment" class="lp-input">
            @if ($reserveMatchedCorpId !== null)
                {{-- Corporate-invoice path: booking is Confirmed but
                     no cash / card is collected — the corp settles via
                     the invoice cycle after the stay. Only offered
                     when a corp match exists so a walk-in guest can't
                     mistakenly land here. --}}
                <option value="corp_invoice" data-testid="reserve-payment-corp-invoice">
                    Bill to {{ $reserveMatchedCorpName ?: 'corporate' }} (invoice later)
                </option>
            @endif
            <option value="confirmed">Paid in full, confirmed</option>
            <option value="on_hold">Deposit only, on hold</option>
            <option value="pending">Pending, guest will pay later</option>
            @if (! $reserveCategoryOnly && $reserveMatchedCustomerId !== null && $reserveWalletBalance > 0)
                <option value="wallet" data-testid="reserve-payment-wallet">
                    Pay from wallet
                    (&#8358;{{ number_format($reserveWalletBalance) }} available)
                </option>
            @endif
        </select>
        @error('reservePaymentStatus') <p class="lp-form-error">{{ $message }}</p> @enderror
    </div>
</div>

{{-- Alpine scope for the payment-status-dependent blocks. Entangling
     each Livewire property lets the operator flip between statuses
     without a server round-trip; the properties still submit correctly
     on save because Livewire flushes entangled state before actions.

     Amount / outstanding math is done client-side too so the hint line
     updates the instant the operator types, without waiting for the
     wire:model.live debounce to hit the server. Works uniformly for
     the specific-room and category-only paths - reserveEstimatedTotal
     is populated in both. --}}
<div
    x-data="{
        status: @entangle('reservePaymentStatus'),
        amount: @entangle('reservePaymentAmount'),
        minDeposit: @entangle('reserveMinDeposit'),
        total: @entangle('reserveEstimatedTotal'),
        walletBalance: @entangle('reserveWalletBalance'),
        hasCustomer: @js($reserveMatchedCustomerId !== null),
        money(n) { return '₦' + Number(n || 0).toLocaleString(); },
        get collected() { return Number(this.amount) || 0 },
        get outstanding() { return Math.max(0, Number(this.total) - this.collected) },
        get surplus() { return Math.max(0, this.collected - Number(this.total)) },
        get walletShort() { return Math.max(0, Number(this.total) - Number(this.walletBalance)) },
        get walletCovers() { return Number(this.walletBalance) >= Number(this.total) },
    }"
    class="contents"
>
    {{-- Payment method, visible for both settled + on-hold since
         both record a Payment row. --}}
    <div x-show="status === 'confirmed' || status === 'on_hold'"
         x-cloak data-testid="reserve-payment-method-block">
        <label for="r-payment-method" class="lp-form-label">Payment method</label>
        {{-- :required tracks the visibility. Static `required` on a
             field hidden by x-show blocks form submit with
             "invalid form control is not focusable" because the
             browser can't scroll to a display:none element. --}}
        <select id="r-payment-method" wire:model="reservePaymentMethod"
                :required="status === 'confirmed' || status === 'on_hold'"
                data-testid="reserve-payment-method" class="lp-input">
            <option value="cash">Cash</option>
            <option value="transfer">Bank transfer</option>
            <option value="card">Card</option>
            <option value="pos">POS terminal</option>
            <option value="other">Other</option>
        </select>
        @error('reservePaymentMethod') <p class="lp-form-error">{{ $message }}</p> @enderror
    </div>

    {{-- Deposit amount, only on the on_hold path. Overpayment
         (> total) is allowed and its surplus lands in the guest
         wallet via RecordManualPayment. --}}
    <div x-show="status === 'on_hold'" x-cloak data-testid="reserve-payment-amount-block">
        <label for="r-payment-amount" class="lp-form-label">
            Deposit received
            <span style="color: var(--color-ink-mute); font-weight: 400;">
                <template x-if="minDeposit > 0">
                    <span>&middot; min <span x-text="money(minDeposit)"></span> per room policy</span>
                </template>
                <template x-if="!minDeposit">
                    <span>&middot; balance collected before check-in</span>
                </template>
            </span>
        </label>
        {{-- Deposit amount. Bound via Alpine x-model against the
             @entangle'd `amount` above, so no per-keystroke server
             trip. The "still owed" hint updates instantly client-side;
             Livewire reads the current value when submitReserve fires. --}}
        <input id="r-payment-amount" type="number" min="1" step="1"
               :required="status === 'on_hold'"
               x-model.number="amount"
               @keydown.enter.prevent
               data-testid="reserve-payment-amount" class="lp-input"
               :placeholder="minDeposit > 0 ? money(minDeposit) : 'e.g. 10000'">
        @error('reservePaymentAmount') <p class="lp-form-error">{{ $message }}</p> @enderror

        <p x-show="total > 0 && collected > 0 && outstanding > 0"
           x-cloak class="text-[12px] mt-1" data-testid="reserve-outstanding"
           style="color: #B85C42;">
            Outstanding to collect before check-in:
            <strong>&#8358;<span x-text="Number(outstanding).toLocaleString()"></span></strong>
        </p>
        <p x-show="total > 0 && collected > 0 && surplus > 0"
           x-cloak class="text-[12px] mt-1" data-testid="reserve-change"
           style="color: var(--color-accent-deep);">
            Booking fully paid, <strong>&#8358;<span x-text="Number(surplus).toLocaleString()"></span></strong>
            <span x-show="hasCustomer">will credit to the guest&rsquo;s wallet.</span>
            <span x-show="!hasCustomer">change will not auto-credit (no linked customer).</span>
        </p>
        <p x-show="total > 0 && collected > 0 && outstanding === 0 && surplus === 0"
           x-cloak class="text-[12px] mt-1" data-testid="reserve-exact"
           style="color: var(--color-accent-deep);">
            Booking fully paid, no balance owed.
        </p>
    </div>

    {{-- Wallet-path affordability hint, same instant client-side
         check based on the entangled balance + total. --}}
    <div x-show="status === 'wallet' && total > 0 && walletBalance > 0" x-cloak>
        <p class="text-[12px]" data-testid="reserve-wallet-hint"
           :style="'color: ' + (walletCovers ? 'var(--color-accent-deep)' : '#B85C42') + ';'">
            <template x-if="walletCovers">
                <span>Wallet covers the full estimate, booking will be Confirmed.</span>
            </template>
            <template x-if="!walletCovers">
                <span>Wallet is short by &#8358;<span x-text="Number(walletShort).toLocaleString()"></span>
                   , booking will land On-hold with the remainder owed.</span>
            </template>
        </p>
    </div>

    {{-- Corp-invoice hint: no cash collected at the desk, guest gets
         their individual invoice at checkout, the corp gets a bulk
         invoice later. --}}
    <div x-show="status === 'corp_invoice'" x-cloak
         data-testid="reserve-corp-invoice-hint"
         style="padding: 10px 12px; border-radius: 8px;
                background: rgba(122, 168, 116, 0.08);
                border: 1px solid rgba(122, 168, 116, 0.35);">
        <p class="text-[12px]" style="color: var(--color-ink); margin: 0;">
            <strong>No payment collected at the desk.</strong>
            Booking is Confirmed; the guest receives an individual invoice at checkout
            and the corp is billed in bulk on the invoice cycle.
        </p>
    </div>
</div>

@error('reserveGeneral')
    <p class="lp-form-error" data-testid="reserve-error">{{ $message }}</p>
@enderror
