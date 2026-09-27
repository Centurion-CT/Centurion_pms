{{--
    Client-side detail panel for an arriving reservation. Rendered
    once at the index page level; both the "Arriving today" and
    "Arriving tomorrow" tables dispatch `arrival-panel-open` with a
    booking id, and the panel reads from the pre-hydrated payload
    (built in Index@buildArrivalsPanelData) so opening is instant.

    Actions are the ones an operator reaches for from the arrivals
    view: check the guest in, jump to the full reservation, or
    handle a no-show / cancellation. The Check-in button only
    surfaces when the booking is eligible today (Confirmed/OnHold,
    room assigned) — the eligibility flag is pre-computed onto the
    payload as `can_check_in`.
--}}
<div
    x-data="arrivalGuestPanel({
        panels: {{ \Illuminate\Support\Js::from(array_values($arrivalsPanelData)) }},
    })"
    x-on:arrival-panel-open.window="openId = $event.detail.id">

    <template x-teleport="body">
    <div x-show="openId !== null"
         x-cloak
         @keydown.escape.window="openId = null"
         data-testid="arrivals-panel-backdrop"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 70;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);"
         @click.self="openId = null">
        <aside x-show="openId !== null"
               x-transition:enter="transition ease-out duration-160"
               x-transition:enter-start="opacity-0 translate-x-4"
               x-transition:enter-end="opacity-100 translate-x-0"
               data-testid="arrivals-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(440px, 96vw);
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
                               style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                                <span x-text="panel.status_label"></span>
                                · <span x-text="panel.ref"></span>
                            </p>
                            <h3 class="lp-display"
                                style="font-size: 17px; font-weight: 600; margin-top: 1px; line-height: 1.15;"
                                x-text="panel.guest_name"></h3>
                            <p class="text-[12px]" style="color: var(--color-ink-soft); margin-top: 1px;"
                               x-text="'Rm ' + panel.room + (panel.category ? ' · ' + panel.category : '')"></p>
                            <p class="text-[11.5px]" x-show="panel.corporate"
                               style="color: var(--color-ink-mute); margin-top: 1px;"
                               x-text="panel.corporate"></p>
                        </div>
                        <button type="button" @click="openId = null"
                                aria-label="Close"
                                style="background: transparent; border: 0; cursor: pointer;
                                       font-size: 22px; color: var(--color-ink-mute);
                                       padding: 2px 4px; line-height: 1;">×</button>
                    </div>

                    {{-- Body --}}
                    <div style="flex: 1 1 auto; overflow-y: auto; padding: 14px 20px 16px;
                                display: flex; flex-direction: column; gap: 12px; font-size: 12.5px;">

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
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Guest phone</dt>
                                <dd style="margin: 0;" x-text="panel.guest_phone || '-'"></dd>
                            </div>
                            <div x-show="panel.guest_email" style="grid-column: span 2;">
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Guest email</dt>
                                <dd style="margin: 0; word-break: break-all;" x-text="panel.guest_email"></dd>
                            </div>
                        </dl>

                        {{-- Payment summary --}}
                        <div style="background: var(--color-card-deep); border-radius: 10px;
                                    padding: 10px 12px; display: flex; flex-direction: column; gap: 4px;">
                            <div class="flex justify-between">
                                <span style="color: var(--color-ink-soft);">Total</span>
                                <span class="lp-mono" x-text="money(panel.total)"></span>
                            </div>
                            <div class="flex justify-between" x-show="panel.deposit > 0">
                                <span style="color: var(--color-ink-soft);">Deposit paid</span>
                                <span class="lp-mono" x-text="money(panel.deposit)"></span>
                            </div>
                            <div class="flex justify-between"
                                 style="border-top: 1px solid var(--color-line-soft);
                                        padding-top: 6px; margin-top: 2px;
                                        font-weight: 600; color: var(--color-ink);">
                                <span>Outstanding</span>
                                <span class="lp-mono"
                                      :style="panel.outstanding > 0 ? 'color: #a36318;' : ''"
                                      x-text="money(panel.outstanding)"></span>
                            </div>
                        </div>

                        {{-- Special requests --}}
                        <div x-show="panel.special_requests">
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 4px;">Special requests</p>
                            <p style="font-size: 12.5px; color: var(--color-ink-soft); font-style: italic; white-space: pre-wrap; margin: 0;"
                               x-text="panel.special_requests"></p>
                        </div>

                        {{-- Recent payments --}}
                        <div x-show="panel.payments.length > 0">
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 4px;">Recent payments</p>
                            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 3px;">
                                <template x-for="(p, i) in panel.payments" :key="i">
                                    <li class="flex justify-between"
                                        style="font-size: 12px; color: var(--color-ink-soft);">
                                        <span>
                                            <span x-text="p.paid_at"></span>
                                            <span style="color: var(--color-ink-mute);">·</span>
                                            <span x-text="p.method"></span>
                                        </span>
                                        <span class="lp-mono" x-text="money(p.amount)"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <p x-show="panel.payments.length === 0" x-cloak
                           style="font-size: 12px; color: var(--color-ink-mute); margin: 0;">
                            No payments recorded yet.
                        </p>
                    </div>

                    {{-- Foot actions --}}
                    <div style="padding: 14px 20px;
                                border-top: 1px solid var(--color-line-soft);
                                display: flex; gap: 8px; flex-wrap: wrap;">
                        {{-- Check-in — primary action when the booking is
                             eligible today. Confirm dialog swaps in an
                             outstanding-balance warning so the operator
                             notices unsettled money before committing. --}}
                        <button x-show="panel.can_check_in"
                                type="button"
                                @click="window.kevernaConfirm({
                                    title: 'Check in ' + panel.guest_name + '?',
                                    message: panel.outstanding > 0
                                        ? 'Outstanding ' + money(panel.outstanding) + ' is unsettled. Check in anyway?'
                                        : 'Marks ' + panel.ref + ' in-house and takes Rm ' + panel.room + ' out of inventory.',
                                    confirmLabel: 'Check in'
                                }).then((ok) => { if (ok) { $wire.checkInFromPanel(panel.ref); openId = null; } })"
                                data-testid="arrivals-panel-check-in"
                                style="background: var(--color-ink); color: var(--color-card);
                                       border: 0; padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 600;
                                       cursor: pointer;">
                            Check in
                        </button>
                        <a :href="panel.url" wire:navigate
                           @click="openId = null"
                           data-testid="arrivals-panel-open-reservation"
                           :style="panel.can_check_in
                               ? 'background: transparent; color: var(--color-ink); border: 1px solid var(--color-line); padding: 6px 14px; border-radius: 999px; font-size: 12px; font-weight: 500; text-decoration: none;'
                               : 'background: var(--color-ink); color: var(--color-card); border: 0; padding: 6px 14px; border-radius: 999px; font-size: 12px; font-weight: 600; text-decoration: none;'">
                            Open reservation
                        </a>
                        <button type="button"
                                @click="window.kevernaConfirm({ message: 'Mark ' + panel.ref + ' as no-show? Deposit will be forfeited (if any).', tone: 'danger' }).then((ok) => { if (ok) { $wire.markNoShow(panel.ref); openId = null; } })"
                                data-testid="arrivals-panel-noshow"
                                style="background: transparent; color: #B85C42;
                                       border: 1px solid #B85C42;
                                       padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                       cursor: pointer;">
                            No-show
                        </button>
                        <button type="button"
                                @click="window.kevernaConfirm({ message: 'Cancel reservation ' + panel.ref + '? This releases the room.', tone: 'danger' }).then((ok) => { if (ok) { $wire.cancelReservation(panel.ref); openId = null; } })"
                                data-testid="arrivals-panel-cancel"
                                style="background: transparent; color: #B85C42;
                                       border: 1px solid #B85C42;
                                       padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                       cursor: pointer; margin-left: auto;">
                            Cancel
                        </button>
                    </div>
                </div>
            </template>
        </aside>
    </div>
    </template>
</div>

<script>
    window.arrivalGuestPanel = function ({ panels }) {
        return {
            openId: null,
            panels,
            get panel() { return this.panels.find(p => p.id === this.openId) ?? null; },
            money(n) { return '₦' + Number(n || 0).toLocaleString(); },
        };
    };
</script>
