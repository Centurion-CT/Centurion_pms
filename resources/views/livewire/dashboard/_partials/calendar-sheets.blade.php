{{--
    Shared action-sheet UI for any interactive Livewire calendar (the
    full property calendar, the dashboard mini-heatmap, future
    surfaces).

    Expects the host component to expose, as public properties:
      $sheetOpen, $sheetRoomId, $sheetRoomName, $sheetCheckin, $sheetCheckout,
      $guest_name, $guest_email, $guest_phone, $adults, $children,
      $special_requests, $payment_settlement, $source, $source_note,
      $manual_discount_type, $manual_discount_percent, $manual_discount_fixed_input,
      $bookingSheetOpen, $viewBookingId, $cancelRefund, $noShowForfeit

    And these vars passed in from render():
      $viewed (Booking|null), $viewedDerived (array of derived state).

    Methods the host component must provide:
      closeSheet, saveReservation,
      closeBookingSheet, checkIn, checkOut, markNoShow, cancelBooking.

    The bound interactive cells must have the attributes:
      data-state="free|booked|blocked"
      data-room-id="{id}"
      data-date="YYYY-MM-DD"
      data-day="{n}"           (optional; used for same-row drag clamp)
      data-cell="reservable"   (free cells only)
      data-booking-id="{id}"   (booked cells with a known booking)

    And the wrapping grid must carry `data-calendar-bind` (any value) so
    the JS binder can find it and skip non-interactive tables.
--}}

@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    $nightsCount = ($sheetOpen && $sheetCheckin && $sheetCheckout)
        ? (int) \Illuminate\Support\Carbon::parse($sheetCheckin)
            ->diffInDays(\Illuminate\Support\Carbon::parse($sheetCheckout), absolute: true)
        : 0;
@endphp

