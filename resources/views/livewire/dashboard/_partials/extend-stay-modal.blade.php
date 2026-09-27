{{--
    Shared "Extend stay" modal. Every fast-moving input lives in
    Alpine - no @entangle, no per-keystroke $wire.$refresh(). The
    only server round-trips are:

      1. openExtendModal(id) - server snapshots booking totals + rate
         onto the trait's public props (extendCurrentTotal, etc.).
         The modal's x-init copies those into Alpine as soon as it
         opens.
      2. submitExtend(...) - server executes ExtendBooking +
         ApplyPostCheckinDiscount + RecordManualPayment in one shot.

    Discount toggles, payment amount, quick-pay picks all resolve
    inside Alpine using the seeded snapshot, so preview numbers move
    the instant the operator touches an input.
--}}

{{-- Spinner while the openExtendModal round-trip is in flight.
     Teleported to body so no parent transform/overflow can clip
     it, and positioned dead-center of the viewport with a plain
     translate - no backdrop, no sidebar offset. --}}
<template x-teleport="body">
<div wire:loading wire:target="openExtendModal"
     data-testid="extend-modal-loader"
     style="position: fixed; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            z-index: 210; pointer-events: none;">
    <svg width="44" height="44" viewBox="0 0 44 44" fill="none"
         style="animation: extend-loader-spin 0.9s linear infinite;">
        <circle cx="22" cy="22" r="18" stroke="rgba(20,17,12,0.14)" stroke-width="3"></circle>
        <path d="M22 4 a18 18 0 0 1 18 18"
              stroke="var(--color-accent-deep)"
              stroke-width="3" stroke-linecap="round" fill="none"></path>
    </svg>
    <style>@@keyframes extend-loader-spin { to { transform: rotate(360deg); } }</style>
</div>
</template>

