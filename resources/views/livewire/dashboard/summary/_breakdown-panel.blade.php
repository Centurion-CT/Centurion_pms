{{--
    Summary: click a Revenue-breakdown row (Front office rooms /
    extras / any POS outlet) to see the underlying items in this
    window. Payload is pre-hydrated on render so opening is
    instant — no wire trip, no loader needed.
--}}
<div
    x-data="summaryBreakdownPanel({
        panels: {{ \Illuminate\Support\Js::from((object) $breakdownPanels) }},
    })"
    x-on:summary-breakdown-open.window="openId = $event.detail.id">

    <template x-teleport="body">
    <div x-show="openId !== null"
         x-cloak
         @keydown.escape.window="openId = null"
         data-testid="summary-breakdown-backdrop"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 80;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);"
         @click.self="openId = null">
        <aside x-show="openId !== null"
               x-transition:enter="transition ease-out duration-160"
               x-transition:enter-start="opacity-0 translate-x-4"
               x-transition:enter-end="opacity-100 translate-x-0"
               data-testid="summary-breakdown-panel"
               style="position: fixed; top: 0; right: 0; bottom: 0;
                      width: min(540px, 96vw);
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
                        <div :style="'display: grid; grid-template-columns: repeat(' + (panel.kpis?.length || 1) + ', minmax(0, 1fr)); gap: 8px;'">
                            <template x-for="k in (panel.kpis || [])" :key="k.label">
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

                        <div>
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 6px;">
                                Items in this window
                            </p>
                            <ul style="list-style: none; padding: 0; margin: 0;
                                       display: flex; flex-direction: column; gap: 0;">
                                <template x-for="r in (panel.rows || [])" :key="r.primary + (r.ref || '') + r.amount">
                                    <li style="display: grid;
                                               grid-template-columns: 1fr auto;
                                               gap: 4px 10px; padding: 8px 0;
                                               border-bottom: 1px solid var(--color-line-soft);">
                                        <div style="min-width: 0;">
                                            <template x-if="r.url">
                                                <a :href="r.url" wire:navigate
                                                   style="color: var(--color-ink); font-weight: 500; font-size: 13px;
                                                          text-decoration: none;"
                                                   x-text="r.primary"></a>
                                            </template>
                                            <template x-if="!r.url">
                                                <span style="color: var(--color-ink); font-weight: 500; font-size: 13px;"
                                                      x-text="r.primary"></span>
                                            </template>
                                            <span class="lp-mono text-[11px]" x-show="r.ref"
                                                  style="color: var(--color-ink-mute); margin-left: 6px;"
                                                  x-text="r.ref"></span>
                                        </div>
                                        <span class="lp-mono"
                                              style="font-size: 12.5px; color: var(--color-ink); font-weight: 600;
                                                     white-space: nowrap;"
                                              x-text="money(r.amount)"></span>
                                        <div style="font-size: 11.5px; color: var(--color-ink-soft); grid-column: 1;"
                                             x-text="r.meta"></div>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="!panel.rows || panel.rows.length === 0" x-cloak
                               style="font-size: 12px; color: var(--color-ink-mute); margin: 8px 0 0;">
                                No items in this window.
                            </p>
                        </div>
                    </div>

                    <div style="padding: 12px 20px;
                                border-top: 1px solid var(--color-line-soft);
                                display: flex; justify-content: flex-end;">
                        <button type="button" @click="openId = null"
                                data-testid="summary-breakdown-close"
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
    window.summaryBreakdownPanel = function ({ panels }) {
        return {
            openId: null,
            panels,
            get panel() { return this.openId !== null ? (this.panels[this.openId] ?? null) : null; },
            money(n) { return '₦' + Number(n || 0).toLocaleString(); },
        };
    };
</script>
