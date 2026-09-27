{{--
    In-house detail panel - click a row on the In-house page, get a
    right-side drawer with the reservation summary + stay progress
    + payment breakdown + primary CTA to open the reservation for
    check-out / extend / settle. Payload is pre-hydrated in
    InHouse\Index::render, so opening is instant - no wire trip,
    no loader needed.
--}}
<div
    x-data="inHousePagePanel({
        panels: {{ \Illuminate\Support\Js::from((object) $panels) }},
    })"
    x-on:in-house-panel-open.window="openId = $event.detail.id">

    <template x-teleport="body">
    <div x-show="openId !== null"
         x-cloak
         @keydown.escape.window="openId = null"
         data-testid="in-house-panel-backdrop"
         class="lp-page"
         style="position: fixed; inset: 0; z-index: 80;
                background: rgba(20, 22, 24, 0.42);
                backdrop-filter: blur(2px);"
         @click.self="openId = null">
        <aside x-show="openId !== null"
               x-transition:enter="transition ease-out duration-160"
               x-transition:enter-start="opacity-0 translate-x-4"
               x-transition:enter-end="opacity-100 translate-x-0"
               data-testid="in-house-panel"
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
                                <span x-show="!panel.is_overdue && !panel.is_same_day_late">In-house</span>
                                <span x-show="panel.is_overdue" x-cloak style="color: #B85C42;">Overdue</span>
                                <span x-show="!panel.is_overdue && panel.is_same_day_late" x-cloak
                                      style="color: #B85C42;">Late check-out</span>
                                · <span x-text="panel.ref"></span>
                            </p>
                            <h3 class="lp-display"
                                style="font-size: 17px; font-weight: 600; margin-top: 1px; line-height: 1.15;"
                                x-text="panel.guest_name"></h3>
                            <p class="text-[12px]" style="color: var(--color-ink-soft); margin-top: 1px;"
                               x-text="panel.room + (panel.category ? ' · ' + panel.category : '')"></p>
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

                        {{-- Meta pairs --}}
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
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Nights so far</dt>
                                <dd style="margin: 0;" x-text="panel.nights_so_far"></dd>
                            </div>
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Nights left</dt>
                                <dd style="margin: 0;"
                                    :style="panel.nights_left !== null && panel.nights_left < 0 ? 'color: #B85C42; font-weight: 600;' : ''"
                                    x-text="panel.nights_left !== null ? panel.nights_left : '-'"></dd>
                            </div>
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Daily rate</dt>
                                <dd style="margin: 0;" x-text="money(panel.daily_rate)"></dd>
                            </div>
                            <div>
                                <dt style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin-bottom: 2px;">Phone</dt>
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
                            {{-- Same-day late guest accruing an hourly fee -
                                 shown as a projection so the desk sees it
                                 building up before check-out is run. The
                                 actual charge lands at MarkCheckedOut. --}}
                            <div x-show="panel.is_same_day_late" x-cloak class="flex justify-between"
                                 style="margin-top: 4px; font-size: 11.5px;
                                        color: #B85C42; font-weight: 500;"
                                 :title="panel.late_description"
                                 data-testid="in-house-panel-late">
                                <span>Late fee accruing</span>
                                <span class="lp-mono" x-text="'+ ' + money(panel.late_fee_projected)"></span>
                            </div>
                        </div>

                        {{-- Open folio charges - extension, F&B, damages
                             etc. Explains WHY Outstanding is what it is,
                             so the operator doesn't stare at an aggregate
                             number without a breakdown. --}}
                        <div x-show="panel.charges && panel.charges.length > 0"
                             data-testid="in-house-panel-charges">
                            <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--color-ink-mute); margin: 0 0 4px;">Open charges</p>
                            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 3px;">
                                <template x-for="(c, i) in panel.charges" :key="i">
                                    <li class="flex justify-between"
                                        style="font-size: 12px; color: var(--color-ink-soft); gap: 12px;">
                                        <span style="min-width: 0; flex: 1;">
                                            <span x-text="c.description"
                                                  style="display: inline-block; overflow: hidden;
                                                         text-overflow: ellipsis; white-space: nowrap;
                                                         max-width: 100%;"></span>
                                            <br>
                                            <span style="color: var(--color-ink-mute); font-size: 11px;"
                                                  x-text="c.added_at"></span>
                                        </span>
                                        <span class="lp-mono"
                                              style="color: #a36318; white-space: nowrap;"
                                              x-text="money(c.amount)"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        {{-- Recent payments --}}
                        <div x-show="panel.payments && panel.payments.length > 0">
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
                                        <span class="lp-mono"
                                              :style="p.status === 'refunded' ? 'color: #B85C42;' : ''"
                                              x-text="(p.status === 'refunded' ? '−' : '') + money(p.amount)"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <p x-show="!panel.payments || panel.payments.length === 0" x-cloak
                           style="font-size: 12px; color: var(--color-ink-mute); margin: 0;">
                            No payments recorded yet.
                        </p>
                    </div>

                    {{-- Foot actions - organised as a small grid so
                         the front-desk operator sees every path the
                         panel offers at a glance. Each pill deep-links
                         into the reservation page and lands on the
                         relevant surface (extend modal, charges form,
                         reassign panel, settle block, checkout button)
                         so no follow-up scrolling / clicking is needed. --}}
                    <div style="padding: 14px 20px;
                                border-top: 1px solid var(--color-line-soft);
                                display: flex; flex-direction: column; gap: 12px;">
                        <p style="font-size: 10.5px; letter-spacing: 0.14em; text-transform: uppercase;
                                  color: var(--color-ink-mute); margin: 0;">
                            Front-desk actions
                        </p>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px;">
                            {{-- All three inline actions now stay on
                                 the in-house page rather than navigating
                                 to /reservations/{ref}. Extend opens the
                                 shared modal (z-index 200 so it sits
                                 above the sheet); Change room + Add
                                 charge open inline Livewire panels. --}}
                            <button type="button"
                                    @click="$wire.openAddCharge(panel.id); openId = null;"
                                    data-testid="in-house-panel-charge"
                                    style="background: var(--color-card-deep); color: var(--color-ink);
                                           border: 1px solid transparent; padding: 8px 10px;
                                           border-radius: 8px; text-align: center;
                                           font-size: 12px; font-weight: 500;
                                           cursor: pointer; font-family: inherit;
                                           display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"
                                     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M8 3v10M3 8h10"/>
                                </svg>
                                Add charge
                            </button>
                            <button type="button"
                                    @click="$wire.openExtendModal(panel.id); openId = null;"
                                    data-testid="in-house-panel-extend"
                                    style="background: var(--color-card-deep); color: var(--color-ink);
                                           border: 1px solid transparent; padding: 8px 10px;
                                           border-radius: 8px; text-align: center;
                                           font-size: 12px; font-weight: 500;
                                           cursor: pointer; font-family: inherit;
                                           display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"
                                     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 5h10v9H3z"/><path d="M6 3v3M10 3v3"/>
                                </svg>
                                Extend stay
                            </button>
                            <button type="button"
                                    @click="$wire.openChangeRoom(panel.id); openId = null;"
                                    wire:loading.attr="disabled"
                                    wire:target="openChangeRoom"
                                    data-testid="in-house-panel-change-room"
                                    style="background: var(--color-card-deep); color: var(--color-ink);
                                           border: 1px solid transparent; padding: 8px 10px;
                                           border-radius: 8px; text-align: center;
                                           font-size: 12px; font-weight: 500;
                                           cursor: pointer; font-family: inherit;
                                           display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                                <svg wire:loading.remove wire:target="openChangeRoom"
                                     width="12" height="12" viewBox="0 0 16 16" fill="none"
                                     stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 6h9l-2-2M13 10H4l2 2"/>
                                </svg>
                                <svg wire:loading wire:target="openChangeRoom"
                                     width="12" height="12" viewBox="0 0 16 16" fill="none"
                                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                     style="animation: kv-spin 720ms linear infinite;">
                                    <path d="M8 2a6 6 0 016 6"/>
                                </svg>
                                <span wire:loading.remove wire:target="openChangeRoom">Change room</span>
                                <span wire:loading wire:target="openChangeRoom">Loading rooms…</span>
                            </button>
                            {{-- Settle button is hidden for corp-
                                 guaranteed guests: their outstanding
                                 will bill to the corp by invoice, not
                                 be settled at the desk. Non-corp guests
                                 still see it (routes to the reservation
                                 settle panel + wallet / cash flow). --}}
                            <template x-if="!panel.is_corp_preauth">
                                <a :href="panel.url + '?settle=1'" wire:navigate
                                   @click="openId = null"
                                   data-testid="in-house-panel-settle"
                                   :style="'background: ' + (panel.outstanding > 0 ? '#F5E6D3' : 'var(--color-card-deep)') + ';'
                                         + 'color: ' + (panel.outstanding > 0 ? '#A8650A' : 'var(--color-ink)') + ';'
                                         + 'border: 1px solid transparent; padding: 8px 10px;'
                                         + 'border-radius: 8px; text-align: center;'
                                         + 'font-size: 12px; font-weight: ' + (panel.outstanding > 0 ? '600' : '500') + ';'
                                         + 'text-decoration: none;'
                                         + 'display: inline-flex; align-items: center; justify-content: center; gap: 6px;'">
                                    <svg width="12" height="12" viewBox="0 0 16 16" fill="none"
                                         stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 5h12v7H2z"/><path d="M2 8h12"/>
                                    </svg>
                                    <template x-if="panel.outstanding > 0">
                                        <span>Settle <span x-text="money(panel.outstanding)"></span></span>
                                    </template>
                                    <template x-if="panel.outstanding === 0">
                                        <span>Ledger</span>
                                    </template>
                                </a>
                            </template>
                        </div>

                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            {{-- Check out stays on this page — opens a
                                 confirmation modal (shared with the
                                 extend flow) instead of navigating to
                                 the reservation page. On success, we
                                 redirect to the reservation summary so
                                 the operator can issue an invoice /
                                 print the ledger. --}}
                            <button type="button"
                                    @click="$wire.openCheckout(panel.id); openId = null;"
                                    data-testid="in-house-panel-checkout"
                                    style="background: var(--color-ink); color: var(--color-card);
                                           border: 0; padding: 8px 14px; border-radius: 999px;
                                           font-size: 12px; font-weight: 600;
                                           cursor: pointer; font-family: inherit;
                                           display: inline-flex; align-items: center; gap: 6px;">
                                Check out
                            </button>
                            <a :href="panel.url" wire:navigate
                               @click="openId = null"
                               data-testid="in-house-panel-open"
                               style="background: transparent; color: var(--color-ink);
                                      border: 1px solid var(--color-line);
                                      padding: 8px 14px; border-radius: 999px;
                                      font-size: 12px; font-weight: 500;
                                      text-decoration: none;">
                                Open reservation →
                            </a>
                            <button type="button" @click="openId = null"
                                    data-testid="in-house-panel-close"
                                    style="background: transparent; color: var(--color-ink-mute);
                                           border: 0; padding: 8px 12px; border-radius: 999px;
                                           font-family: inherit; font-size: 12px; font-weight: 500;
                                           cursor: pointer; margin-left: auto;">
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </aside>
    </div>
    </template>

    {{-- ── Checkout confirmation modal ────────────────────────────
         Fires from the sheet's Check out button, keeps the flow on
         this page. For corp-guaranteed guests the modal notes that
         the outstanding will bill to the corp by invoice; for walk-
         ins with a balance we point at the settle-first path (the
         MarkCheckedOut action refuses otherwise). --}}
    @if ($checkoutOpen && $checkoutBookingId !== null)
        @php
            $checkoutBooking = \App\Models\Booking::query()
                ->with(['room:id,room_number', 'corporate:id,name'])
                ->find($checkoutBookingId);
            $checkoutOutstanding = $checkoutBooking?->outstandingTotal() ?? 0;
            $checkoutIsCorp = $checkoutBooking?->isCorporatePreAuthorized() ?? false;
            $checkoutCanFire = $checkoutOutstanding <= 0 || $checkoutIsCorp;
        @endphp
        <template x-teleport="body">
        <div data-testid="in-house-checkout-modal"
             role="dialog" aria-modal="true"
             wire:key="in-house-checkout-modal"
             class="lp-page"
             style="position: fixed; inset: 0; z-index: 200;
                    background: rgba(20, 17, 12, 0.42);
                    display: flex; align-items: center; justify-content: center;
                    padding: 24px;">
            <div wire:click="closeCheckout"
                 style="position: absolute; inset: 0;"></div>
            <div style="position: relative; width: min(480px, 100%);
                        background: var(--color-card); border-radius: 14px;
                        box-shadow: 0 32px 72px -32px rgba(20, 17, 12, 0.4);
                        padding: 22px 24px;">
                <div class="lp-mono text-[10.5px] tracking-[0.16em] uppercase"
                     style="color: var(--color-ink-mute);">
                    Check out
                </div>
                <h3 class="lp-display text-[20px] mt-1"
                    style="color: var(--color-ink); font-weight: 600;">
                    {{ $checkoutBooking?->guest_name ?? 'Guest' }}
                </h3>
                <p class="text-[13px] mt-1" style="color: var(--color-ink-soft);">
                    Rm {{ $checkoutBooking?->room?->room_number ?? '-' }}
                    @if ($checkoutBooking?->corporate?->name)
                        <span style="color: var(--color-ink-mute); margin: 0 4px;">·</span>
                        {{ $checkoutBooking->corporate->name }}
                    @endif
                </p>

                <div style="margin-top: 16px; padding: 12px 14px;
                            background: var(--color-card-deep); border-radius: 10px;
                            display: flex; justify-content: space-between; align-items: baseline;">
                    <span class="lp-mono text-[11px]"
                          style="color: var(--color-ink-mute); letter-spacing: 0.14em; text-transform: uppercase;">
                        Outstanding
                    </span>
                    <span class="lp-num" data-testid="checkout-outstanding"
                          style="color: {{ $checkoutOutstanding > 0 ? '#a36318' : 'var(--color-ink)' }};
                                 font-size: 18px; font-weight: 600;">
                        {{ money($checkoutOutstanding) }}
                    </span>
                </div>

                @if ($checkoutIsCorp && $checkoutOutstanding > 0)
                    <p class="text-[12.5px] mt-3"
                       data-testid="checkout-corp-hint"
                       style="padding: 10px 12px; border-radius: 8px;
                              background: rgba(122, 168, 116, 0.08);
                              border: 1px solid rgba(122, 168, 116, 0.35);
                              color: var(--color-ink);">
                        <strong>Corporate guaranteed.</strong>
                        The outstanding {{ money($checkoutOutstanding) }} will bill to
                        {{ $checkoutBooking?->corporate?->name ?? 'the corporate' }} on the next
                        invoice cycle. Checkout is safe to proceed.
                    </p>
                @elseif ($checkoutOutstanding > 0)
                    <p class="text-[12.5px] mt-3"
                       data-testid="checkout-outstanding-warning"
                       style="padding: 10px 12px; border-radius: 8px;
                              background: rgba(184, 92, 66, 0.08);
                              border: 1px solid rgba(184, 92, 66, 0.35);
                              color: var(--color-ink);">
                        <strong style="color: #B85C42;">Balance outstanding.</strong>
                        Settle {{ money($checkoutOutstanding) }} before check-out.
                        Use the Settle button on the sheet to take payment.
                    </p>
                @endif

                <div class="flex justify-end gap-2" style="margin-top: 18px;">
                    <button type="button" wire:click="closeCheckout"
                            data-testid="checkout-cancel"
                            class="lp-btn" style="font-size: 12.5px;">Cancel</button>
                    <button type="button" wire:click="submitCheckout"
                            data-testid="checkout-confirm"
                            @disabled(! $checkoutCanFire)
                            class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                        <span wire:loading.remove wire:target="submitCheckout">
                            @if ($checkoutIsCorp && $checkoutOutstanding > 0)
                                Confirm + bill corp
                            @else
                                Confirm checkout
                            @endif
                        </span>
                        <span wire:loading wire:target="submitCheckout">Checking out...</span>
                    </button>
                </div>
            </div>
        </div>
        </template>
    @endif
</div>

<script>
    window.inHousePagePanel = function ({ panels }) {
        return {
            openId: null,
            panels,
            get panel() { return this.openId !== null ? (this.panels[this.openId] ?? null) : null; },
            money(n) { return @js(currency_symbol()) + Number(n || 0).toLocaleString(); },
        };
    };
</script>
