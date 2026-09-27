{{--
    Shared "click a Finance row to see what makes it up" panel.
    Rendered once at the Finance page level; tables (channel,
    category, top rooms) turn their rows into buttons that
    dispatch `finance-breakdown-open` with an id like
    `channel:walk_in`, `category:5`, or `room:211`. The Alpine
    factory reads from a pre-hydrated payload map (built in
    Finance\Index::buildBreakdowns) so opening is instant.

    Same right-side drawer shape as the arrivals + in-house panels
    on the reservations page so the interaction feels consistent
    across the operator app.
--}}
<div
    x-data="financeBreakdownPanel({
        panels: {{ \Illuminate\Support\Js::from((object) $breakdowns) }},
    })"
    x-on:finance-breakdown-open.window="openId = $event.detail.id">

    <template x-teleport="body">
    <div x-show="openId !== null"
         x-cloak
         @keydown.escape.window="openId = null"
         data-testid="finance-breakdown-backdrop"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 80;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);"
         @click.self="openId = null">
        <aside x-show="openId !== null"
               x-transition:enter="transition ease-out duration-160"
               x-transition:enter-start="opacity-0 translate-x-4"
               x-transition:enter-end="opacity-100 translate-x-0"
               data-testid="finance-breakdown-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(520px, 96vw);
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
                               style="color: var(--color-ink-mute); letter-spacing: 0.18em; text-transform: uppercase;"
                               x-text="panel.subtitle"></p>
                            <h3 class="lp-display"
                                style="font-size: 18px; font-weight: 600; margin-top: 1px; line-height: 1.15;"
                                x-text="panel.title"></h3>
                        </div>
                        <button type="button" @click="openId = null"
                                aria-label="Close"
                                style="background: transparent; border: 0; cursor: pointer;
                                       font-size: 22px; color: var(--color-ink-mute);
                                       padding: 2px 4px; line-height: 1;">×</button>
                    </div>

                    <div style="flex: 1 1 auto; overflow-y: auto; padding: 14px 20px 16px;
                                display: flex; flex-direction: column; gap: 14px; font-size: 12.5px;">

                        {{-- KPI mini-strip --}}
                        <div style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px;">
                            <template x-for="k in panel.kpis" :key="k.label">
                                <div style="background: var(--color-card-deep); border-radius: 10px;
                                            padding: 10px 12px; display: flex; flex-direction: column; gap: 2px;">
                                    <span style="font-size: 10.5px; letter-spacing: 0.12em;
                                                 text-transform: uppercase; color: var(--color-ink-mute);"
                                          x-text="k.label"></span>
                                    <span class="lp-num" style="font-size: 16px; color: var(--color-ink); font-weight: 600;"
                                          x-text="k.value"></span>
                                </div>
                            </template>
                        </div>

                        {{-- Body switches on panel.kind so one panel
                             serves grouping rows (bookings list),
                             cash-flow rows (daily table), and ledger
                             rows (metadata card). --}}

                        {{-- Default: bookings list (channel / category / room) --}}
                        <template x-if="!panel.kind || panel.kind === 'bookings'">
                            <div>
                                <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                                    Bookings in this window
                                </p>
                                <ul style="list-style: none; padding: 0; margin: 0;
                                           display: flex; flex-direction: column; gap: 0;">
                                    <template x-for="b in (panel.bookings || [])" :key="b.ref">
                                        <li style="display: grid;
                                                   grid-template-columns: 1fr auto;
                                                   gap: 4px 10px; padding: 8px 0;
                                                   border-bottom: 1px solid var(--color-line-soft);">
                                            <div style="min-width: 0;">
                                                <a :href="b.url" wire:navigate
                                                   style="color: var(--color-ink); font-weight: 500; font-size: 13px;
                                                          text-decoration: none;"
                                                   x-text="b.guest"></a>
                                                <span class="lp-mono text-[11px]"
                                                      style="color: var(--color-ink-mute); margin-left: 6px;"
                                                      x-text="b.ref"></span>
                                            </div>
                                            <span class="lp-mono"
                                                  style="font-size: 12.5px; color: var(--color-ink); font-weight: 600;
                                                         white-space: nowrap;"
                                                  x-text="money(b.amount)"></span>
                                            <div style="font-size: 11.5px; color: var(--color-ink-soft); grid-column: 1;">
                                                <span x-text="b.room"></span>
                                                <span style="color: var(--color-ink-mute);">·</span>
                                                <span x-text="b.checkin + ' → ' + b.checkout"></span>
                                                <span style="color: var(--color-ink-mute);">·</span>
                                                <span x-text="b.nights + ' night' + (b.nights === 1 ? '' : 's')"></span>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                                <p x-show="!panel.bookings || panel.bookings.length === 0" x-cloak
                                   style="font-size: 12px; color: var(--color-ink-mute); margin: 8px 0 0;">
                                    No bookings in this window.
                                </p>
                            </div>
                        </template>

                        {{-- Cashflow: daily table --}}
                        <template x-if="panel.kind === 'daily'">
                            <div>
                                <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                                    Daily activity
                                </p>
                                <table class="lp-table" data-testid="finance-breakdown-daily"
                                       style="font-size: 12px;">
                                    <thead>
                                        <tr>
                                            <th>Day</th>
                                            <th style="text-align: right;">Billed</th>
                                            <th style="text-align: right;">Collected</th>
                                            <th style="text-align: right;">Refunded</th>
                                            <th style="text-align: right;">Net</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="d in (panel.daily || [])" :key="d.date">
                                            <tr>
                                                <td style="color: var(--color-ink); font-weight: 500;" x-text="d.date"></td>
                                                <td class="lp-mono" style="text-align: right;" x-text="money(d.billed)"></td>
                                                <td class="lp-mono" style="text-align: right;" x-text="money(d.collected)"></td>
                                                <td class="lp-mono" style="text-align: right;"
                                                    :style="d.refunded > 0 ? 'color: #B85C42;' : 'color: var(--color-ink-mute);'"
                                                    x-text="money(d.refunded)"></td>
                                                <td class="lp-mono" style="text-align: right; font-weight: 600;"
                                                    x-text="money(d.net)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        {{-- Ledger: single-transaction metadata card --}}
                        <template x-if="panel.kind === 'ledger_detail'">
                            <div>
                                <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                                    Transaction details
                                </p>
                                <dl style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
                                           column-gap: 12px; row-gap: 8px; margin: 0;"
                                    data-testid="finance-breakdown-ledger">
                                    <template x-for="d in (panel.details || [])" :key="d.label">
                                        <div>
                                            <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;"
                                                x-text="d.label"></dt>
                                            <dd style="margin: 0; word-break: break-all;" x-text="d.value"></dd>
                                        </div>
                                    </template>
                                </dl>
                            </div>
                        </template>
                    </div>

                    <div style="padding: 12px 20px;
                                border-top: 1px solid var(--color-line-soft);
                                display: flex; justify-content: flex-end; gap: 8px;">
                        <template x-if="panel.open_url">
                            <a :href="panel.open_url" wire:navigate
                               @click="openId = null"
                               data-testid="finance-breakdown-open"
                               style="background: var(--color-ink); color: var(--color-card);
                                      border: 0; padding: 6px 14px; border-radius: 999px;
                                      font-family: inherit; font-size: 12px; font-weight: 600;
                                      text-decoration: none;"
                               x-text="panel.open_label || 'Open'"></a>
                        </template>
                        <button type="button" @click="openId = null"
                                data-testid="finance-breakdown-close"
                                style="background: transparent; color: var(--color-ink);
                                       border: 1px solid var(--color-line);
                                       padding: 6px 14px; border-radius: 999px;
                                       font-family: inherit; font-size: 12px; font-weight: 500;
                                       cursor: pointer;">
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
    window.financeBreakdownPanel = function ({ panels }) {
        return {
            // Panels are an object keyed by id ("channel:walk_in", etc.)
            // so lookup is O(1); using an array + find would work too
            // but the string id is more natural for these categorical
            // rows than a numeric one.
            openId: null,
            panels,
            get panel() { return this.openId !== null ? (this.panels[this.openId] ?? null) : null; },
            money(n) { return '₦' + Number(n || 0).toLocaleString(); },
        };
    };
</script>
