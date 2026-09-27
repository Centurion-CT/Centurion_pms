{{--
    Refunds detail panel — click a row on the Refunds page, get a
    right-side drawer with reservation summary + cancellation trail
    + quick actions. Payload is pre-hydrated in Index@buildPanels
    so opening is instant — no wire trip needed.
--}}
<div
    x-data="refundsDetailPanel({
        panels: {{ \Illuminate\Support\Js::from((object) $panels) }},
    })"
    x-on:refunds-panel-open.window="openId = $event.detail.id">

    <template x-teleport="body">
    <div x-show="openId !== null"
         x-cloak
         @keydown.escape.window="openId = null"
         data-testid="refunds-panel-backdrop"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 80;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);"
         @click.self="openId = null">
        <aside x-show="openId !== null"
               x-transition:enter="transition ease-out duration-160"
               x-transition:enter-start="opacity-0 translate-x-4"
               x-transition:enter-end="opacity-100 translate-x-0"
               data-testid="refunds-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(520px, 96vw);
                      background: var(--color-card);
                      box-shadow: -18px 0 40px -18px rgba(20, 17, 12, 0.28);
                      display: flex; flex-direction: column;">
            <template x-if="panel">
                <div style="display: flex; flex-direction: column; height: 100%;">
                    {{-- Head --}}
                    <div style="display: flex; align-items: flex-start; justify-content: space-between;
                                padding: 16px 20px 12px;
                                border-bottom: 1px solid var(--color-line-soft);">
                        <div>
                            <p class="lp-mono text-[10.5px]"
                               style="color: #A8650A; letter-spacing: 0.18em; text-transform: uppercase;">
                                <span x-text="(panel.status || '').replace('_', ' ')"></span>
                                · <span x-text="panel.ref"></span>
                            </p>
                            <h3 class="lp-display"
                                style="font-size: 18px; font-weight: 600; margin-top: 1px; line-height: 1.15;"
                                x-text="panel.guest_name || 'Guest'"></h3>
                            <p class="text-[12px]" style="color: var(--color-ink-soft); margin-top: 1px;"
                               x-text="panel.room + (panel.category ? ' · ' + panel.category : '')"></p>
                        </div>
                        <button type="button" @click="openId = null"
                                aria-label="Close"
                                style="background: transparent; border: 0; cursor: pointer;
                                       font-size: 22px; color: var(--color-ink-mute);
                                       padding: 2px 4px; line-height: 1;">×</button>
                    </div>

                    {{-- Body --}}
                    <div style="flex: 1 1 auto; overflow-y: auto; padding: 14px 20px 16px;
                                display: flex; flex-direction: column; gap: 14px; font-size: 12.5px;">

                        {{-- Reservation summary --}}
                        <dl style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
                                   column-gap: 12px; row-gap: 8px; margin: 0;">
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Check-in</dt>
                                <dd style="margin: 0;" x-text="panel.checkin"></dd>
                            </div>
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Check-out</dt>
                                <dd style="margin: 0;" x-text="panel.checkout"></dd>
                            </div>
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Nights</dt>
                                <dd style="margin: 0;" x-text="panel.nights"></dd>
                            </div>
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Contact</dt>
                                <dd style="margin: 0;" x-text="panel.guest_phone || '-'"></dd>
                            </div>
                            <div x-show="panel.guest_email" style="grid-column: span 2;">
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Email</dt>
                                <dd style="margin: 0; word-break: break-all;" x-text="panel.guest_email"></dd>
                            </div>
                        </dl>

                        {{-- Money card --}}
                        <div style="background: var(--color-card-deep); border-radius: 10px;
                                    padding: 10px 12px; display: flex; flex-direction: column; gap: 4px;">
                            <div class="flex justify-between">
                                <span style="color: var(--color-ink-soft);">Booking total</span>
                                <span class="lp-mono" x-text="money(panel.total)"></span>
                            </div>
                            <div class="flex justify-between">
                                <span style="color: var(--color-ink-soft);">Paid</span>
                                <span class="lp-mono" x-text="money(panel.paid)"></span>
                            </div>
                            <div class="flex justify-between" x-show="panel.refunded > 0">
                                <span style="color: var(--color-ink-soft);">Refunded</span>
                                <span class="lp-mono" x-text="money(panel.refunded)"></span>
                            </div>
                            <div class="flex justify-between"
                                 style="border-top: 1px solid var(--color-line-soft);
                                        padding-top: 6px; margin-top: 2px;
                                        font-weight: 600; color: var(--color-ink);">
                                <span>Held</span>
                                <span class="lp-mono"
                                      :style="panel.held > 0 ? 'color: #A8650A;' : ''"
                                      x-text="money(panel.held)"></span>
                            </div>
                        </div>

                        {{-- Cancellation trail --}}
                        <template x-if="panel.cancel">
                            <div style="border: 1px solid var(--color-line-soft); border-radius: 10px;
                                        padding: 10px 12px; display: flex; flex-direction: column; gap: 4px;">
                                <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0;">
                                    <span x-show="panel.cancel.kind === 'cancelled'">Cancelled</span>
                                    <span x-show="panel.cancel.kind === 'no_show'" x-cloak>Marked no-show</span>
                                </p>
                                <p style="font-size: 12.5px; color: var(--color-ink); margin: 0;">
                                    <strong x-text="panel.cancel.at"></strong>
                                    <span style="color: var(--color-ink-mute);">·</span>
                                    by <strong x-text="panel.cancel.by"></strong>
                                </p>
                                <p x-show="panel.cancel.note"
                                   style="font-size: 12px; color: var(--color-ink-soft); margin: 0; font-style: italic;"
                                   x-text="panel.cancel.note"></p>
                                <p x-show="panel.cancel.deposit_forfeit || panel.cancel.refund_requested"
                                   style="font-size: 11.5px; color: var(--color-ink-mute); margin-top: 2px;">
                                    <span x-show="panel.cancel.deposit_forfeit">Deposit forfeit policy applied.</span>
                                    <span x-show="panel.cancel.refund_requested">Refund was requested.</span>
                                </p>
                            </div>
                        </template>
                        <template x-if="!panel.cancel">
                            <p style="font-size: 12px; color: var(--color-ink-mute); margin: 0;">
                                No cancellation event on file (may pre-date the audit log).
                            </p>
                        </template>

                        {{-- Recent payments --}}
                        <div x-show="panel.payments && panel.payments.length > 0">
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 4px;">
                                Recent payments
                            </p>
                            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 3px;">
                                <template x-for="(p, i) in panel.payments" :key="i">
                                    <li class="flex justify-between"
                                        style="font-size: 12px; color: var(--color-ink-soft);">
                                        <span>
                                            <span x-text="p.paid_at"></span>
                                            <span style="color: var(--color-ink-mute);">·</span>
                                            <span x-text="p.method"></span>
                                            <span style="color: var(--color-ink-mute);">·</span>
                                            <span class="lp-mono"
                                                  style="font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase;
                                                         color: var(--color-ink-mute); font-weight: 600;"
                                                  x-text="p.status"></span>
                                        </span>
                                        <span class="lp-mono"
                                              :style="p.status === 'refunded' ? 'color: #B85C42;' : ''"
                                              x-text="(p.status === 'refunded' ? '−' : '') + money(p.amount)"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    {{-- Record-refund inline form. Toggled from the
                         foot; opens with the held amount pre-filled.
                         Submits via $wire.recordRefund and closes on ok. --}}
                    <template x-if="showRefundForm && panel.held > 0">
                        <div style="padding: 12px 20px;
                                    border-top: 1px solid var(--color-line-soft);
                                    background: var(--color-card-deep);">
                            <p class="lp-mono text-[10.5px]"
                               style="color: #A8650A; letter-spacing: 0.16em; text-transform: uppercase; margin-bottom: 6px;">
                                Record a refund
                            </p>
                            <div class="grid gap-2 sm:grid-cols-2" style="margin-bottom: 8px;">
                                <div style="position: relative;">
                                    <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
                                                 color: var(--color-ink-mute); font-size: 13px;">₦</span>
                                    <input type="number" min="1" step="1"
                                           x-model.number="refundAmount"
                                           :max="panel.held"
                                           data-testid="refunds-panel-amount"
                                           class="lp-input"
                                           style="padding-left: 22px;">
                                </div>
                                <select x-model="refundMethod"
                                        data-testid="refunds-panel-method"
                                        class="lp-input">
                                    <option value="cash">Cash</option>
                                    <option value="transfer">Bank transfer</option>
                                    <option value="card">Card / POS</option>
                                    <option value="paystack">Paystack</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <input type="text" x-model="refundNote"
                                   maxlength="240"
                                   placeholder="Reference / reason (optional)"
                                   data-testid="refunds-panel-note"
                                   class="lp-input">
                            <div class="flex items-center justify-between gap-2" style="margin-top: 8px;">
                                <span class="text-[11.5px]" style="color: var(--color-ink-mute);">
                                    Cap: <span class="lp-mono" x-text="money(panel.held)"></span>
                                </span>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="showRefundForm = false"
                                            class="lp-btn" style="font-size: 12px; padding: 5px 12px;">
                                        Cancel
                                    </button>
                                    <button type="button"
                                            @click="submitRefund()"
                                            :disabled="submitting || !refundAmount || refundAmount <= 0 || refundAmount > panel.held"
                                            :style="(submitting || !refundAmount || refundAmount <= 0 || refundAmount > panel.held) ? 'opacity: 0.4; cursor: not-allowed;' : ''"
                                            data-testid="refunds-panel-submit"
                                            style="background: #A8650A; color: #fff; border: 0;
                                                   padding: 6px 14px; border-radius: 999px;
                                                   font-family: inherit; font-size: 12px; font-weight: 600;
                                                   cursor: pointer;">
                                        <span x-show="!submitting">Record refund</span>
                                        <span x-show="submitting" x-cloak>Recording…</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Foot actions --}}
                    <div style="padding: 14px 20px;
                                border-top: 1px solid var(--color-line-soft);
                                display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button"
                                x-show="panel.held > 0 && !showRefundForm"
                                @click="showRefundForm = true; refundAmount = panel.held; refundNote = ''"
                                data-testid="refunds-panel-record"
                                style="background: #A8650A; color: #fff; border: 0;
                                       padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 600;
                                       cursor: pointer;">
                            Record refund
                        </button>
                        <a :href="panel.url" wire:navigate
                           @click="openId = null"
                           data-testid="refunds-panel-open"
                           style="background: transparent; color: var(--color-ink);
                                  border: 1px solid var(--color-line);
                                  padding: 6px 14px; border-radius: 999px;
                                  font-size: 12px; font-weight: 500;
                                  text-decoration: none;">
                            Open reservation
                        </a>
                        <button type="button" @click="openId = null"
                                data-testid="refunds-panel-close"
                                style="background: transparent; color: var(--color-ink);
                                       border: 1px solid var(--color-line);
                                       padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                       cursor: pointer; margin-left: auto;">
                            Close
                        </button>
                    </div>
                </div>
            </template>
        </aside>
    </div>
    </template>
</div>

<script>
    window.refundsDetailPanel = function ({ panels }) {
        return {
            openId: null,
            panels,
            // Refund-form state — Alpine keeps it inline in the panel
            // so the user doesn't need a second modal for a small
            // amount + method + note form. Submits through the wire
            // action which caps against the current held amount.
            showRefundForm: false,
            refundAmount: 0,
            refundMethod: 'cash',
            refundNote: '',
            submitting: false,
            get panel() { return this.openId !== null ? (this.panels[this.openId] ?? null) : null; },
            money(n) { return '₦' + Number(n || 0).toLocaleString(); },
            async submitRefund() {
                if (!this.panel || this.submitting) return;
                const amt = Number(this.refundAmount || 0);
                if (amt <= 0 || amt > (this.panel.held || 0)) return;
                this.submitting = true;
                try {
                    await this.$wire.recordRefund(
                        this.panel.id,
                        amt,
                        this.refundMethod,
                        this.refundNote || null,
                    );
                    // On success we close the drawer; the server-side
                    // notify + a livewire re-render will refresh the
                    // rows behind us.
                    this.showRefundForm = false;
                    this.openId = null;
                } finally {
                    this.submitting = false;
                }
            },
        };
    };
</script>
