{{--
    Client-side detail panel for an in-house guest picked from the
    "In-house right now" sheet. Rendered once at the index page
    level; rows in the sheet dispatch `inhouse-sheet-panel-open`
    with a booking id, and this factory reads from the pre-hydrated
    payload so opening is instant.

    Deliberately does NOT touch the in-house sheet's own `open`
    state — the sheet stays behind the panel so the operator can
    close the panel and pick another guest without re-navigating.
    Z-index sits above the sheet's .lp-sheet-backdrop (900) so the
    panel visually stacks on top.
--}}
<div
    x-data="inHouseSheetPanel({
        panels: {{ \Illuminate\Support\Js::from(array_values($inHouseSheetData['panels'])) }},
    })"
    x-on:inhouse-sheet-panel-open.window="openId = $event.detail.id">

    <template x-teleport="body">
    <div x-show="openId !== null"
         x-cloak
         data-testid="inhouse-sheet-panel-backdrop"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 950;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);"
         @click.self="openId = null">
        <aside x-show="openId !== null"
               x-transition:enter="transition ease-out duration-160"
               x-transition:enter-start="opacity-0 translate-x-4"
               x-transition:enter-end="opacity-100 translate-x-0"
               data-testid="inhouse-sheet-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(440px, 96vw);
                      background: var(--color-card);
                      box-shadow: -18px 0 40px -18px rgba(20, 17, 12, 0.28);
                      display: flex; flex-direction: column;">
            <template x-if="panel">
                <div style="display: flex; flex-direction: column; height: 100%;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between;
                                padding: 16px 20px 12px;
                                border-bottom: 1px solid var(--color-line-soft);">
                        <div>
                            <p class="lp-mono text-[10.5px]"
                               style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;">
                                <span x-show="!panel.is_overdue">In-house</span>
                                <span x-show="panel.is_overdue" x-cloak style="color: #B85C42;">Overdue</span>
                                · <span x-text="panel.ref"></span>
                            </p>
                            <h3 class="lp-display"
                                style="font-size: 17px; font-weight: 600; margin-top: 1px; line-height: 1.15;"
                                x-text="panel.guest_name"></h3>
                            <p class="text-[12px]" style="color: var(--color-ink-soft); margin-top: 1px;"
                               x-text="'Rm ' + panel.room + (panel.category ? ' · ' + panel.category : '')"></p>
                        </div>
                        <button type="button" @click="openId = null"
                                aria-label="Close"
                                style="background: transparent; border: 0; cursor: pointer;
                                       font-size: 22px; color: var(--color-ink-mute);
                                       padding: 2px 4px; line-height: 1;">×</button>
                    </div>

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
                                <dd style="margin: 0;"
                                    :style="panel.is_overdue ? 'color: #B85C42; font-weight: 600;' : ''"
                                    x-text="panel.checkout"></dd>
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

                        <div style="background: var(--color-card-deep); border-radius: 10px;
                                    padding: 10px 12px; display: flex; flex-direction: column; gap: 4px;">
                            <div class="flex justify-between">
                                <span style="color: var(--color-ink-soft);">Total</span>
                                <span class="lp-mono" x-text="money(panel.total)"></span>
                            </div>
                            <div class="flex justify-between" x-show="panel.deposit > 0">
                                <span style="color: var(--color-ink-soft);">Deposit</span>
                                <span class="lp-mono" x-text="money(panel.deposit)"></span>
                            </div>
                            <div class="flex justify-between" x-show="panel.balance > 0">
                                <span style="color: var(--color-ink-soft);">Booked balance</span>
                                <span class="lp-mono" x-text="money(panel.balance)"></span>
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

                    <div style="padding: 14px 20px;
                                border-top: 1px solid var(--color-line-soft);
                                display: flex; gap: 8px; flex-wrap: wrap;">
                        <a :href="panel.url" wire:navigate
                           @click="openId = null"
                           data-testid="inhouse-sheet-panel-open"
                           style="background: var(--color-ink); color: var(--color-card);
                                  border: 0; padding: 6px 14px; border-radius: 999px;
                                  font-size: 12px; font-weight: 600;
                                  text-decoration: none;">
                            Open reservation
                        </a>
                        <button type="button" @click="openId = null"
                                data-testid="inhouse-sheet-panel-back"
                                style="background: transparent; color: var(--color-ink);
                                       border: 1px solid var(--color-line);
                                       padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                       cursor: pointer; margin-left: auto;">
                            Back to list
                        </button>
                    </div>
                </div>
            </template>
        </aside>
    </div>
    </template>
</div>

<script>
    window.inHouseSheetPanel = function ({ panels }) {
        return {
            openId: null,
            panels,
            get panel() { return this.panels.find(p => p.id === this.openId) ?? null; },
            money(n) { return '₦' + Number(n || 0).toLocaleString(); },
        };
    };
</script>
