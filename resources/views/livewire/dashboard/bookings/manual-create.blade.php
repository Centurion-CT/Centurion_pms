@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back link + page header ──────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">Operate</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Add manual booking
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Walk-in or phone reservation. Won&rsquo;t go through Paystack.
            </p>
        </div>
    </div>

    {{-- ── Form ─────────────────────────────────────────────────── --}}
    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">

        {{-- Category-only toggle — holds a slot against a room
             category without pinning a specific room. Front desk
             assigns a real room at check-in via AssignRoomToBooking.
             Skips discount + breakfast + deposit + wallet plumbing
             (those all assume a concrete Room). --}}
        <label class="lp-form-inline-toggle" data-testid="manual-category-only-toggle"
               style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;
                      padding: 12px 14px; border: 1px solid var(--color-hairline);
                      border-radius: 10px; background: var(--color-surface-2);">
            <input type="checkbox" wire:model.live="categoryOnly"
                   data-testid="manual-category-only-checkbox"
                   style="margin-top: 3px; accent-color: var(--color-accent-deep);">
            <span style="display: grid; gap: 3px;">
                <span style="font-weight: 600; color: var(--color-ink); font-size: 14px;">
                    Reserve against category only
                </span>
                <span style="font-size: 12.5px; color: var(--color-ink-soft); line-height: 1.4;">
                    Hold a slot in a category. Front desk picks the specific room at check-in.
                </span>
            </span>
        </label>

        @if ($categoryOnly)
            {{-- Category picker replaces the room picker in this mode.
                 Small live "N units free for those dates" hint tells
                 the operator whether the hold will land before submit. --}}
            <div>
                <label for="manual-category" class="lp-form-label">Category</label>
                <select id="manual-category" wire:model.live="categoryId"
                        data-testid="manual-category-picker" class="lp-input">
                    <option value="">Pick a category</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">
                            {{ $cat->name }}
                            @if ($cat->relationLoaded('property') && $cat->property)
                                · {{ $cat->property->name }}
                            @endif
                            · ₦{{ number_format((int) $cat->base_rate) }}/night
                        </option>
                    @endforeach
                </select>
                @error('categoryId') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror

                @if ($pickedCategory && $categoryFree !== null)
                    <p data-testid="manual-category-free-hint" class="mt-2"
                       style="font-size: 12.5px; color: {{ $categoryFree > 0 ? 'var(--color-ink-soft)' : 'var(--color-danger)' }};">
                        @if ($categoryFree > 0)
                            {{ $categoryFree }} unit{{ $categoryFree === 1 ? '' : 's' }} free for those dates.
                        @else
                            Fully booked for those dates. Pick different dates or a different category.
                        @endif
                    </p>
                @endif
            </div>
        @else
            {{-- Room — searchable picker so operators with many rooms /
                 categories aren't scrolling a long <select>. Selection
                 routes through wire:click="$set('roomId', X)" so the
                 existing updatedRoomId hook (pricing preview + breakfast
                 tier reset) still fires. --}}
            <div>
                <label for="manual-room-picker-trigger" class="lp-form-label">Room</label>
                @include('livewire.dashboard._partials.searchable-room-picker', [
                    'rooms' => $rooms,
                    'modelPath' => 'roomId',
                    'idPrefix' => 'manual-room',
                    'currentId' => $roomId,
                    'placeholder' => 'Pick a room',
                ])
                @error('roomId') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Rate tier, only when the picked room's category has a
                 "with complimentary breakfast" alternative rate configured. --}}
            @if ($pickedRoom && $pickedRoom->category?->hasBreakfastOption())
                @include('livewire.dashboard._partials.breakfast-rate-radio', [
                    'category' => $pickedRoom->category,
                    'selected' => $breakfast_included,
                    'modelPath' => 'breakfast_included',
                    'idPrefix'  => 'manual',
                ])
            @endif
        @endif

        {{-- Customer typeahead, search existing customers by name,
             email or phone and autofill the guest fields. Skip it to
             enter a fresh walk-in. --}}
        <div data-testid="manual-customer-lookup">
            @if ($this->pickedCustomer)
                <label class="lp-form-label">Existing customer</label>
                <div class="lp-picked-customer" data-testid="manual-picked-customer">
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
                            data-testid="manual-customer-clear"
                            class="lp-sheet-close" style="width: 26px; height: 26px;" aria-label="Clear picked customer">
                        <svg width="11" height="11" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                            <path d="M3 3l8 8M11 3l-8 8"/>
                        </svg>
                    </button>
                </div>
            @else
                <label for="manual-customer-search" class="lp-form-label">
                    Look up customer <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                </label>
                <input id="manual-customer-search" type="search"
                       wire:model.live.debounce.250ms="customerSearch"
                       data-testid="manual-customer-search"
                       class="lp-input" autocomplete="off"
                       placeholder="Name, email, or phone…">
                @if ($customerSearch !== '')
                    @if ($this->customerMatches->isEmpty())
                        <p class="lp-form-help" data-testid="manual-customer-no-results">
                            No customer matches. Continue below to add a new guest.
                        </p>
                    @else
                        <ul class="lp-typeahead" data-testid="manual-customer-matches">
                            @foreach ($this->customerMatches as $c)
                                <li>
                                    <button type="button"
                                            wire:click="pickCustomer({{ $c->id }})"
                                            data-testid="manual-customer-pick-{{ $c->id }}"
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

        {{-- Guest info --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="manual-name" class="lp-form-label">Guest name</label>
                <input id="manual-name" type="text" wire:model="guest_name" data-testid="manual-name"
                       required class="lp-input" placeholder="Amara Okafor">
                @error('guest_name') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="manual-email" class="lp-form-label">Guest email</label>
                <input id="manual-email" type="email" wire:model="guest_email" data-testid="manual-email"
                       required class="lp-input" placeholder="guest@example.com">
                @error('guest_email') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="manual-phone" class="lp-form-label">Guest phone</label>
                <input id="manual-phone" type="tel" wire:model="guest_phone" data-testid="manual-phone"
                       required class="lp-input" placeholder="+234 800 000 0000">
                @error('guest_phone') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-5">
                <div>
                    <label for="manual-adults" class="lp-form-label">Adults</label>
                    <input id="manual-adults" type="number" wire:model="adults" min="1" required class="lp-input">
                    @error('adults') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="manual-children" class="lp-form-label">Children</label>
                    <input id="manual-children" type="number" wire:model="children" min="0" class="lp-input">
                    @error('children') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Dates --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="manual-checkin" class="lp-form-label">Check-in</label>
                <input id="manual-checkin" type="date" wire:model.live="checkin"
                       data-testid="manual-checkin" required class="lp-input">
                @error('checkin') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="manual-checkout" class="lp-form-label">Check-out</label>
                <input id="manual-checkout" type="date" wire:model.live="checkout"
                       data-testid="manual-checkout" required class="lp-input">
                @error('checkout') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Special requests --}}
        <div>
            <label for="manual-special" class="lp-form-label">Special requests</label>
            <textarea id="manual-special" wire:model="special_requests" maxlength="1000"
                      rows="3" class="lp-input"
                      placeholder="Early check-in, dietary notes, accessibility…"></textarea>
        </div>

        {{-- Discount / coupon. Auto-apply runs in render() and surfaces
             here as a green notice; otherwise the operator can type a
             code. Engine re-validates on save. Hidden in category-only
             mode - discounts assume a concrete Room and re-apply when
             the front desk assigns one at check-in. --}}
        @if (! $categoryOnly)
        <div data-testid="manual-discount-block">
            @if ($appliedDiscountId)
                <label class="lp-form-label">
                    {{ $discountAutoApplied ? 'Auto-applied discount' : 'Coupon applied' }}
                </label>
                <div class="lp-discount-applied" data-testid="manual-discount-applied">
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
                            data-testid="manual-discount-clear"
                            aria-label="Remove discount">×</button>
                </div>
            @else
                <label for="manual-discount-code" class="lp-form-label">
                    Coupon code <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                </label>
                <div class="lp-discount-row">
                    <input id="manual-discount-code" type="text" wire:model="discountCode"
                           wire:keydown.enter.prevent="applyDiscount"
                           data-testid="manual-discount-code"
                           class="lp-input"
                           style="text-transform: uppercase; letter-spacing: 0.06em;"
                           placeholder="SUMMER25">
                    <button type="button" wire:click="applyDiscount"
                            data-testid="manual-discount-apply"
                            class="lp-btn">
                        <span wire:loading.remove wire:target="applyDiscount">Apply</span>
                        <span wire:loading wire:target="applyDiscount">…</span>
                    </button>
                </div>
                @if ($discountError)
                    <p class="lp-form-error" data-testid="manual-discount-error">{{ $discountError }}</p>
                @endif
            @endif
        </div>

        {{-- Itemised price breakdown, mirrors the math CreateBooking
             will apply on submit. Renders whenever room + dates are
             valid; picks up length-of-stay discounts, auto-apply /
             coupon discounts, and the breakfast-tier rate swap. --}}
        @php
            $manualPreview = $this->pricingPreview();
        @endphp
        @if ($manualPreview !== null)
            @include('livewire.dashboard._partials.price-breakdown', [
                'breakdown' => $manualPreview['breakdown'],
                'discountOff' => $manualPreview['discountOff'],
                'discountLabel' => $manualPreview['discountLabel'],
                'discountCode' => $manualPreview['discountCode'],
                'discountAutoApplied' => $manualPreview['discountAutoApplied'],
                'showDeposit' => $payment_settlement === 'pending',
                'compact' => false,
            ])

            {{-- Manual front-desk discount. Alpine-driven so the mode
                 toggle + slider + amount input are instant — no wire
                 round-trip per keystroke. State is @entangle'd to
                 Livewire so the save() action reads the current
                 values without an extra sync. --}}
            <div style="border: 1px solid var(--color-line-soft); border-radius: 12px; padding: 14px 16px; margin-top: 6px;"
                 x-data="{
                    mode: @entangle('manual_discount_type'),
                    pct: @entangle('manual_discount_percent'),
                    fixed: @entangle('manual_discount_fixed_input'),
                    preTotal: {{ (int) ($manualPreview['preManualTotal'] ?? 0) }},
                    get amount() {
                        if (this.mode === 'fixed') {
                            return Math.max(0, Math.min(Number(this.fixed) || 0, this.preTotal));
                        }
                        const p = Math.max(0, Math.min(100, Number(this.pct) || 0));
                        return Math.floor(this.preTotal * p / 100);
                    },
                    get newTotal() { return Math.max(0, this.preTotal - this.amount); },
                    money(n) { return '₦' + Number(n || 0).toLocaleString(); },
                 }">
                <div class="flex items-center justify-between">
                    <label for="manual-discount" class="lp-form-label" style="margin: 0;">
                        Manual discount
                        <span style="color: var(--color-ink-mute); font-weight: 400; letter-spacing: 0; text-transform: none;">
                            · optional
                        </span>
                    </label>
                    <div class="lp-mode-toggle inline-flex" role="group" aria-label="Discount type"
                         style="background: var(--color-card-deep); border-radius: 999px; padding: 2px; gap: 2px;">
                        <button type="button"
                                @click="mode = 'percent'; fixed = 0"
                                data-testid="manual-discount-type-percent"
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
                                data-testid="manual-discount-type-fixed"
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
                <div x-show="mode === 'percent'" class="flex items-center gap-3 mt-3">
                    <input id="manual-discount" type="range" min="0" max="100" step="0.5"
                           x-model.number="pct"
                           @change="$wire.$refresh()"
                           @keydown.enter.prevent
                           data-testid="manual-discount-slider"
                           style="flex: 1; accent-color: var(--color-accent-deep);"
                           aria-label="Manual discount percent">
                    <div style="position: relative; width: 92px;">
                        <input type="number" min="0" max="100" step="0.01"
                               x-model.number="pct"
                               @change="$wire.$refresh()"
                               @keydown.enter.prevent
                               data-testid="manual-discount-input"
                               class="lp-input"
                               style="width: 100%; text-align: right; padding-right: 24px;"
                               aria-label="Manual discount percent (direct entry)">
                        <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
                                     font-size: 12px; color: var(--color-ink-mute); pointer-events: none;">%</span>
                    </div>
                </div>
                <div x-show="mode === 'fixed'" x-cloak class="mt-3" style="position: relative;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                                 font-size: 14px; color: var(--color-ink-mute); pointer-events: none;">₦</span>
                    <input type="number" min="0" step="1"
                           x-model.number="fixed"
                           @change="$wire.$refresh()"
                           @keydown.enter.prevent
                           data-testid="manual-discount-fixed-input"
                           class="lp-input"
                           style="width: 100%; padding-left: 26px;"
                           placeholder="e.g. 5000"
                           aria-label="Manual discount, flat ₦ amount">
                </div>
                <p x-show="amount > 0" x-cloak
                   class="text-[12.5px] mt-2" data-testid="manual-discount-line"
                   style="color: var(--color-accent-deep);">
                    −<span x-text="money(amount)"></span> off ·
                    new total <strong>&#8358;<span x-text="Number(newTotal).toLocaleString()"></span></strong>
                    <span style="color: var(--color-ink-mute);">
                        · recorded against your operator profile
                    </span>
                </p>
                @error('manual_discount_percent')
                    <p class="lp-form-error">{{ $message }}</p>
                @enderror
                @error('manual_discount_fixed_input')
                    <p class="lp-form-error">{{ $message }}</p>
                @enderror
            </div>
        @endif
        @endif {{-- /not-categoryOnly wrapper for discount + preview block --}}

        {{-- Booking channel, where the reservation came in from. Feeds
             the revenue-by-channel report + payments channel breakdown. --}}
        <fieldset class="lp-fieldset" data-testid="manual-source-block">
            <legend>Booking channel</legend>
            <div class="mt-2">
                <select wire:model.live="source"
                        data-testid="manual-source"
                        class="lp-input" style="padding: 10px 12px;">
                    @foreach (\App\Enums\BookingSource::pickerCases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('source') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            @if ($source === 'other' || $source === 'booking_com' || $source === 'airbnb' || $source === 'hotels_ng' || $source === 'jumia_travel' || $source === 'direct')
                <div class="mt-3">
                    <label for="manual-source-note" class="lp-form-label">
                        @if ($source === 'other') Channel name @else Note <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span> @endif
                    </label>
                    <input id="manual-source-note" type="text" wire:model="source_note"
                           data-testid="manual-source-note" class="lp-input" maxlength="120"
                           placeholder="{{ $source === 'other' ? 'e.g. Facebook Marketplace' : 'e.g. second account, corporate contract' }}">
                    @error('source_note') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            @endif
        </fieldset>

        {{-- Payment settlement, runs on both the specific-room and
             category-only paths so the guest can pay a deposit or
             the full stay at reservation time. Category-only skips
             the room-policy minimum-deposit floor (there's no per-
             room policy to enforce until the front desk pins one at
             check-in). --}}
        @php
            // Min deposit + net total come from PricingCalculator on
            // the specific-room path. Category-only computes both
            // inline: net = nights × category.base_rate, no min.
            if ($categoryOnly) {
                $minDeposit = 0;
                $netTotal = 0;
                if ($pickedCategory && $checkin !== '' && $checkout !== '') {
                    try {
                        $ci = \Illuminate\Support\Carbon::parse($checkin);
                        $co = \Illuminate\Support\Carbon::parse($checkout);
                        if ($co->greaterThan($ci)) {
                            $netTotal = (int) $ci->diffInDays($co, absolute: true)
                                * (int) $pickedCategory->base_rate;
                        }
                    } catch (\Throwable) {}
                }
            } else {
                $preview = $this->pricingPreview();
                $minDeposit = $preview !== null ? (int) $preview['breakdown']->depositAmount : 0;
                $netTotal = $preview !== null
                    ? max(0, (int) $preview['breakdown']->netSubtotal - (int) $preview['discountOff'])
                    : 0;
            }
        @endphp
        {{-- Alpine scope for the payment-settlement-dependent blocks.
             Entangling the status makes the radios flip locally so the
             conditional blocks appear/disappear instantly, without the
             server round-trip that was causing the modal-like flash. --}}
        <fieldset class="lp-fieldset"
                  x-data="{ status: @entangle('payment_settlement') }">
            <legend>Payment settlement</legend>
            <div class="grid gap-1 mt-2">
                <label class="lp-radio-row">
                    <input type="radio" x-model="status" value="succeeded">
                    <span>Paid in full (cash / off-platform), mark <strong>Confirmed</strong></span>
                </label>
                <label class="lp-radio-row" data-testid="payment-on-hold-radio">
                    <input type="radio" x-model="status" value="on_hold">
                    <span>
                        Deposit only, mark <strong>On hold</strong>
                        <span style="color: var(--color-ink-mute); font-weight: 400;">
                            &middot; balance collected before check-in
                        </span>
                    </span>
                </label>
                @if (! $categoryOnly && $this->pickedCustomerWalletBalance > 0)
                    <label class="lp-radio-row" data-testid="payment-wallet-radio">
                        <input type="radio" x-model="status" value="wallet">
                        <span>
                            Pay from wallet
                            <span style="color: var(--color-ink-mute); font-weight: 400;">
                                &middot; balance available:
                                <strong>&#8358;{{ number_format($this->pickedCustomerWalletBalance) }}</strong>.
                                Confirmed if it covers the full total; On-hold otherwise.
                            </span>
                        </span>
                    </label>
                @endif
                <label class="lp-radio-row">
                    <input type="radio" x-model="status" value="pending">
                    <span>Not paid yet, mark <strong>Pending</strong></span>
                </label>
            </div>
            @error('payment_settlement')
                <p class="lp-form-error mt-2" data-testid="payment-settlement-error">{{ $message }}</p>
            @enderror

            {{-- Payment method, shown for both settled + on-hold since
                 both record a Payment row and Finance needs to know how
                 the money came in. Hidden for wallet + pending. --}}
            <div class="mt-3" x-show="status === 'succeeded' || status === 'on_hold'" x-cloak>
                <label for="manual-payment-method" class="lp-form-label">Payment method</label>
                {{-- :required tracks visibility so a hidden static
                     required doesn't trip "invalid form control is
                     not focusable" on submit. --}}
                <select id="manual-payment-method" wire:model="payment_method"
                        :required="status === 'succeeded' || status === 'on_hold'"
                        data-testid="manual-payment-method" class="lp-input">
                    <option value="cash">Cash</option>
                    <option value="transfer">Bank transfer</option>
                    <option value="card">Card</option>
                    <option value="pos">POS terminal</option>
                    <option value="other">Other</option>
                </select>
                @error('payment_method') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Deposit amount, only on the on_hold path. Overpayment
                 (> total) is allowed and its surplus lands in the guest
                 wallet via RecordManualPayment. Outstanding / change
                 hint runs entirely in Alpine so per-keystroke changes
                 don't round-trip the server. --}}
            <div class="mt-3" x-show="status === 'on_hold'" x-cloak
                 data-testid="manual-deposit-block"
                 x-data="{
                    amount: @entangle('payment_amount'),
                    netTotal: {{ (int) $netTotal }},
                    hasCustomer: @js($this->selectedCustomerId !== null),
                    get collected() { return Number(this.amount) || 0 },
                    get outstanding() { return Math.max(0, this.netTotal - this.collected) },
                    get surplus() { return Math.max(0, this.collected - this.netTotal) },
                    money(n) { return '₦' + Number(n || 0).toLocaleString() },
                 }">
                <label for="manual-deposit-amount" class="lp-form-label">
                    Deposit received
                    @if ($minDeposit > 0)
                        <span style="color: var(--color-ink-mute); font-weight: 400;">
                            &middot; min &#8358;{{ number_format($minDeposit) }} per room policy
                        </span>
                    @endif
                </label>
                <input id="manual-deposit-amount" type="number" min="1" step="1"
                       :required="status === 'on_hold'"
                       x-model.number="amount"
                       @keydown.enter.prevent
                       data-testid="manual-deposit-amount" class="lp-input"
                       placeholder="{{ $minDeposit > 0
                           ? '₦'.number_format($minDeposit)
                           : 'e.g. 10000' }}">
                @error('payment_amount') <p class="lp-form-error">{{ $message }}</p> @enderror

                <p x-show="netTotal > 0 && collected > 0 && outstanding > 0"
                   x-cloak class="text-[12px] mt-1" data-testid="manual-outstanding"
                   style="color: #B85C42;">
                    Outstanding to collect before check-in:
                    <strong>&#8358;<span x-text="Number(outstanding).toLocaleString()"></span></strong>
                </p>
                <p x-show="netTotal > 0 && collected > 0 && surplus > 0"
                   x-cloak class="text-[12px] mt-1" data-testid="manual-change"
                   style="color: var(--color-accent-deep);">
                    Booking fully paid ·
                    <strong>&#8358;<span x-text="Number(surplus).toLocaleString()"></span></strong>
                    <template x-if="hasCustomer">
                        <span>will credit to the guest's wallet.</span>
                    </template>
                    <template x-if="!hasCustomer">
                        <span>change will not auto-credit (no linked customer).</span>
                    </template>
                </p>
                <p x-show="netTotal > 0 && collected > 0 && outstanding === 0 && surplus === 0"
                   x-cloak class="text-[12px] mt-1" data-testid="manual-exact"
                   style="color: var(--color-accent-deep);">
                    Booking fully paid, no balance owed.
                </p>
            </div>
        </fieldset>

        {{-- Actions, sticks to the bottom of the viewport on phones
             so the operator never loses the Save button mid-form. --}}
        <div class="lp-form-actions-sticky flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.bookings.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" data-testid="manual-save" class="lp-pill">
                <span wire:loading.remove wire:target="save">Create booking</span>
                <span wire:loading wire:target="save">Creating…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>

    {{-- Post-create "Check in now?" prompt — surfaces when the
         reservation is for today AND the booking is Confirmed / OnHold. --}}
    @include('livewire.dashboard._partials.checkin-prompt')

    {{-- ── Floating booking summary ──────────────────────────────
         Sticky-right price + context recap so the operator never
         loses sight of what they're building while filling out a
         long form. Reads current values from the same Livewire
         props already bound above — no new state. Hides itself
         until at least one meaningful value is entered so the
         empty form doesn't get pill-crowned by a "₦0" chip. --}}
    <div class="lp-manual-summary" data-testid="manual-booking-summary"
         wire:ignore.self
         x-data="{
            guest: @entangle('guest_name'),
            ci: @entangle('checkin'),
            co: @entangle('checkout'),
            net: {{ (int) ($netTotal ?? 0) }},
            money(n) { return '₦' + Number(n || 0).toLocaleString() },
            get nights() {
                if (!this.ci || !this.co) return 0;
                const a = new Date(this.ci), b = new Date(this.co);
                const d = Math.round((b - a) / 86400000);
                return d > 0 ? d : 0;
            },
            get hasAny() {
                return (this.guest && this.guest.trim() !== '')
                    || this.nights > 0
                    || this.net > 0;
            },
         }"
         x-show="hasAny"
         x-cloak>
        <div class="lp-manual-summary__inner">
            <div class="lp-manual-summary__line">
                <span class="lp-manual-summary__label">Booking</span>
                <span class="lp-manual-summary__value">
                    <template x-if="guest && guest.trim() !== ''">
                        <strong x-text="guest"></strong>
                    </template>
                    <template x-if="!guest || guest.trim() === ''">
                        <em style="color: var(--color-ink-mute); font-style: normal;">Unnamed guest</em>
                    </template>
                    <template x-if="nights > 0">
                        <span>&nbsp;·&nbsp;<span x-text="nights"></span>
                              <span x-text="nights === 1 ? 'night' : 'nights'"></span></span>
                    </template>
                </span>
            </div>
            <div class="lp-manual-summary__line" x-show="net > 0" x-cloak>
                <span class="lp-manual-summary__label">Net total</span>
                <span class="lp-manual-summary__value lp-manual-summary__amount"
                      x-text="money(net)"></span>
            </div>
        </div>
    </div>

    <style>
        /* Sticky floating summary card. Fixed to the bottom-right
           corner on desktop (offset above the WhatsApp float slot),
           spans the full width on phones as a floor sheet. Tabular
           digits so the amount doesn't jitter as it grows. */
        .lp-page .lp-manual-summary {
            position: fixed;
            right: 24px;
            bottom: 24px;
            z-index: 40;
            padding: 12px 16px;
            border-radius: 999px;
            background: var(--color-ink, #14171A);
            color: var(--color-paper, #F5F1E8);
            box-shadow:
                0 18px 44px -14px rgba(11, 9, 6, 0.55),
                0 0 0 1px rgba(245, 241, 232, 0.06);
            font-size: 12.5px;
            font-family: var(--font-body);
            max-width: min(420px, calc(100vw - 32px));
        }
        .lp-page .lp-manual-summary__inner {
            display: inline-flex; align-items: center; gap: 18px;
            flex-wrap: wrap;
        }
        .lp-page .lp-manual-summary__line {
            display: inline-flex; align-items: baseline; gap: 8px;
        }
        .lp-page .lp-manual-summary__label {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 9.5px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(245, 241, 232, 0.55);
        }
        .lp-page .lp-manual-summary__value {
            color: var(--color-paper);
            font-weight: 500;
        }
        .lp-page .lp-manual-summary__amount {
            font-family: var(--font-display);
            font-weight: 600;
            font-size: 16px;
            font-variant-numeric: tabular-nums lining-nums;
            color: var(--color-accent, #B6D97E);
        }
        @media (max-width: 700px) {
            .lp-page .lp-manual-summary {
                left: 12px; right: 12px; bottom: 12px;
                max-width: none;
                border-radius: 14px;
                text-align: center;
            }
            .lp-page .lp-manual-summary__inner {
                justify-content: center;
            }
        }
    </style>
</div>