{{-- ─── New-reservation sheet (free cell click / drag) ────────────── --}}
@if ($sheetOpen)
    <div class="lp-sheet-backdrop" wire:click="closeSheet" data-testid="sheet-backdrop"></div>

    <aside class="lp-sheet" data-testid="reservation-sheet" role="dialog" aria-modal="true">

        <div class="lp-sheet-head">
            <div>
                <span class="lp-eyebrow-strip">New reservation</span>
                <p class="title mt-2">Book {{ $sheetRoomName }}</p>
                <p class="sub">Walk-in or phone booking, won&rsquo;t go through Paystack.</p>
            </div>
            <button type="button" wire:click="closeSheet"
                    data-testid="sheet-close"
                    class="lp-sheet-close" aria-label="Close">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <path d="M3 3l8 8M11 3l-8 8"/>
                </svg>
            </button>
        </div>

        <div class="lp-sheet-summary">
            <span class="room">{{ $sheetRoomName }}</span>
            <span class="dates">
                <strong>{{ \Illuminate\Support\Carbon::parse($sheetCheckin)->toFormattedDateString() }}</strong>
                <span style="color: var(--color-ink-mute);">→</span>
                <strong>{{ \Illuminate\Support\Carbon::parse($sheetCheckout)->toFormattedDateString() }}</strong>
                @if ($nightsCount > 0)
                    <span class="nights-pill">
                        {{ $nightsCount }} night{{ $nightsCount === 1 ? '' : 's' }}
                    </span>
                @endif
            </span>
            @error('sheetCheckin') <p class="lp-form-error mt-1">{{ $message }}</p> @enderror
        </div>

        <form wire:submit="saveReservation" class="lp-sheet-body" data-testid="sheet-form">

            {{-- Breakfast tier, only shown when the sheet's picked
                 room's category has breakfast_included_rate set. --}}
            @if (isset($sheetRoom) && $sheetRoom && $sheetRoom->category?->hasBreakfastOption())
                <div style="margin-bottom: 4px;">
                    @include('livewire.dashboard._partials.breakfast-rate-radio', [
                        'category' => $sheetRoom->category,
                        'modelPath' => 'breakfast_included',
                        'idPrefix' => 'sheet',
                    ])
                </div>
            @endif

            {{-- Customer typeahead, searches existing customers by name,
                 email or phone and autofills the guest fields. Skip it
                 to enter a fresh guest. --}}
            <div data-testid="sheet-customer-lookup">
                @if ($this->pickedCustomer)
                    <label class="lp-form-label">Existing customer</label>
                    <div class="lp-picked-customer" data-testid="sheet-picked-customer">
                        <span class="lp-avatar @if ($this->pickedCustomer->is_vip) lp-avatar--vip @endif" style="width: 32px; height: 32px; font-size: 11px;">
                            {{ $this->pickedCustomer->avatarInitials() }}
                        </span>
                        <div class="lp-picked-customer-body">
                            <span class="name">
                                @if ($this->pickedCustomer->is_vip)
                                    <span style="color: var(--color-accent-deep); margin-right: 4px;">★</span>
                                @endif
                                {{ $this->pickedCustomer->name }}
                            </span>
                            <span class="meta">
                                {{ $this->pickedCustomer->contactLine() }}
                                @if ($this->pickedCustomer->total_bookings > 0)
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    {{ $this->pickedCustomer->total_bookings }} stay{{ $this->pickedCustomer->total_bookings === 1 ? '' : 's' }}
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    ₦{{ number_format((int) $this->pickedCustomer->lifetime_value) }} lifetime
                                @endif
                            </span>
                        </div>
                        <button type="button" wire:click="clearPickedCustomer"
                                data-testid="sheet-customer-clear"
                                class="lp-sheet-close" style="width: 26px; height: 26px;" aria-label="Clear picked customer">
                            <svg width="11" height="11" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                                <path d="M3 3l8 8M11 3l-8 8"/>
                            </svg>
                        </button>
                    </div>
                @else
                    <label for="sheet-customer-search" class="lp-form-label">
                        Look up customer <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                    </label>
                    <input id="sheet-customer-search" type="search"
                           wire:model.live.debounce.250ms="customerSearch"
                           data-testid="sheet-customer-search"
                           class="lp-input" autocomplete="off"
                           placeholder="Name, email, or phone…">
                    @if ($customerSearch !== '')
                        @if ($this->customerMatches->isEmpty())
                            <p class="lp-form-help" data-testid="sheet-customer-no-results">
                                No customer matches. Continue below to add a new guest.
                            </p>
                        @else
                            <ul class="lp-typeahead" data-testid="sheet-customer-matches">
                                @foreach ($this->customerMatches as $c)
                                    <li>
                                        <button type="button"
                                                wire:click="pickCustomer({{ $c->id }})"
                                                data-testid="sheet-customer-pick-{{ $c->id }}"
                                                class="lp-typeahead-row">
                                            <span class="lp-avatar @if ($c->is_vip) lp-avatar--vip @endif" style="width: 30px; height: 30px; font-size: 11px;">
                                                {{ $c->avatarInitials() }}
                                            </span>
                                            <span class="lp-typeahead-body">
                                                <span class="name">
                                                    @if ($c->is_vip)
                                                        <span style="color: var(--color-accent-deep); margin-right: 3px;">★</span>
                                                    @endif
                                                    {{ $c->name }}
                                                </span>
                                                <span class="meta">{{ $c->contactLine() ?: '-' }}</span>
                                                @if ($c->total_bookings > 0)
                                                    <span class="stat">
                                                        {{ $c->total_bookings }} stay{{ $c->total_bookings === 1 ? '' : 's' }}
                                                        <span style="color: var(--color-ink-mute);">·</span>
                                                        ₦{{ number_format((int) $c->lifetime_value) }}
                                                    </span>
                                                @endif
                                            </span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                @endif
            </div>

            <div>
                <label for="sheet-name" class="lp-form-label">Guest name</label>
                <input id="sheet-name" type="text" wire:model="guest_name"
                       data-testid="sheet-name" class="lp-input" required maxlength="120"
                       placeholder="Amara Okafor">
                @error('guest_name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="sheet-email" class="lp-form-label">Email</label>
                    <input id="sheet-email" type="email" wire:model="guest_email"
                           data-testid="sheet-email" class="lp-input" required>
                    @error('guest_email') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="sheet-phone" class="lp-form-label">Phone</label>
                    <input id="sheet-phone" type="tel" wire:model="guest_phone"
                           data-testid="sheet-phone" class="lp-input" required
                           placeholder="+234 800 000 0000">
                    @error('guest_phone') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="sheet-adults" class="lp-form-label">Adults</label>
                    <input id="sheet-adults" type="number" wire:model="adults" min="1"
                           data-testid="sheet-adults" class="lp-input" required>
                    @error('adults') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="sheet-children" class="lp-form-label">Children</label>
                    <input id="sheet-children" type="number" wire:model="children" min="0"
                           data-testid="sheet-children" class="lp-input">
                    @error('children') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="sheet-special" class="lp-form-label">
                    Special requests <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                </label>
                <textarea id="sheet-special" wire:model="special_requests" rows="2" maxlength="1000"
                          data-testid="sheet-special" class="lp-input"></textarea>
            </div>

            {{-- Discount / coupon, auto-apply notice when a codeless
                 rule matches the chosen dates, or input for typing a
                 code. Engine re-validates on save. --}}
            <div data-testid="sheet-discount-block">
                @if ($appliedDiscountId)
                    <label class="lp-form-label">
                        {{ $discountAutoApplied ? 'Auto-applied discount' : 'Coupon applied' }}
                    </label>
                    <div class="lp-discount-applied" data-testid="sheet-discount-applied">
                        <div class="body">
                            <span class="name">{{ $appliedDiscountName }}</span>
                            @if ($appliedDiscountCodeUsed)
                                <span class="code">{{ $appliedDiscountCodeUsed }}</span>
                            @endif
                            <span class="off">
                                &minus;₦{{ number_format((int) $appliedDiscountAmountOff) }}
                                <span class="muted">· {{ $appliedDiscountSummary }}</span>
                            </span>
                        </div>
                        <button type="button" wire:click="clearDiscount"
                                data-testid="sheet-discount-clear"
                                aria-label="Remove discount">×</button>
                    </div>
                @else
                    <label for="sheet-discount-code" class="lp-form-label">
                        Coupon code <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                    </label>
                    <div class="lp-discount-row">
                        <input id="sheet-discount-code" type="text" wire:model="discountCode"
                               wire:keydown.enter.prevent="applyDiscount"
                               data-testid="sheet-discount-code"
                               class="lp-input"
                               style="text-transform: uppercase; letter-spacing: 0.06em;"
                               placeholder="SUMMER25">
                        <button type="button" wire:click="applyDiscount"
                                data-testid="sheet-discount-apply"
                                class="lp-btn">
                            <span wire:loading.remove wire:target="applyDiscount">Apply</span>
                            <span wire:loading wire:target="applyDiscount">…</span>
                        </button>
                    </div>
                    @if ($discountError)
                        <p class="lp-form-error" data-testid="sheet-discount-error">{{ $discountError }}</p>
                    @endif
                @endif
            </div>

            {{-- Manual front-desk discount. Alpine-driven, no per-
                 keystroke wire trip; @entangle keeps Livewire in sync
                 so the save action reads the current values. Calendar
                 sheet doesn't itemise pricing on-screen, so no live
                 "new total" line here — the operator confirms the
                 total on the resulting reservation page. --}}
            <div style="border: 1px solid var(--color-line-soft); border-radius: 12px; padding: 12px 14px;"
                 x-data="{
                    mode: @entangle('manual_discount_type'),
                    pct: @entangle('manual_discount_percent'),
                    fixed: @entangle('manual_discount_fixed_input'),
                 }">
                <div class="flex items-center justify-between">
                    <label for="sheet-manual-discount" class="lp-form-label" style="margin: 0;">
                        Manual discount
                        <span style="color: var(--color-ink-mute); font-weight: 400; letter-spacing: 0; text-transform: none;">
                            · optional
                        </span>
                    </label>
                    <div class="lp-mode-toggle inline-flex" role="group" aria-label="Discount type"
                         style="background: var(--color-card-deep); border-radius: 999px; padding: 2px; gap: 2px;">
                        <button type="button"
                                @click="mode = 'percent'; fixed = 0"
                                data-testid="sheet-manual-discount-type-percent"
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
                                data-testid="sheet-manual-discount-type-fixed"
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
                <div x-show="mode === 'percent'" class="flex items-center gap-3 mt-2">
                    <input id="sheet-manual-discount" type="range" min="0" max="100" step="0.5"
                           x-model.number="pct"
                           @keydown.enter.prevent
                           data-testid="sheet-manual-discount-slider"
                           style="flex: 1; accent-color: var(--color-accent-deep);"
                           aria-label="Manual discount percent">
                    <div style="position: relative; width: 84px;">
                        <input type="number" min="0" max="100" step="0.01"
                               x-model.number="pct"
                               @keydown.enter.prevent
                               data-testid="sheet-manual-discount-input"
                               class="lp-input"
                               style="width: 100%; text-align: right; padding-right: 22px;"
                               aria-label="Manual discount percent (direct entry)">
                        <span style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
                                     font-size: 11px; color: var(--color-ink-mute); pointer-events: none;">%</span>
                    </div>
                </div>
                <div x-show="mode === 'fixed'" x-cloak class="mt-2" style="position: relative;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                                 font-size: 13px; color: var(--color-ink-mute); pointer-events: none;">₦</span>
                    <input type="number" min="0" step="1"
                           x-model.number="fixed"
                           @keydown.enter.prevent
                           data-testid="sheet-manual-discount-fixed-input"
                           class="lp-input"
                           style="width: 100%; padding-left: 24px;"
                           placeholder="e.g. 5000"
                           aria-label="Manual discount, flat ₦ amount">
                </div>
                @error('manual_discount_percent')
                    <p class="lp-form-error">{{ $message }}</p>
                @enderror
                @error('manual_discount_fixed_input')
                    <p class="lp-form-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Channel picker, where the booking came from (walk-in,
                 Booking.com, Airbnb, WhatsApp DM, …). "Other" pairs with
                 a free-text note so the operator can name a niche channel
                 without waiting for us to add an enum case. --}}
            <fieldset class="lp-fieldset" data-testid="sheet-source-block">
                <legend>Booking channel</legend>
                <div class="mt-2">
                    <select wire:model.live="source"
                            data-testid="sheet-source"
                            class="lp-input" style="padding: 10px 12px;">
                        @foreach (\App\Enums\BookingSource::pickerCases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    @error('source') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                @if ($source === 'other' || $source === 'booking_com' || $source === 'airbnb' || $source === 'hotels_ng' || $source === 'jumia_travel' || $source === 'direct')
                    <div class="mt-3">
                        <label for="sheet-source-note" class="lp-form-label">
                            @if ($source === 'other') Channel name @else Note <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span> @endif
                        </label>
                        <input id="sheet-source-note" type="text" wire:model="source_note"
                               data-testid="sheet-source-note" class="lp-input" maxlength="120"
                               placeholder="{{ $source === 'other' ? 'e.g. Facebook Marketplace' : 'e.g. second account, corporate contract' }}">
                        @error('source_note') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
            </fieldset>

            <fieldset class="lp-fieldset">
                <legend>Payment settlement</legend>
                <div class="grid gap-1 mt-2">
                    <label class="lp-radio-row">
                        <input type="radio" wire:model="payment_settlement" value="succeeded"
                               data-testid="sheet-paid">
                        <span>Paid in full (cash / off-platform), mark <strong>Confirmed</strong></span>
                    </label>
                    @if ($this->pickedCustomerWalletBalance > 0)
                        <label class="lp-radio-row" data-testid="sheet-wallet">
                            <input type="radio" wire:model="payment_settlement" value="wallet">
                            <span>
                                Pay from wallet
                                <span style="color: var(--color-ink-mute); font-weight: 400;">
                                    &middot; balance:
                                    <strong>&#8358;{{ number_format($this->pickedCustomerWalletBalance) }}</strong>.
                                    Confirmed if it covers the total; On-hold otherwise.
                                </span>
                            </span>
                        </label>
                    @endif
                    <label class="lp-radio-row">
                        <input type="radio" wire:model="payment_settlement" value="pending"
                               data-testid="sheet-pending">
                        <span>Not paid yet, mark <strong>Pending</strong></span>
                    </label>
                </div>
                @error('payment_settlement')
                    <p class="lp-form-error mt-2" data-testid="sheet-payment-error">{{ $message }}</p>
                @enderror
            </fieldset>
        </form>

        <div class="lp-sheet-foot">
            <button type="button" wire:click="closeSheet"
                    data-testid="sheet-cancel" class="lp-btn">Cancel</button>
            <button type="button" wire:click="saveReservation"
                    data-testid="sheet-save" class="lp-pill">
                <span wire:loading.remove wire:target="saveReservation">Create reservation</span>
                <span wire:loading wire:target="saveReservation">Creating…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </aside>
@endif

{{-- ─── Existing-booking sheet (booked cell click) ──────────────────
     Alpine owns the visibility so open/close animate instantly on
     click without waiting for a Livewire round-trip. Livewire still
     runs `openBookingSheet` in parallel to hydrate the panel content
     (guest data, KPIs, action buttons); until it arrives, the panel
     shows a skeleton with the guest surname captured from the chip.

     `pendingSurname` is what the JS click handler stashes off the
     chip cell so the header can already say "Loading Adaeze's
     reservation…" before the server responds. --}}
<div x-data="{
        open: {{ $bookingSheetOpen ? 'true' : 'false' }},
        pendingSurname: {{ $viewed ? json_encode($viewed->guest_name) : "''" }},
        close() {
            // Hide the panel via Alpine straight away for the exit
            // transition, then let Livewire tear the content down after
            // the animation completes. Firing closeBookingSheet in
            // parallel would null $viewed mid-animation and swap the
            // content to the loading skeleton right as it slides out.
            this.open = false;
            this.pendingSurname = '';
            setTimeout(() => this.$wire.closeBookingSheet(), 220);
        }
     }"
     x-on:booking-sheet-request-open.window="open = true; pendingSurname = $event.detail.surname || pendingSurname"
     x-on:keydown.escape.window="if (open) close()">

    <div x-show="open"
         x-cloak
         x-transition:enter="lp-sheet-fade-enter"
         x-transition:enter-start="lp-sheet-fade-enter-start"
         x-transition:enter-end="lp-sheet-fade-enter-end"
         x-transition:leave="lp-sheet-fade-leave"
         x-transition:leave-start="lp-sheet-fade-leave-start"
         x-transition:leave-end="lp-sheet-fade-leave-end"
         class="lp-sheet-backdrop"
         data-testid="booking-sheet-backdrop"
         @click="close()"></div>

    <aside x-show="open"
           x-cloak
           x-transition:enter="lp-sheet-slide-enter"
           x-transition:enter-start="lp-sheet-slide-enter-start"
           x-transition:enter-end="lp-sheet-slide-enter-end"
           x-transition:leave="lp-sheet-slide-leave"
           x-transition:leave-start="lp-sheet-slide-leave-start"
           x-transition:leave-end="lp-sheet-slide-leave-end"
           class="lp-sheet" data-testid="booking-sheet" role="dialog" aria-modal="true">

    @if (! $viewed)
        {{-- Skeleton shown while the Livewire round-trip loads the
             real reservation. Header carries the guest name we know
             from the chip cell so it already reads as the right
             reservation; body is a light shimmer strip. --}}
        <div class="lp-sheet-head">
            <div>
                <span class="lp-eyebrow-strip">Reservation</span>
                <p class="title mt-2" data-testid="booking-sheet-loading-title">
                    Loading
                    <span x-text="pendingSurname ? pendingSurname + '’s reservation…' : 'reservation…'"></span>
                </p>
            </div>
            <button type="button" @click="close()"
                    data-testid="booking-sheet-close"
                    class="lp-sheet-close" aria-label="Close">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <path d="M3 3l8 8M11 3l-8 8"/>
                </svg>
            </button>
        </div>
        <div class="lp-sheet-body" style="padding-top: 18px;">
            <div aria-hidden="true"
                 style="display: grid; gap: 12px;"
                 data-testid="booking-sheet-loading-body">
                <div style="height: 12px; border-radius: 6px; background: var(--color-card-deep); animation: lp-shim 1200ms ease-in-out infinite; width: 60%;"></div>
                <div style="height: 12px; border-radius: 6px; background: var(--color-card-deep); animation: lp-shim 1200ms ease-in-out infinite; width: 40%;"></div>
                <div style="height: 68px; border-radius: 10px; background: var(--color-card-deep); animation: lp-shim 1200ms ease-in-out infinite;"></div>
                <div style="height: 68px; border-radius: 10px; background: var(--color-card-deep); animation: lp-shim 1200ms ease-in-out infinite;"></div>
                <div style="height: 12px; border-radius: 6px; background: var(--color-card-deep); animation: lp-shim 1200ms ease-in-out infinite; width: 70%;"></div>
            </div>
        </div>
    @else

        <div class="lp-sheet-head">
            <div>
                <span class="lp-eyebrow-strip">Reservation</span>
                <p class="title mt-2 flex flex-wrap items-baseline gap-2">
                    @if ($viewed->customer?->is_vip)
                        <span style="color: var(--color-accent-deep);">★</span>
                    @endif
                    <span data-testid="booking-sheet-guest">{{ $viewed->guest_name }}</span>
                    <span class="lp-mono" style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase; font-size: 12px; font-weight: 500;">
                        {{ $viewed->booking_ref }}
                    </span>
                </p>
                <p class="sub">
                    {{ $viewed->room?->name }}
                    <span style="color: var(--color-ink-mute);">·</span>
                    {{ $viewed->checkin_date->toFormattedDateString() }}
                    →
                    {{ $viewed->checkout_date->toFormattedDateString() }}
                    <span style="color: var(--color-ink-mute);">·</span>
                    {{ $viewed->nights }}n
                </p>
            </div>
            <button type="button" @click="close()"
                    data-testid="booking-sheet-close"
                    class="lp-sheet-close" aria-label="Close">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <path d="M3 3l8 8M11 3l-8 8"/>
                </svg>
            </button>
        </div>

        <div class="lp-sheet-body" style="padding-top: 18px;">

            <div style="display: grid; gap: 12px;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                    <span class="lp-form-label" style="margin: 0;">Status</span>
                    <span class="lp-tag {{ $viewedDerived['statusClass'] }}" data-testid="booking-sheet-status-tag">
                        {{ $viewedDerived['statusLabel'] }}
                    </span>
                </div>

                {{-- Overstay notice, visible whenever the guest is past
                     their booked checkout. The amount shown is what the
                     next "Check out" press will auto-add to the ledger
                     (idempotent, re-press won't double-charge). --}}
                @if (($viewedDerived['overstayNights'] ?? 0) > 0 && ! ($viewedDerived['overstayAlreadyCharged'] ?? false))
                    <div data-testid="booking-sheet-overstay-notice"
                         style="padding: 12px 14px; background: rgba(168, 101, 10, 0.10); border: 1px solid rgba(168, 101, 10, 0.30); border-radius: 12px; display: grid; gap: 4px;">
                        <span class="lp-form-label" style="margin: 0; color: #A8650A;">Overstay pending</span>
                        <span style="font-family: var(--font-display); font-weight: 600; font-size: 18px; color: #A8650A; letter-spacing: -0.015em;">
                            {{ $viewedDerived['overstayNights'] }} night{{ $viewedDerived['overstayNights'] === 1 ? '' : 's' }}
                            &middot; ₦{{ number_format($viewedDerived['overstayAmount']) }}
                        </span>
                        <span class="text-[12px]" style="color: var(--color-ink-soft);">
                            Will be added to the bill automatically when you press Check out.
                            Settle the new total before the room can release.
                        </span>
                    </div>
                @elseif (($viewedDerived['overstayAlreadyCharged'] ?? false))
                    <div data-testid="booking-sheet-overstay-charged"
                         style="padding: 10px 14px; background: var(--color-card-deep); border: 1px solid var(--color-line); border-radius: 12px; font-size: 12.5px; color: var(--color-ink-soft);">
                        Overstay already charged. See the ledger in the full console.
                    </div>
                @endif

                <div class="grid gap-2 grid-cols-2" data-testid="booking-sheet-kpis">
                    <div class="lp-kpi" style="padding: 12px 14px;">
                        <span class="label">Stay total</span>
                        <span class="value" style="font-size: 20px;">
                            ₦{{ number_format((int) $viewed->total_amount) }}
                        </span>
                    </div>
                    <div class="lp-kpi" style="padding: 12px 14px; @if ($viewedDerived['outstandingTotal'] > 0) background: rgba(168, 101, 10, 0.12); @endif">
                        <span class="label">Outstanding</span>
                        <span class="value" style="font-size: 20px; @if ($viewedDerived['outstandingTotal'] > 0) color: #A8650A; @endif">
                            ₦{{ number_format($viewedDerived['outstandingTotal']) }}
                        </span>
                        <span class="sub">
                            @if ($viewedDerived['unsettledTotal'] > 0)
                                incl. ₦{{ number_format($viewedDerived['unsettledTotal']) }} in charges
                            @elseif ($viewedDerived['outstandingTotal'] > 0)
                                Balance due
                            @else
                                Cleared
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            <dl class="lp-dl">
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Contact</dt>
                    <dd class="lp-dl-value">
                        @if ($viewed->guest_email)
                            <a href="mailto:{{ $viewed->guest_email }}"
                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">{{ $viewed->guest_email }}</a><br>
                        @endif
                        @if ($viewed->guest_phone)
                            <a href="tel:{{ $viewed->guest_phone }}" class="lp-mono"
                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">{{ $viewed->guest_phone }}</a>
                        @endif
                    </dd>
                </div>
                <div class="lp-dl-row">
                    <dt class="lp-dl-label">Guests</dt>
                    <dd class="lp-dl-value">
                        {{ $viewed->adults }} adult{{ $viewed->adults === 1 ? '' : 's' }}
                        @if ($viewed->children > 0)
                            <span class="muted">·</span> {{ $viewed->children }} child{{ $viewed->children === 1 ? '' : 'ren' }}
                        @endif
                    </dd>
                </div>
                @if ($viewed->customer)
                    <div class="lp-dl-row">
                        <dt class="lp-dl-label">CRM</dt>
                        <dd class="lp-dl-value">
                            <a href="{{ route('tenant.customers.show', $viewed->customer) }}" wire:navigate
                               style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                                {{ $viewed->customer->name }}
                            </a>
                            <span class="muted">·</span>
                            <span class="lp-tag {{ $viewed->customer->lifecycle->value === 'active' ? 'lp-tag--confirmed' : 'lp-tag--pending' }}"
                                  style="margin-left: 4px;">{{ $viewed->customer->lifecycle->label() }}</span>
                        </dd>
                    </div>
                @endif
                <div class="lp-dl-row" data-testid="booking-sheet-source">
                    <dt class="lp-dl-label">Channel</dt>
                    <dd class="lp-dl-value">
                        {{ $viewed->source->label() }}
                        @if ($viewed->source_note)
                            <span class="muted">·</span>
                            <span style="color: var(--color-ink-soft);">{{ $viewed->source_note }}</span>
                        @endif
                    </dd>
                </div>
                @if ($viewed->special_requests)
                    <div class="lp-dl-row">
                        <dt class="lp-dl-label">Requests</dt>
                        <dd class="lp-dl-value" style="font-style: italic; white-space: pre-wrap;">{{ $viewed->special_requests }}</dd>
                    </div>
                @endif
                @if ($viewed->no_show_at)
                    <div class="lp-dl-row">
                        <dt class="lp-dl-label">No-show at</dt>
                        <dd class="lp-dl-value" style="color: #B85C42;">
                            {{ $viewed->no_show_at->toDayDateTimeString() }}
                        </dd>
                    </div>
                @endif
            </dl>

            {{-- Assigned room + reassign affordance. Same flow as the
                 reservations console, swap to a different room in the
                 same category before/during check-in. --}}
            @if (($viewedDerived['canReassign'] ?? false))
                <div data-testid="booking-sheet-assigned-room"
                     style="display: grid; gap: 6px; padding: 12px 14px; background: var(--color-card-deep); border-radius: 12px;">
                    <span class="lp-form-label" style="margin: 0;">Assigned room</span>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="" style="font-family: var(--font-body); letter-spacing: -0.01em; font-size: 17px; font-weight: 600; color: var(--color-ink); letter-spacing: -0.015em;">
                            @if ($viewed->room?->category)
                                {{ $viewed->room->category->name }}
                                <span class="lp-mono" style="color: var(--color-ink-mute); font-weight: 500; font-size: 13px; margin-left: 4px;">
                                    · {{ $viewed->room?->room_number ?? '-' }}
                                </span>
                            @else
                                {{ $viewed->room?->name ?? '-' }}
                            @endif
                        </span>
                        @if (($reassignCandidates ?? collect())->isNotEmpty())
                            <button type="button" wire:click="toggleReassign"
                                    data-testid="booking-sheet-toggle-reassign"
                                    style="background: transparent; border: 0; font-family: inherit; font-size: 12px; color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px; cursor: pointer;">
                                {{ $showReassign ?? false ? 'Cancel' : 'Change' }}
                            </button>
                        @endif
                    </div>
                    @if (($reassignCandidates ?? collect())->isEmpty() && ! ($showReassign ?? false))
                        <span class="text-[11px]" style="color: var(--color-ink-mute);">
                            No other rooms available in this category for these dates.
                        </span>
                    @endif

                    @if (($showReassign ?? false) && ($reassignCandidates ?? collect())->isNotEmpty())
                        <div style="display: grid; gap: 8px; margin-top: 4px;" data-testid="booking-sheet-reassign-panel">
                            <select wire:model="reassignTargetRoomId"
                                    data-testid="booking-sheet-reassign-target"
                                    class="lp-input" style="font-size: 13px; padding: 8px 10px;">
                                <option value="">- pick a room -</option>
                                @foreach ($reassignCandidates->groupBy(fn ($r) => $r->category?->name ?? 'Uncategorised') as $categoryName => $rooms)
                                    <optgroup label="{{ $categoryName }} · ₦{{ number_format((int) $rooms->first()->base_rate) }}/night">
                                        @foreach ($rooms as $candidate)
                                            <option value="{{ $candidate->id }}">
                                                Room {{ $candidate->room_number ?? $candidate->id }}
                                                @if ($candidate->internal_notes)
                                                    · {{ \Illuminate\Support\Str::limit($candidate->internal_notes, 36) }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <button type="button" wire:click="reassignRoom"
                                    wire:confirm="Move this reservation to the selected room?"
                                    data-testid="booking-sheet-reassign-confirm"
                                    class="lp-btn lp-btn--block"
                                    style="font-size: 12.5px;">
                                <span wire:loading.remove wire:target="reassignRoom">Confirm reassignment</span>
                                <span wire:loading wire:target="reassignRoom">Reassigning…</span>
                            </button>
                        </div>
                    @endif
                </div>
            @endif

            <div style="display: grid; gap: 10px;">
                <span class="lp-form-label" style="margin: 0;">Front-desk actions</span>

                @if ($viewedDerived['canCheckIn'])
                    <button type="button" wire:click="checkIn"
                            wire:confirm="Check in {{ $viewed->guest_name }} now?"
                            data-testid="booking-sheet-checkin"
                            class="lp-btn lp-btn--primary lp-btn--block">Check in</button>
                @endif

                @if ($viewedDerived['canCheckOut'])
                    <button type="button" wire:click="checkOut"
                            wire:confirm="Check out {{ $viewed->guest_name }}? Outstanding must be ₦0."
                            @disabled($viewedDerived['outstandingTotal'] > 0)
                            data-testid="booking-sheet-checkout"
                            class="lp-btn lp-btn--primary lp-btn--block">Check out</button>
                    @if ($viewedDerived['outstandingTotal'] > 0)
                        <p class="text-[11.5px]" style="color: #A8650A; margin-top: -4px;">
                            Clear ₦{{ number_format($viewedDerived['outstandingTotal']) }} first.
                        </p>
                    @endif
                @endif

                {{-- Change room - promoted from the small link on the
                     assigned-room card up to a proper button in the
                     action list. Toggles the picker + scrolls it into
                     view. Only rendered when there IS a room to move
                     to (empty pool renders a disabled hint instead). --}}
                @if (($viewedDerived['canReassign'] ?? false) && $viewed->room_id !== null)
                    @if (($reassignCandidates ?? collect())->isNotEmpty())
                        <button type="button"
                                wire:click="toggleReassign"
                                data-testid="booking-sheet-action-change-room"
                                x-on:click="$nextTick(() => document.querySelector('[data-testid=&quot;booking-sheet-reassign-panel&quot;]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
                                class="lp-btn lp-btn--block">
                            {{ ($showReassign ?? false) ? 'Cancel change' : 'Change room' }}
                        </button>
                    @else
                        <button type="button" disabled
                                data-testid="booking-sheet-action-change-room-disabled"
                                class="lp-btn lp-btn--block"
                                style="opacity: 0.5; cursor: not-allowed;">
                            Change room
                        </button>
                    @endif
                @endif

                {{-- Extend stay - opens the shared modal. Same modal
                     the reservation-show + dashboard home use, so
                     rate preview + discount + inline payment all
                     work identically here. --}}
                @if (($viewedDerived['canExtend'] ?? false))
                    <button type="button"
                            wire:click="openExtendModal({{ $viewed->id }})"
                            data-testid="booking-sheet-extend"
                            class="lp-btn lp-btn--block">Extend stay</button>
                @endif

                @if ($viewedDerived['canNoShow'])
                    <label class="lp-check-row">
                        <input type="checkbox" wire:model="noShowForfeit" data-testid="booking-sheet-forfeit">
                        <span>Forfeit deposit on no-show</span>
                    </label>
                    <button type="button" wire:click="markNoShow"
                            wire:confirm="Mark {{ $viewed->guest_name }} as no-show?"
                            data-testid="booking-sheet-noshow"
                            class="lp-btn lp-btn--block">Mark no-show</button>
                @endif

                @if ($viewedDerived['canCancel'])
                    <div style="margin-top: 6px; padding-top: 14px; border-top: 1px solid var(--color-line-soft); display: grid; gap: 10px;">
                        <label class="lp-check-row" style="font-size: 12.5px;">
                            <input type="checkbox" wire:model="cancelRefund" data-testid="booking-sheet-refund">
                            <span>Flag a refund request on Paystack
                                <span style="color: var(--color-ink-mute);">(doesn&rsquo;t auto-execute, noted for reconciliation)</span>
                            </span>
                        </label>
                        <button type="button" wire:click="cancelBooking"
                                wire:confirm="Cancel this reservation?"
                                data-testid="booking-sheet-cancel-booking"
                                class="lp-btn lp-btn--danger lp-btn--block">Cancel reservation</button>
                    </div>
                @endif
            </div>
        </div>

        <div class="lp-sheet-foot">
            <a href="{{ route('tenant.reservations.show', $viewed->booking_ref) }}" wire:navigate
               class="lp-btn"
               data-testid="booking-sheet-open-console">
                <svg width="13" height="13" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 3h2v2M11 3L6 8M3 6v5h5"/>
                </svg>
                Full front-desk console
            </a>
            <button type="button" @click="close()"
                    data-testid="booking-sheet-done" class="lp-pill">
                <span>Done</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    @endif
    </aside>
</div>

{{-- ─── Calendar interaction JS (click + drag + booked-click) ───────
     Binds to every element on the page carrying `data-calendar-bind`,
     so both the property heat-map and the dashboard mini-grid use the
     same code path. Cells inside that element must carry the standard
     data-* attributes documented at the top of this partial. --}}
<script>
(function () {
    function bindAll() {
        document.querySelectorAll('[data-calendar-bind]').forEach(bind);
    }

    function bind(grid) {
        if (grid.dataset.lpCalBound === '1') return;
        grid.dataset.lpCalBound = '1';

        let selecting = false;
        let anchor = null;
        let currentRoom = null;
        const highlighted = new Set();

        const cellFromTarget = (target) => {
            const cell = target.closest('td[data-cell="reservable"]');
            if (!cell || cell.dataset.state === 'booked' || cell.dataset.state === 'blocked') return null;
            return {
                el: cell,
                roomId: parseInt(cell.dataset.roomId, 10),
                day: parseInt(cell.dataset.day, 10),
                date: cell.dataset.date,
            };
        };

        const clearHighlight = () => {
            highlighted.forEach(el => el.classList.remove('is-selecting'));
            highlighted.clear();
        };

        const highlightRange = (roomId, fromDay, toDay) => {
            clearHighlight();
            const [lo, hi] = fromDay <= toDay ? [fromDay, toDay] : [toDay, fromDay];
            for (let d = lo; d <= hi; d++) {
                const cell = grid.querySelector(`td[data-room-id="${roomId}"][data-day="${d}"]`);
                if (!cell) break;
                if (cell.dataset.state === 'booked' || cell.dataset.state === 'blocked') break;
                cell.classList.add('is-selecting');
                highlighted.add(cell);
            }
        };

        const isoNext = (iso) => {
            const d = new Date(iso + 'T00:00:00');
            d.setDate(d.getDate() + 1);
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd}`;
        };

        grid.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return;
            const c = cellFromTarget(e.target);
            if (!c) return;
            e.preventDefault();
            selecting = true;
            anchor = c;
            currentRoom = c.roomId;
            highlightRange(currentRoom, c.day, c.day);
        });

        grid.addEventListener('mouseover', (e) => {
            if (!selecting) return;
            const c = cellFromTarget(e.target);
            if (!c || c.roomId !== currentRoom) return;
            highlightRange(currentRoom, anchor.day, c.day);
        });

        // Booked-family cell → open existing-booking sheet. Includes
        // CheckedIn / due_out / overdue cells since they too are tied
        // to a booking the operator may want to open. Two-step so the
        // panel slides in instantly (Alpine sees the browser event and
        // flips its `open` flag) while the Livewire round-trip loads
        // the real content in parallel behind a skeleton.
        grid.addEventListener('click', (e) => {
            const cell = e.target.closest(
                'td[data-booking-id][data-state="booked"], ' +
                'td[data-booking-id][data-state="checkedin"], ' +
                'td[data-booking-id][data-state="due_out"], ' +
                'td[data-booking-id][data-state="overdue"], ' +
                'td[data-booking-id][data-state="pending"]',
            );
            if (!cell) return;
            const bookingId = parseInt(cell.dataset.bookingId, 10);
            if (!bookingId) return;
            const guestSurname = cell.dataset.reassignGuest || '';
            window.dispatchEvent(new CustomEvent('booking-sheet-request-open', {
                detail: { id: bookingId, surname: guestSurname },
            }));
            window.Livewire.dispatch('open-booking-sheet', { bookingId });
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && selecting) {
                selecting = false;
                anchor = null;
                currentRoom = null;
                clearHighlight();
            }
        });

        document.addEventListener('mouseup', (e) => {
            if (!selecting || !anchor) return;
            const c = cellFromTarget(e.target);
            const lastCell = (c && c.roomId === currentRoom) ? c : anchor;

            const lo = anchor.day <= lastCell.day ? anchor : lastCell;
            const hi = anchor.day <= lastCell.day ? lastCell : anchor;

            // Snap to actually-highlighted span (booked nights break it).
            let finalLo = lo, finalHi = lo;
            for (let d = lo.day; d <= hi.day; d++) {
                const cell = grid.querySelector(`td[data-room-id="${currentRoom}"][data-day="${d}"]`);
                if (!cell || cell.dataset.state === 'booked' || cell.dataset.state === 'blocked') break;
                finalHi = {
                    roomId: parseInt(cell.dataset.roomId, 10),
                    day: parseInt(cell.dataset.day, 10),
                    date: cell.dataset.date,
                };
            }

            const roomId = currentRoom;
            const checkin = finalLo.date;
            const checkout = isoNext(finalHi.date);

            clearHighlight();
            selecting = false;
            anchor = null;
            currentRoom = null;

            if (!checkin || !checkout) return;
            window.Livewire.dispatch('open-reservation-sheet', { roomId, checkin, checkout });
        });
    }

    document.addEventListener('livewire:init', bindAll);
    document.addEventListener('livewire:navigated', bindAll);
    if (window.Livewire) {
        window.Livewire.hook('morph.updated', bindAll);
    }
    bindAll();
})();
</script>
