{{-- Payment action sheet, a bottom-sheet-on-mobile / centered-card-
     on-desktop overlay that hosts the method chips, tender rows, notes
     and Complete button. Cart state stays intact behind it, so closing
     is silent (backdrop click / X / Escape / Cancel) and re-opening
     one-taps back in via "Take payment".

     Teleported to <body> so ancestor transform / contain doesn't turn
     position:fixed into position-relative-to-ancestor (the kiosk shell
     creates a containing block that would pin the modal to the cart
     column on desktop).

     Nested modal note: the charge-to-room picker (_booking-lookup-modal)
     opens on top of this at z-index 900. Escape here is guarded so
     it only closes the payment sheet when nothing is stacked above. --}}
<template x-teleport="body">
{{-- .lp-page wrapper restores the CSS scope (`.lp-page .lp-btn` etc.)
     after teleporting out of the terminal. display:contents so the
     wrapper occupies no layout, the inner fixed positioning still
     resolves against the viewport. --}}
<div class="lp-page" style="display: contents;">
<div x-show="$store.pos.paymentOpen"
     x-cloak
     @click.self="$store.pos.cancelPayment()"
     @keydown.escape.window="if (!$store.pos.bookingModalOpen) $store.pos.cancelPayment()"
     data-testid="pay-sheet"
     class="lp-pay-modal-backdrop">
    <div class="lp-pay-modal-panel">

        {{-- Header, total + line count so the operator doesn't have to
             close the sheet to double-check what they're charging. --}}
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--color-line);">
            <div>
                <div class="lp-mono" style="font-size: 10.5px; color: var(--color-ink-mute); text-transform: uppercase; letter-spacing: 0.08em;">
                    Take payment
                </div>
                <div class="lp-num-lg" style="font-size: 26px; color: var(--color-ink); margin-top: 2px;">
                    &#8358;<span x-text="$store.pos.total().toLocaleString()"></span>
                </div>
                <div style="color: var(--color-ink-mute); font-size: 12.5px; margin-top: 2px;">
                    <span x-text="$store.pos.cart.length"></span>
                    item<span x-show="$store.pos.cart.length !== 1">s</span>
                </div>
            </div>
            <button type="button"
                    @click="$store.pos.cancelPayment()"
                    data-testid="pay-close"
                    aria-label="Close"
                    style="background: none; border: 0; font-size: 24px; line-height: 1; color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;">
                ×
            </button>
        </div>

        {{-- Split-tender toggle --}}
        <div class="flex items-center justify-between mb-3">
            <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">Method</span>
            <button type="button" @click="$store.pos.enableSplit()" x-show="!$store.pos.splitMode && !$store.pos.saleComp"
                    class="lp-btn" data-testid="pay-split" style="font-size: 12px; padding: 4px 12px;">
                + Split tender
            </button>
            <button type="button" @click="$store.pos.disableSplit()" x-show="$store.pos.splitMode"
                    class="lp-btn" data-testid="pay-single" style="font-size: 12px; padding: 4px 12px;">
                ← Single tender
            </button>
        </div>

        <template x-if="!$store.pos.splitMode">
            <div>
                {{-- Method chips, room + wallet chips disabled when offline
                     or when the resolved booking's guest wallet can't cover
                     the sale total. The Comp chip lives here as its own
                     "method" — picking it flips the sale to complimentary
                     and short-circuits the money math. --}}
                <div class="lp-chips" data-testid="pay-methods">
                    @foreach (['cash' => 'Cash', 'transfer' => 'Transfer', 'card' => 'Card', 'room' => 'Charge to room'] as $key => $label)
                        <button type="button"
                                @click="$store.pos.setMethod('{{ $key }}'); $store.pos.saleComp = false"
                                :class="'lp-chip ' + ($store.pos.method === '{{ $key }}' && !$store.pos.saleComp ? 'is-active' : '') + ({{ $key === 'room' ? '(!$store.pos.isOnline ? \' is-disabled\' : \'\')' : '\'\'' }})"
                                @if ($key === 'room')
                                    :disabled="!$store.pos.isOnline"
                                    :title="!$store.pos.isOnline ? 'Requires network, booking lookup needed' : ''"
                                @endif
                                data-testid="pay-method-{{ $key }}">
                            {{ $label }}
                        </button>
                    @endforeach
                    <button type="button"
                            @click="$store.pos.setMethod('wallet'); $store.pos.saleComp = false"
                            :class="'lp-chip ' + ($store.pos.method === 'wallet' && !$store.pos.saleComp ? 'is-active' : '') + (!$store.pos.walletAvailable($store.pos.total()) ? ' is-disabled' : '')"
                            :disabled="!$store.pos.walletAvailable($store.pos.total())"
                            :title="!$store.pos.bookingId ? 'Pick a booking to enable Wallet' : (Number($store.pos.walletBalance) < $store.pos.total() ? 'Wallet balance too low' : '')"
                            data-testid="pay-method-wallet">
                        Wallet
                        <span x-show="$store.pos.bookingId" style="opacity: 0.7; margin-left: 4px;"
                              x-text="'&#8358;' + Number($store.pos.walletBalance).toLocaleString()"></span>
                    </button>
                    {{-- Comp: complimentary sale. When active, tender + amount
                         inputs hide and the reason textarea below becomes
                         the only required field. Works alongside a picked
                         booking or a walk-in — comp is orthogonal to who
                         the guest is. --}}
                    <button type="button"
                            @click="$store.pos.saleComp = !$store.pos.saleComp; if ($store.pos.saleComp) { $store.pos.splitMode = false; }"
                            :class="'lp-chip ' + ($store.pos.saleComp ? 'is-active' : '')"
                            data-testid="pay-method-comp"
                            title="Mark this whole sale as complimentary — on the house">
                        🎁 Comp
                    </button>
                </div>

                {{-- Comp reason: required for the audit trail. --}}
                <div class="mt-3" x-show="$store.pos.saleComp" x-cloak>
                    <label for="pay-comp-reason" class="lp-form-label">Why is this comp?</label>
                    <input id="pay-comp-reason" type="text"
                           x-model="$store.pos.saleCompReason"
                           data-testid="pay-comp-reason"
                           class="lp-input"
                           maxlength="200"
                           placeholder="e.g. VIP welcome drink · Broken order re-fired · Room 305 birthday">
                    <p class="mt-2 lp-mono text-[11px]" style="color: var(--color-ink-mute); letter-spacing: 0.04em;">
                        Guest pays &#8358;0 · <span x-text="$store.pos.customerName || 'walk-in'"></span> ·
                        credit &#8358;<span x-text="$store.pos.compTotal().toLocaleString()"></span> attributed to you.
                    </p>
                </div>

                <div class="mt-3" x-show="$store.pos.method === 'cash'">
                    <label for="pay-tendered" class="lp-form-label">Cash tendered (&#8358;)</label>
                    <input id="pay-tendered" type="number" x-model.number="$store.pos.cashTendered" min="0"
                           data-testid="pay-tendered" class="lp-input" style="font-size: 20px;">
                    <p class="mt-2 lp-mono text-[13px]" data-testid="pay-change"
                       :style="'color: ' + ($store.pos.change() > 0 ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)')">
                        Change: &#8358;<span x-text="$store.pos.change().toLocaleString()"></span>
                    </p>
                </div>

                <div x-show="($store.pos.method === 'room' || $store.pos.method === 'wallet') && $store.pos.isOnline">
                    @include('livewire.dashboard.pos._booking-lookup', [
                        'bookingRef' => $bookingRef,
                        'bookingIdResolved' => $bookingIdResolved,
                        'bookingLookupError' => $bookingLookupError,
                        'inHouseBookings' => $inHouseBookings,
                    ])
                </div>
            </div>
        </template>

        <template x-if="$store.pos.splitMode">
            <div>
                <div data-testid="pay-tenders" style="display: grid; gap: 8px;">
                    <template x-for="(t, index) in $store.pos.tenders" :key="index">
                        <div class="flex items-center gap-2" :data-testid="'pay-tender-row-' + index">
                            <select x-model="t.method"
                                    class="lp-input" style="width: auto; font-size: 13px;"
                                    :data-testid="'pay-tender-method-' + index">
                                <option value="cash">Cash</option>
                                <option value="transfer">Transfer</option>
                                <option value="card">Card</option>
                                <option value="room" :disabled="!$store.pos.isOnline">Charge to room</option>
                                <option value="wallet" :disabled="!$store.pos.isOnline || !$store.pos.bookingId">Guest wallet</option>
                            </select>
                            <input type="number" x-model.number="t.amount" min="0"
                                   class="lp-input" style="flex: 1; text-align: right;"
                                   :data-testid="'pay-tender-amount-' + index"
                                   placeholder="0">
                            <button type="button" @click="$store.pos.removeTender(index)"
                                    aria-label="Remove tender"
                                    style="color: #B85C42; background: none; border: none; cursor: pointer; font-size: 20px;">×</button>
                        </div>
                    </template>
                </div>
                <div class="flex items-center justify-between mt-3">
                    <button type="button" @click="$store.pos.addTender()" class="lp-btn"
                            data-testid="pay-add-tender" style="font-size: 12px; padding: 4px 12px;">+ Add tender</button>
                    <span class="lp-mono text-[12px]"
                          data-testid="pay-tender-total"
                          :style="'color: ' + ($store.pos.tenderDelta() === 0 ? 'var(--color-accent-deep)' : '#B85C42')">
                        <span x-text="$store.pos.tenderTotal().toLocaleString()"></span> / <span x-text="$store.pos.total().toLocaleString()"></span>
                        <template x-if="$store.pos.tenderDelta() !== 0">
                            <span>
                                <span x-show="$store.pos.tenderDelta() > 0"> · −<span x-text="$store.pos.tenderDelta().toLocaleString()"></span> short</span>
                                <span x-show="$store.pos.tenderDelta() < 0"> · +<span x-text="Math.abs($store.pos.tenderDelta()).toLocaleString()"></span> over</span>
                            </span>
                        </template>
                    </span>
                </div>

                {{-- Booking lookup needed for room or wallet tender (need customer). --}}
                <div x-show="$store.pos.isOnline && $store.pos.tenders.some(t => t.method === 'room' || t.method === 'wallet')">
                    @include('livewire.dashboard.pos._booking-lookup', [
                        'bookingRef' => $bookingRef,
                        'bookingIdResolved' => $bookingIdResolved,
                        'bookingLookupError' => $bookingLookupError,
                        'inHouseBookings' => $inHouseBookings,
                    ])
                    <p x-show="$store.pos.bookingId && Number($store.pos.walletBalance) < $store.pos.walletTenderTotal()"
                       x-cloak
                       data-testid="pay-tender-wallet-warning"
                       style="margin-top: 8px; padding: 8px 10px; background: rgba(184, 92, 66, 0.10); color: #7a3d2b; border: 1px solid rgba(184, 92, 66, 0.30); border-radius: 6px; font-size: 12.5px;">
                        Wallet balance &#8358;<span x-text="Number($store.pos.walletBalance).toLocaleString()"></span>
                        is less than the wallet tender total
                        &#8358;<span x-text="$store.pos.walletTenderTotal().toLocaleString()"></span>.
                    </p>
                </div>
            </div>
        </template>

        {{-- ── Customer section ────────────────────────────────
             Phone-based CRM lookup so the sale attaches to a real
             person even when it's a walk-in. Empty state shows a
             search field; on match, the resolved name pins with a
             "clear" affordance; on miss, an inline "save as new
             customer" mini-form spins up. Works alongside any
             tender + alongside a picked booking. --}}
        <div class="mt-3" data-testid="pay-customer">
            <label class="lp-form-label" style="display: flex; justify-content: space-between; align-items: baseline;">
                <span>Customer <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">· optional</span></span>
                <button type="button"
                        x-show="$store.pos.customerId"
                        @click="$store.pos.clearCustomer()"
                        x-cloak
                        data-testid="pay-customer-clear"
                        style="background: none; border: 0; color: var(--color-accent-deep); font-size: 11px; cursor: pointer; text-decoration: underline;">
                    Clear
                </button>
            </label>
            {{-- Resolved chip. --}}
            <div x-show="$store.pos.customerId"
                 x-cloak
                 data-testid="pay-customer-resolved"
                 style="padding: 10px 12px; border-radius: 8px; background: rgba(93, 132, 44, 0.08); border: 1px solid rgba(93, 132, 44, 0.28); display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <span>
                    <strong style="color: var(--color-ink);" x-text="$store.pos.customerName"></strong>
                    <span class="lp-mono" style="color: var(--color-ink-mute); font-size: 11.5px; margin-left: 6px;"
                          x-text="$store.pos.customerPhone"></span>
                </span>
                <span class="lp-mono" style="color: var(--color-accent-deep); font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; font-weight: 600;">
                    ✓ In CRM
                </span>
            </div>
            {{-- Lookup / create UI. --}}
            <div x-show="!$store.pos.customerId" x-cloak style="display: grid; gap: 8px;">
                <div style="display: flex; gap: 6px;">
                    <input type="tel"
                           x-model="$store.pos.customerPhone"
                           @keydown.enter.prevent="$store.pos.lookupCustomer()"
                           data-testid="pay-customer-phone"
                           class="lp-input lp-mono"
                           placeholder="Phone number, e.g. 08012345678"
                           style="flex: 1;">
                    <button type="button"
                            @click="$store.pos.lookupCustomer()"
                            :disabled="$store.pos.customerLookupLoading"
                            data-testid="pay-customer-lookup"
                            class="lp-btn">
                        <span x-show="!$store.pos.customerLookupLoading">Look up</span>
                        <span x-show="$store.pos.customerLookupLoading" x-cloak>Looking…</span>
                    </button>
                </div>
                {{-- Inline profile form appears when no match. --}}
                <div x-show="$store.pos.customerNewOpen" x-cloak
                     data-testid="pay-customer-new"
                     style="padding: 10px 12px; border-radius: 8px; background: var(--color-card-deep); display: grid; gap: 8px;">
                    <p style="font-size: 12px; color: var(--color-ink-mute); margin: 0;">
                        No match on that phone. Save this as a new customer?
                    </p>
                    <div style="display: flex; gap: 6px;">
                        <input type="text"
                               x-model="$store.pos.customerName"
                               data-testid="pay-customer-name"
                               class="lp-input"
                               placeholder="Customer name"
                               style="flex: 1;">
                        <button type="button"
                                @click="$store.pos.saveNewCustomer()"
                                :disabled="$store.pos.customerLookupLoading"
                                data-testid="pay-customer-save"
                                class="lp-btn lp-btn--primary">
                            Save
                        </button>
                    </div>
                </div>
                <p x-show="$store.pos.customerLookupError" x-cloak
                   x-text="$store.pos.customerLookupError"
                   data-testid="pay-customer-error"
                   style="margin: 0; padding: 6px 10px; background: rgba(184, 92, 66, 0.10); color: #7a3d2b; border: 1px solid rgba(184, 92, 66, 0.30); border-radius: 6px; font-size: 12px;"></p>
            </div>
        </div>

        <div class="mt-3">
            <label for="pay-notes" class="lp-form-label">Note <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">· optional</span></label>
            <input id="pay-notes" type="text" x-model="$store.pos.notes" maxlength="240"
                   data-testid="pay-notes" class="lp-input" placeholder="Any reference, table #, receipt #, etc.">
        </div>

        {{-- Transient error / notice banners driven by the Alpine store. --}}
        <p x-show="$store.pos.error" x-cloak x-text="$store.pos.error"
           data-testid="pay-error"
           style="margin: 12px 0 0; padding: 8px 12px; background: rgba(184, 92, 66, 0.10); color: #7a3d2b; border: 1px solid rgba(184, 92, 66, 0.30); border-radius: 6px; font-size: 12.5px;"></p>
        <p x-show="$store.pos.notice" x-cloak x-text="$store.pos.notice"
           data-testid="pay-notice"
           style="margin: 12px 0 0; padding: 8px 12px; background: rgba(60, 120, 90, 0.10); color: #305a4b; border: 1px solid rgba(60, 120, 90, 0.30); border-radius: 6px; font-size: 12.5px;"></p>

        <div class="flex gap-2 mt-4">
            <button type="button" @click="$store.pos.cancelPayment()" class="lp-btn" data-testid="pay-cancel" style="flex: 0;">Cancel</button>
            <button type="button"
                    @click="$store.pos.complete()"
                    :disabled="!$store.pos.canComplete()"
                    class="lp-btn lp-btn--primary" data-testid="pay-complete" style="flex: 1;">
                <span x-show="!$store.pos.pendingComplete && !$store.pos.saleComp">Complete sale</span>
                <span x-show="!$store.pos.pendingComplete && $store.pos.saleComp" x-cloak>Comp sale</span>
                <span x-show="$store.pos.pendingComplete" x-cloak>Processing…</span>
            </button>
        </div>
    </div>
</div>

</div>
</template>

<style>
    /* Base sheet styling lives in CSS (not inline) so Alpine's x-show
      , which clears el.style.display when revealing, can't wipe
       display:flex and turn the sheet into a top-left block. */
    .lp-pay-modal-backdrop {
        position: fixed; inset: 0;
        background: rgba(20, 20, 30, 0.55);
        display: flex; align-items: flex-end; justify-content: center;
        z-index: 890; padding: 0;
    }
    .lp-pay-modal-panel {
        background: var(--color-card); width: 100%; max-width: 520px;
        border-radius: 16px 16px 0 0; padding: 20px;
        max-height: 92vh; overflow-y: auto;
        box-shadow: 0 -20px 60px rgba(0, 0, 0, 0.20);
    }
    /* Desktop: centered card instead of bottom-sheet. */
    @media (min-width: 640px) {
        .lp-pay-modal-backdrop { align-items: center; padding: 24px; }
        .lp-pay-modal-panel { border-radius: 16px; }
    }
</style>