<template x-teleport="body">
<div x-data="extendStayModal()"
     x-init="init()"
     x-show="open"
     x-cloak
     x-on:extend-modal-open.window="hydrateFromWire(); open = true; $nextTick(() => $refs.dateInput?.focus())"
     x-on:extend-modal-close.window="open = false"
     x-on:keydown.escape.window="if (open) close()"
     @click.self="close()"
     data-testid="extend-modal-backdrop"
     class="lp-modal-backdrop"
     style="z-index: 200;
            background: rgba(20, 22, 24, 0.42);
            backdrop-filter: blur(2px);">
    <div @click.stop
         data-testid="extend-modal-card"
         class="lp-page"
         style="width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto;
                background: var(--color-card);
                border-radius: 16px;
                border: 1px solid var(--color-line-soft);
                box-shadow: 0 24px 60px rgba(0,0,0,0.22);
                padding: 24px 26px 22px;">

        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="lp-mono text-[10.5px]"
                   style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                    Extend stay
                </p>
                <h2 class="lp-display" style="font-size: 20px; font-weight: 600; margin-top: 2px;"
                    x-text="guestName ? 'Extend ' + guestName : 'Pick a new checkout'"></h2>
            </div>
            <button type="button" @click="close()"
                    aria-label="Close"
                    data-testid="extend-modal-close"
                    style="background: transparent; border: 0; cursor: pointer;
                           font-size: 22px; color: var(--color-ink-mute);
                           padding: 4px 8px;">×</button>
        </div>

        {{-- Extension impossible: room is booked back-to-back so there's
             no free night to extend into. Front-desk should switch the
             guest to another room (change room) rather than trying to
             extend this one. --}}
        <template x-if="extensionImpossible">
            <div style="margin-top: 14px; padding: 14px; background: #FFF4E5;
                        border: 1px solid #F5D9A8; border-radius: 10px;"
                 data-testid="extend-blocked-notice">
                <p style="margin: 0; font-size: 13.5px; color: var(--color-ink); line-height: 1.55;">
                    The next guest arrives on
                    <strong x-text="formatDate(maxCheckout)"></strong> -
                    this room can't be extended. If the guest wants to stay longer,
                    move them to another available room instead.
                </p>
                <button type="button" @click="close()"
                        class="lp-btn lp-btn--sm" style="margin-top: 10px;"
                        data-testid="extend-blocked-close">Close</button>
            </div>
        </template>

        <form @submit.prevent="submit()"
              x-show="! extensionImpossible"
              {{-- Keep payAmount mirrored to the active chip whenever the
                   dependencies of the amount (extra nights, discount,
                   wallet cap) change. No-op when the operator has typed
                   a manual amount (payChoice='none'). --}}
              x-effect="syncPayAmountToChoice()"
              style="display: grid; gap: 14px; margin-top: 14px;">

            {{-- New checkout --}}
            <div>
                <label for="extend-new-checkout" class="lp-form-label">New checkout</label>
                <input id="extend-new-checkout" type="date"
                       x-ref="dateInput"
                       x-model="newCheckout"
                       :min="minCheckout"
                       :max="maxCheckout || null"
                       @keydown.enter.prevent
                       data-testid="extend-new-checkout"
                       class="lp-input">
                {{-- Ceiling hint: the room is booked to the next
                     guest from maxCheckout onwards, so extensions
                     can't go past that day. Nothing shown when the
                     room is free forward - no artificial cap. --}}
                <template x-if="maxCheckout">
                    <p class="lp-form-help" style="margin-top: 4px; color: var(--color-ink-mute);"
                       data-testid="extend-max-hint">
                        Room is booked from <strong x-text="formatDate(maxCheckout)"></strong> onwards -
                        this stay can extend up to that day.
                    </p>
                </template>
                {{-- Hard-block message when the guest tries to type
                     past the ceiling - matches the server-side guard
                     so they see the same story either way. --}}
                <template x-if="newCheckout && maxCheckout && newCheckout > maxCheckout">
                    <p class="lp-form-error" data-testid="extend-past-ceiling">
                        The room is reserved for another guest from
                        <strong x-text="formatDate(maxCheckout)"></strong>. Pick an earlier date.
                    </p>
                </template>
            </div>

            {{-- Breakfast tier toggle - only when the category offers
                 the breakfast rate. Defaults to the booking's original
                 tier so a guest who booked with breakfast isn't
                 silently downgraded on extend. --}}
            <template x-if="hasBreakfastOption">
                <div style="border: 1px solid var(--color-line-soft);
                            border-radius: 12px; padding: 12px 14px;"
                     data-testid="extend-breakfast-row">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <label class="lp-form-label" style="margin: 0;">
                                Include breakfast
                            </label>
                            <p style="font-size: 11.5px; color: var(--color-ink-mute); margin: 4px 0 0;">
                                <span x-show="breakfast">Extension nights charged at
                                    <strong x-text="money(breakfastRate)"></strong>/night.</span>
                                <span x-show="!breakfast">Extension nights charged at
                                    <strong x-text="money(baseRate)"></strong>/night.</span>
                            </p>
                        </div>
                        <button type="button"
                                @click="breakfast = !breakfast"
                                :aria-pressed="breakfast"
                                data-testid="extend-breakfast-toggle"
                                :style="'width: 40px; height: 22px; border: 0; border-radius: 999px;'
                                      + 'cursor: pointer; position: relative; flex-shrink: 0;'
                                      + 'transition: background 160ms;'
                                      + 'background: ' + (breakfast ? 'var(--color-accent)' : 'var(--color-card-deep)') + ';'">
                            <span :style="'position: absolute; top: 2px; height: 18px; width: 18px;'
                                        + 'border-radius: 999px; background: #fff;'
                                        + 'transition: left 160ms;'
                                        + 'left: ' + (breakfast ? '20px' : '2px') + ';'"></span>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Live preview - pure Alpine. --}}
            <div style="background: var(--color-card-deep); border-radius: 10px;
                        padding: 12px 14px; display: grid; gap: 4px; font-size: 12.5px;"
                 data-testid="extend-modal-preview">
                <template x-if="extraNights <= 0">
                    <p style="color: var(--color-ink-mute);">
                        Pick a date after the current checkout to see the price.
                    </p>
                </template>
                <template x-if="extraNights > 0">
                    <div style="display: grid; gap: 4px;">
                        <div class="flex justify-between">
                            <span style="color: var(--color-ink-soft);">
                                <span x-text="extraNights + ' extra night' + (extraNights === 1 ? '' : 's')"></span>
                                × <span x-text="money(ratePerNight)"></span>
                            </span>
                            <span class="lp-mono" x-text="money(extensionSubtotal)"></span>
                        </div>
                        <div class="flex justify-between" x-show="inheritedPct > 0"
                             data-testid="extend-modal-inherited-line"
                             style="font-size: 11.5px;">
                            <span style="color: var(--color-ink-mute);">
                                <span x-text="inheritedPct + '% off inherited (was ' + money(rackRatePerNight) + '/night)'"></span>
                            </span>
                            <span></span>
                        </div>
                        <div class="flex justify-between" x-show="discountAmount > 0"
                             data-testid="extend-modal-discount-line">
                            <span style="color: var(--color-ink-soft);">Extra discount</span>
                            <span class="lp-mono" style="color: var(--color-accent-deep); font-weight: 600;"
                                  x-text="'−' + money(discountAmount)"></span>
                        </div>
                        <div class="flex justify-between"
                             style="border-top: 1px solid var(--color-line-soft); padding-top: 4px; margin-top: 2px;">
                            <span style="color: var(--color-ink-soft);">Extension total</span>
                            <span class="lp-mono" style="font-weight: 600;" x-text="money(extensionNet)"></span>
                        </div>
                        <div class="flex justify-between"
                             style="font-weight: 600; color: var(--color-ink); margin-top: 2px;">
                            <span>New booking total</span>
                            <span class="lp-mono" data-testid="extend-modal-new-total"
                                  x-text="money(newTotal)"></span>
                        </div>
                        <div class="flex justify-between" x-show="newBalanceBeforePayment > 0">
                            <span style="color: var(--color-ink-soft);">Balance after extension</span>
                            <span class="lp-mono" x-text="money(newBalanceBeforePayment)"></span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Discount block --}}
            <div style="border: 1px solid var(--color-line-soft);
                        border-radius: 12px; padding: 12px 14px;">
                <div class="flex items-center justify-between">
                    <label class="lp-form-label" style="margin: 0;">
                        Extension discount
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <div class="lp-mode-toggle inline-flex" role="group"
                         style="background: var(--color-card-deep); border-radius: 999px; padding: 2px; gap: 2px;">
                        <button type="button"
                                @click="mode = 'percent'; fixed = 0"
                                data-testid="extend-discount-mode-percent"
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
                                data-testid="extend-discount-mode-fixed"
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
                <div x-show="mode === 'percent'" class="mt-2" style="position: relative;">
                    <input type="number" min="0" max="100" step="0.01"
                           x-model.number="pct"
                           @keydown.enter.prevent
                           data-testid="extend-discount-percent"
                           class="lp-input"
                           style="width: 100%; text-align: right; padding-right: 26px;">
                    <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
                                 font-size: 12px; color: var(--color-ink-mute);">%</span>
                </div>
                <div x-show="mode === 'fixed'" x-cloak class="mt-2" style="position: relative;">
                    <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                                 font-size: 14px; color: var(--color-ink-mute);">₦</span>
                    <input type="number" min="0" step="1"
                           x-model.number="fixed"
                           @keydown.enter.prevent
                           data-testid="extend-discount-fixed"
                           class="lp-input"
                           style="width: 100%; padding-left: 26px;"
                           placeholder="e.g. 5000">
                </div>
            </div>

            {{-- Payment collection --}}
            <div style="border: 1px solid var(--color-line-soft);
                        border-radius: 12px; padding: 12px 14px;">
                <label class="lp-form-label">
                    Collect payment now
                    <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                </label>

                {{-- Quick-pick chips: Full / Extension only / Wallet.
                     Extension only is pre-selected on open (see the
                     component's payChoice default) - so the classic
                     "Cash for the extension total" flow now takes one
                     click. Chips render an active/selected style based
                     on payChoice so the operator can see what's about
                     to be collected without reading the amount input.
                     --}}
                <div class="flex flex-wrap gap-2 mt-2" data-testid="extend-quick-pay">
                    <button type="button"
                            @click="payFull()"
                            :disabled="newBalanceBeforePayment <= 0"
                            :aria-pressed="payChoice === 'full'"
                            data-testid="extend-pay-full"
                            :style="'padding: 5px 12px; border-radius: 999px;'
                                  + 'font-family: inherit; font-size: 11.5px;'
                                  + 'font-weight: ' + (payChoice === 'full' ? '600' : '500') + ';'
                                  + 'cursor: ' + (newBalanceBeforePayment > 0 ? 'pointer' : 'not-allowed') + ';'
                                  + 'opacity: ' + (newBalanceBeforePayment > 0 ? '1' : '0.4') + ';'
                                  + (payChoice === 'full'
                                      ? 'background: var(--color-accent); color: var(--color-ink); border: 0;'
                                      : 'background: transparent; color: var(--color-ink); border: 1px solid var(--color-line);')">
                        Full (<span x-text="money(newBalanceBeforePayment)"></span>)
                    </button>
                    <button type="button"
                            @click="payExtension()"
                            :disabled="extensionNet <= 0"
                            :aria-pressed="payChoice === 'extension'"
                            data-testid="extend-pay-extension"
                            :style="'padding: 5px 12px; border-radius: 999px;'
                                  + 'font-family: inherit; font-size: 11.5px;'
                                  + 'font-weight: ' + (payChoice === 'extension' ? '600' : '500') + ';'
                                  + 'cursor: ' + (extensionNet > 0 ? 'pointer' : 'not-allowed') + ';'
                                  + 'opacity: ' + (extensionNet > 0 ? '1' : '0.4') + ';'
                                  + (payChoice === 'extension'
                                      ? 'background: var(--color-accent); color: var(--color-ink); border: 0;'
                                      : 'background: transparent; color: var(--color-ink); border: 1px solid var(--color-line);')">
                        Extension only (<span x-text="money(extensionNet)"></span>)
                    </button>
                    <button type="button"
                            @click="payWallet()"
                            x-show="walletBalance > 0"
                            :aria-pressed="payChoice === 'wallet'"
                            data-testid="extend-pay-wallet"
                            :style="'padding: 5px 12px; border-radius: 999px;'
                                  + 'font-family: inherit; font-size: 11.5px; cursor: pointer;'
                                  + 'font-weight: ' + (payChoice === 'wallet' ? '600' : '500') + ';'
                                  + (payChoice === 'wallet'
                                      ? 'background: var(--color-accent); color: var(--color-ink); border: 0;'
                                      : 'background: transparent; color: var(--color-ink); border: 1px solid var(--color-line);')">
                        Wallet (<span x-text="money(Math.min(walletBalance, newBalanceBeforePayment))"></span>)
                    </button>
                    <button type="button"
                            @click="payClear()"
                            x-show="payAmount > 0"
                            data-testid="extend-pay-clear"
                            style="background: transparent; color: var(--color-ink-mute);
                                   border: 0; padding: 5px 8px;
                                   font-family: inherit; font-size: 11.5px;
                                   cursor: pointer;">
                        Don't collect now
                    </button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 mt-3">
                    <div style="position: relative;">
                        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                                     font-size: 14px; color: var(--color-ink-mute);">₦</span>
                        <input type="number" min="0" step="1"
                               x-model.number="payAmount"
                               @input="onManualAmountEdit()"
                               @keydown.enter.prevent
                               data-testid="extend-payment-amount"
                               class="lp-input"
                               style="width: 100%; padding-left: 26px;"
                               placeholder="0">
                    </div>
                    <select x-model="payMethod"
                            data-testid="extend-payment-method"
                            class="lp-input">
                        <option value="cash">Cash</option>
                        <option value="transfer">Bank transfer</option>
                        <option value="card">Card</option>
                        <option value="pos">POS terminal</option>
                        <option value="wallet" x-show="walletBalance > 0">Wallet</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <p class="text-[12px] mt-2"
                   x-show="payAmount > 0"
                   data-testid="extend-payment-preview"
                   style="color: var(--color-ink-soft);">
                    Balance after payment:
                    <strong style="color: var(--color-ink);"
                            x-text="money(newBalanceAfterPayment)"></strong>
                </p>
            </div>

            {{-- Reason --}}
            <div>
                <label class="lp-form-label">
                    Reason <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                </label>
                <input type="text" x-model="reason" maxlength="240"
                       data-testid="extend-reason"
                       class="lp-input"
                       placeholder="e.g. late flight, one more night for a work meeting">
            </div>

            {{-- Foot --}}
            <div class="flex justify-end gap-3 pt-2"
                 style="border-top: 1px solid var(--color-line-soft);">
                <button type="button" @click="close()" class="lp-btn">Cancel</button>
                <button type="submit"
                        data-testid="extend-submit"
                        :disabled="submitting || extraNights <= 0"
                        :style="(submitting || extraNights <= 0) ? 'opacity: 0.4; cursor: not-allowed;' : ''"
                        class="lp-pill">
                    <span x-show="!submitting">Extend stay</span>
                    <span x-show="submitting" x-cloak>Extending…</span>
                </button>
            </div>
        </form>
    </div>
</div>
</template>

<script>
    window.extendStayModal = function () {
        return {
            // Livewire-owned state (seeded on open)
            open: false,
            guestName: '',
            currentCheckout: '',
            currentTotal: 0,
            currentBalance: 0,
            baseRate: 0,
            breakfastRate: 0,
            hasBreakfastOption: false,
            breakfast: false,     // toggle state; seeded from booking
            walletBalance: 0,
            // Percent discount inherited from the original booking.
            // Non-zero only for percent-type manual discounts. The
            // preview rate reflects this so operators see the guest's
            // real per-night price, not the rack rate.
            inheritedPct: 0,
            maxCheckout: '',      // ceiling from next reservation, '' = none
            // Modal-owned state (pure Alpine)
            newCheckout: '',
            mode: 'percent',
            pct: 0,
            fixed: 0,
            payAmount: 0,
            payMethod: 'cash',
            reason: '',
            submitting: false,
            /**
             * Which quick-pay chip is currently "active" - drives the
             * chip's visual state + keeps payAmount in sync when the
             * date / discount changes without the operator having to
             * re-click. 'none' = manual amount (operator typed or hit
             * Clear). 'extension' is the default because 90% of the
             * time the operator is collecting for the extension right
             * at the counter.
             */
            payChoice: 'extension',

            init() {
                // Nothing needed pre-open; hydrateFromWire() handles
                // the seed when the modal-open event fires. The x-effect
                // in the template keeps payAmount in sync with the
                // active chip when the operator changes dates or
                // discounts.
            },

            /**
             * Copy the snapshot the trait set on the Livewire component
             * into local Alpine state so preview computations run
             * without touching the network.
             */
            hydrateFromWire() {
                this.guestName          = this.$wire.get('extendGuestName') || '';
                this.currentCheckout    = this.$wire.get('extendCurrentCheckout') || '';
                this.currentTotal       = Number(this.$wire.get('extendCurrentTotal')) || 0;
                this.currentBalance     = Number(this.$wire.get('extendCurrentBalance')) || 0;
                this.baseRate           = Number(this.$wire.get('extendBaseRate')) || 0;
                this.breakfastRate      = Number(this.$wire.get('extendBreakfastRate')) || 0;
                this.hasBreakfastOption = !!this.$wire.get('extendHasBreakfastOption');
                this.breakfast          = !!this.$wire.get('extendBookingBreakfast');
                this.walletBalance      = Number(this.$wire.get('extendWalletBalance')) || 0;
                this.inheritedPct       = Number(this.$wire.get('extendInheritedDiscountPercent')) || 0;
                this.maxCheckout        = this.$wire.get('extendMaxCheckout') || '';
                // Reset modal-owned state so a reopen doesn't carry
                // stale values from a previous booking. When the
                // calendar drag preselected a target date, honour it
                // so the price preview populates without a second
                // click; otherwise fall back to "day after current".
                const preselect = this.$wire.get('extendPreselectCheckout') || '';
                this.newCheckout = preselect !== ''
                    ? preselect
                    : (this.currentCheckout ? this.addDay(this.currentCheckout, 1) : '');
                this.mode = 'percent';
                this.pct = 0;
                this.fixed = 0;
                this.payAmount = 0;
                this.payMethod = 'cash';
                this.reason = '';
                this.submitting = false;
                this.payChoice = 'extension';
            },

            /**
             * Called from an x-effect on the form. Keeps payAmount
             * mirrored to the active chip - so if the operator picks
             * a longer stay after already tapping "Extension only",
             * the amount reflects the new extension total without a
             * re-click. Skips the sync when payChoice is 'none' (the
             * operator typed a bespoke amount).
             */
            syncPayAmountToChoice() {
                if (this.payChoice === 'full') {
                    this.payAmount = this.newBalanceBeforePayment;
                } else if (this.payChoice === 'extension') {
                    this.payAmount = this.extensionNet;
                } else if (this.payChoice === 'wallet') {
                    this.payAmount = Math.min(this.walletBalance, this.newBalanceBeforePayment);
                }
            },

            addDay(dateStr, n) {
                const d = new Date(dateStr + 'T00:00:00');
                if (isNaN(d.getTime())) return dateStr;
                d.setDate(d.getDate() + n);
                return d.toISOString().slice(0, 10);
            },

            get minCheckout() {
                return this.currentCheckout
                    ? this.addDay(this.currentCheckout, 1)
                    : new Date().toISOString().slice(0, 10);
            },

            // No free night to extend into - next reservation checks
            // in on or before the current checkout, so the datepicker
            // can't produce a valid pick. Modal switches to the
            // room-change guidance instead.
            get extensionImpossible() {
                return !! this.maxCheckout
                    && !! this.currentCheckout
                    && this.maxCheckout <= this.currentCheckout;
            },

            get extraNights() {
                if (!this.newCheckout || !this.currentCheckout) return 0;
                const from = new Date(this.currentCheckout + 'T00:00:00');
                const to   = new Date(this.newCheckout + 'T00:00:00');
                if (isNaN(from.getTime()) || isNaN(to.getTime())) return 0;
                const diff = Math.round((to - from) / 86400000);
                return diff > 0 ? diff : 0;
            },

            get rackRatePerNight() {
                return this.breakfast && this.breakfastRate > 0
                    ? this.breakfastRate
                    : this.baseRate;
            },

            // Effective per-night rate the guest pays — rack minus the
            // percent discount inherited from the original booking.
            // Matches what ExtendBooking bills on the server side.
            get ratePerNight() {
                const rack = this.rackRatePerNight;
                if (this.inheritedPct <= 0) return rack;
                const pct = Math.max(0, Math.min(100, Number(this.inheritedPct) || 0));
                return rack - Math.floor(rack * pct / 100);
            },

            get extensionSubtotal() {
                return this.extraNights * this.ratePerNight;
            },

            get discountAmount() {
                if (this.mode === 'fixed') {
                    return Math.max(0, Math.min(Number(this.fixed) || 0, this.extensionSubtotal));
                }
                const p = Math.max(0, Math.min(100, Number(this.pct) || 0));
                return Math.floor(this.extensionSubtotal * p / 100);
            },

            get extensionNet()             { return Math.max(0, this.extensionSubtotal - this.discountAmount); },
            get newTotal()                 { return this.currentTotal + this.extensionNet; },
            get newBalanceBeforePayment()  { return this.currentBalance + this.extensionNet; },
            get newBalanceAfterPayment()   { return Math.max(0, this.newBalanceBeforePayment - (Number(this.payAmount) || 0)); },

            money(n) { return '₦' + Number(n || 0).toLocaleString(); },

            formatDate(dateStr) {
                if (! dateStr) return '';
                const d = new Date(dateStr + 'T00:00:00');
                if (isNaN(d.getTime())) return dateStr;
                return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
            },

            // ── Quick-pay actions ────────────────────────────────
            payFull() {
                this.payChoice = 'full';
                this.payAmount = this.newBalanceBeforePayment;
                if (this.payMethod === 'wallet' && this.walletBalance < this.payAmount) this.payMethod = 'cash';
            },
            payExtension() {
                this.payChoice = 'extension';
                this.payAmount = this.extensionNet;
                if (this.payMethod === 'wallet' && this.walletBalance < this.payAmount) this.payMethod = 'cash';
            },
            payWallet() {
                this.payChoice = 'wallet';
                this.payAmount = Math.min(this.walletBalance, this.newBalanceBeforePayment);
                this.payMethod = 'wallet';
            },
            payClear() {
                this.payChoice = 'none';
                this.payAmount = 0;
            },
            /** Operator typed a bespoke amount - drop chip highlight. */
            onManualAmountEdit() {
                if (this.payChoice !== 'none') this.payChoice = 'none';
            },

            close() {
                this.open = false;
                // Server-side too so subsequent renders don't
                // re-show the modal on a Livewire refresh.
                if (this.$wire?.set) this.$wire.set('extendOpen', false, false);
            },

            async submit() {
                if (this.extraNights <= 0 || this.submitting) return;
                // Client-side guard mirrors the server exception path so
                // a mis-typed date shows the same message here as at the
                // portal + still can't slip through.
                if (this.maxCheckout && this.newCheckout > this.maxCheckout) return;
                this.submitting = true;
                try {
                    await this.$wire.submitExtend(
                        this.newCheckout,
                        this.mode,
                        Number(this.pct) || 0,
                        Number(this.fixed) || 0,
                        Number(this.payAmount) || 0,
                        this.payMethod,
                        this.reason || '',
                        this.hasBreakfastOption ? this.breakfast : null,
                    );
                } finally {
                    this.submitting = false;
                }
            },
        };
    };
</script>
