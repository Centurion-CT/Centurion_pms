<div class="lp-dash lp-pos"
     data-testid="pos-terminal"
     x-data="{}"
     x-init="$store.pos.init(@js($outlet->id), @js((float) $outlet->tax_rate), @js($productsBoot))"
     @pos-booking-resolved.window="$store.pos.bookingRef = $event.detail.ref; $store.pos.bookingId = $event.detail.id; $store.pos.walletBalance = Number($event.detail.walletBalance || 0); $store.pos.creditLineId = null; $store.pos.creditLineName = ''"
     @pos-booking-cleared.window="$store.pos.bookingRef = ''; $store.pos.bookingId = null; $store.pos.walletBalance = 0"
     @pos-credit-line-resolved.window="$store.pos.creditLineId = $event.detail.id; $store.pos.creditLineName = $event.detail.name; $store.pos.bookingRef = ''; $store.pos.bookingId = null; $store.pos.walletBalance = 0"
     @pos-credit-line-cleared.window="$store.pos.creditLineId = null; $store.pos.creditLineName = ''">

    {{-- ── Offline banner + queue count ────────────────────────── --}}
    <div class="lp-pos__conn" data-testid="pos-conn"
         style="display: none;
                padding: 10px 14px; border-radius: 8px;
                background: rgba(184, 92, 66, 0.14); color: #B85C42;
                border: 1px solid rgba(184, 92, 66, 0.35);
                font-size: 13px; font-weight: 500; gap: 12px;">
        <span class="lp-pos__conn-label">Offline, sales will queue and send on reconnect.</span>
        <span class="lp-pos__conn-queue" style="opacity: 0.75;"></span>
        <button type="button" class="lp-pos__conn-retry"
                onclick="window.KevernaPos && window.KevernaPos.drain()"
                data-testid="pos-conn-retry"
                style="margin-left: auto; background: none; border: 1px solid currentColor; padding: 2px 10px; border-radius: 4px; color: inherit; cursor: pointer; font-size: 12px;">
            Retry now
        </button>
    </div>

    {{-- ── Failed sales panel (rejected queued sales) ───────────── --}}
    <div x-show="$store.pos.failedCount > 0"
         x-cloak
         data-testid="pos-failed-panel"
         style="padding: 12px 14px; border-radius: 8px;
                background: rgba(184, 92, 66, 0.10); color: #7a3d2b;
                border: 1px solid rgba(184, 92, 66, 0.30);
                font-size: 13px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <strong style="color: #B85C42;">Needs attention</strong>
            <span x-text="$store.pos.failedCount + ' queued sale' + ($store.pos.failedCount === 1 ? '' : 's') + ' were rejected by the server.'"></span>
            <button type="button"
                    onclick="window.KevernaPosFailed && window.KevernaPosFailed.openPanel()"
                    data-testid="pos-failed-open"
                    style="margin-left: auto; background: none; border: 1px solid currentColor; padding: 2px 10px; border-radius: 4px; color: inherit; cursor: pointer; font-size: 12px;">
                Review
            </button>
        </div>
    </div>

    @include('_partials._back-button')

    {{-- ── Hero: outlet identity + terminal chrome links ─
         Header only. The product grid, cart, payment sheet,
         and offline banners below intentionally keep their
         tap-optimised layout — this is a live cash register,
         not a report. --}}
    <section class="lp-res-idx-hero mt-3" data-testid="pos-hero">
        <div class="lp-res-idx-hero-body" style="grid-template-columns: 1fr;">
            <div class="lp-res-idx-hero-identity">
                <span class="lp-eyebrow-strip">{{ $outlet->type->label() }} · POS</span>
                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,40px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.02; letter-spacing: -0.01em;">
                    {{ $outlet->name }}
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft); max-width: 620px;">
                    Ring sales, take payment, charge to a room, or run a walk-in.
                    @if ((float) $outlet->tax_rate > 0)
                        <span class="lp-mono" style="color: var(--color-ink-mute); margin-left: 6px;">
                            {{ rtrim(rtrim((string) $outlet->tax_rate, '0'), '.') }}% tax
                        </span>
                    @endif
                </p>
                <div class="lp-res-idx-hero-actions">
                    {{-- Shift control: click-once to open a new shift on
                         arrival (snapshots opening stock); once open,
                         becomes a link to the running shift report where
                         the operator can close it at end-of-shift. --}}
                    @if ($activeShiftId === null)
                        <button type="button" wire:click="openShift"
                                wire:loading.attr="disabled"
                                wire:target="openShift"
                                data-testid="pos-open-shift"
                                class="lp-res-action lp-res-action--primary">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="8" r="5.5"/><path d="M8 5v3l2 2"/></svg>
                            <span wire:loading.remove wire:target="openShift">Open shift</span>
                            <span wire:loading wire:target="openShift">Opening…</span>
                        </button>
                    @else
                        <a href="{{ route('tenant.pos.shifts.show', $activeShiftId) }}" wire:navigate
                           data-testid="pos-active-shift"
                           class="lp-res-action lp-res-action--primary"
                           style="background: rgba(93, 132, 44, 0.15); color: var(--color-accent-deep); border-color: rgba(93, 132, 44, 0.4);">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="1.5" fill="currentColor" stroke="none"/></svg>
                            End shift →
                        </a>
                    @endif
                    <a href="{{ route('tenant.pos.shifts.index') }}" wire:navigate
                       class="lp-res-action"
                       data-testid="pos-shifts-link">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 3h10v10H3z"/><path d="M6 3v10M10 3v10"/></svg>
                        Shift reports
                    </a>
                    <a href="{{ route('tenant.pos.sales.index') }}" wire:navigate
                       class="lp-res-action lp-res-action--ghost">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M3 4h10M3 8h10M3 12h6"/></svg>
                        Recent sales
                    </a>
                    <a href="{{ route('tenant.pos.link-device') }}" wire:navigate
                       data-testid="pos-terminal-link-device"
                       class="lp-res-action lp-res-action--ghost">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><rect x="5" y="2" width="6" height="12" rx="1.5"/><path d="M8 12.5h.01"/></svg>
                        Link phone
                    </a>
                    {{-- Client-side kiosk mode. Toggles a body class that
                         hides the sidebar + topbar chrome, requests
                         browser fullscreen, and pins the "Exit kiosk"
                         chip below. Sticky per device via localStorage
                         so restarting the tablet keeps the mode; the
                         inline script at the bottom of this file
                         restores state on load. --}}
                    <button type="button"
                            data-testid="pos-kiosk-toggle"
                            onclick="window.KevernaPosKiosk && window.KevernaPosKiosk.toggle()"
                            class="lp-res-action lp-res-action--ghost">
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M2 6V2h4M14 6V2h-4M2 10v4h4M14 10v4h-4"/></svg>
                        Kiosk mode
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- Server-inlined product snapshot, Alpine seeds IndexedDB from this
         so the first render has products even before the network sync.
         Also the offline enqueue path's fallback payload shape. --}}
    <script id="pos-products-boot" type="application/json">{!! json_encode($productsBoot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    <div class="lp-pos__layout">

        {{-- ── Product grid ─────────────────────────────────────── --}}
        <section class="lp-pos__products lp-dash-card">

            {{-- Barcode / SKU scan input. USB scanners emit keystrokes
                 ending with Enter, which triggers the submit handler.
                 Auto-focus on load so the scanner keystrokes always
                 land here and don't scatter across the page. --}}
            <form @submit.prevent="const input = $event.target.elements.code; const r = await $store.pos.scanBarcode(input.value); input.value = ''; if (!r.ok && r.reason === 'not_found') { $store.pos.error = 'No product with that barcode / SKU in this outlet.'; setTimeout(() => $store.pos.error = null, 3000); }"
                  class="lp-pos__scan" data-testid="pos-scan-form"
                  style="display: flex; gap: 6px; margin-bottom: 12px;">
                <input id="pos-scan-input" name="code" type="text"
                       data-testid="pos-scan-input"
                       class="lp-input lp-mono"
                       placeholder="Scan barcode or type SKU, then Enter"
                       autocomplete="off"
                       autofocus
                       style="flex: 1; background: #FFFFFF;">
                <button type="button" class="lp-btn"
                        data-testid="pos-scan-camera"
                        data-camera-scan
                        data-camera-scan-target="#pos-scan-input"
                        data-camera-scan-submit="#pos-scan-add"
                        title="Scan with camera">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px;">
                        <path d="M2 5h2l1-2h6l1 2h2v9H2z"/>
                        <circle cx="8" cy="9" r="2.5"/>
                    </svg>
                </button>
                {{-- Add submit is hidden but retained: Enter in the input
                     triggers the form submit natively, and the camera
                     scan flow still calls #pos-scan-add.click() after a
                     successful decode. Removing the button entirely would
                     break the camera scan path. --}}
                <button id="pos-scan-add" type="submit" class="lp-btn" data-testid="pos-scan-add"
                        style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0;"
                        aria-hidden="true"
                        tabindex="-1">Add</button>
            </form>

            <template x-if="$store.pos.productsLoading">
                <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="pos-products-loading">Loading products…</p>
            </template>

            <template x-if="!$store.pos.productsLoading && $store.pos.products.length === 0">
                <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="pos-products-empty">
                    No active products in this outlet yet. A manager needs to add some in Inventory first.
                </p>
            </template>

            <div class="lp-pos__grid" data-testid="pos-products-grid"
                 x-show="!$store.pos.productsLoading && $store.pos.products.length > 0">
                <template x-for="product in $store.pos.products" :key="product.id">
                    <button type="button"
                            :data-testid="'pos-product-' + product.id"
                            @click="$store.pos.addToCart(product)"
                            :disabled="product.track_stock && product.quantity_on_hand <= 0"
                            :class="'lp-pos__tile ' + (product.track_stock && product.quantity_on_hand <= 0 ? 'is-out' : '')">
                        <span class="name" x-text="product.name"></span>
                        <span class="price">&#8358;<span x-text="product.price.toLocaleString()"></span></span>
                        <template x-if="product.track_stock">
                            <span class="stock"><span x-text="product.quantity_on_hand"></span> in stock</span>
                        </template>
                    </button>
                </template>
            </div>
        </section>

        {{-- ── Cart pane ────────────────────────────────────────── --}}
        <aside class="lp-pos__cart lp-dash-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Cart</span>
                <span class="lp-dash-card-meta">
                    <span x-text="$store.pos.cart.length"></span>
                    item<span x-show="$store.pos.cart.length !== 1">s</span>
                </span>
            </div>

            <template x-if="$store.pos.cart.length === 0">
                <p class="text-[13.5px]" data-testid="cart-empty" style="color: var(--color-ink-mute); padding: 20px 0;">
                    Tap a product to start a sale.
                </p>
            </template>

            <template x-if="$store.pos.cart.length > 0">
                <div>
                    <ul class="lp-pos__lines" data-testid="cart-lines" style="list-style: none; padding: 0; margin: 0 0 12px 0;">
                        <template x-for="line in $store.pos.cart" :key="line.product_id">
                            <li class="lp-pos__line"
                                :class="line.is_complimentary ? 'is-comp' : ''"
                                :data-testid="'cart-line-' + line.product_id">
                                <div class="body">
                                    <span class="name" x-text="line.name"></span>
                                    <span class="unit lp-mono">
                                        &#8358;<span x-text="line.unit_price.toLocaleString()"></span>
                                        <span x-show="line.is_complimentary" x-cloak
                                              class="lp-mono"
                                              style="margin-left: 6px; padding: 1px 6px; border-radius: 999px;
                                                     background: rgba(93, 132, 44, 0.18); color: var(--color-accent-deep);
                                                     font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; font-weight: 700;">
                                            comp
                                        </span>
                                    </span>
                                </div>
                                <div class="stepper">
                                    <button type="button" @click="$store.pos.decrement(line.product_id)" aria-label="Decrease" :data-testid="'cart-dec-' + line.product_id">−</button>
                                    <span class="qty" x-text="line.quantity"></span>
                                    <button type="button" @click="$store.pos.increment(line.product_id)" aria-label="Increase" :data-testid="'cart-inc-' + line.product_id">+</button>
                                </div>
                                <div class="subtotal lp-mono">
                                    <span x-show="!line.is_complimentary">&#8358;<span x-text="(line.unit_price * line.quantity).toLocaleString()"></span></span>
                                    <span x-show="line.is_complimentary" x-cloak
                                          style="color: var(--color-accent-deep);">&#8358;0</span>
                                </div>
                                {{-- Comp toggle: tap 🎁 to flip this line's is_complimentary
                                     flag. The line stays on the cart (stock still decrements
                                     on complete) but its subtotal contribution drops to ₦0. --}}
                                <button type="button"
                                        @click="$store.pos.toggleLineComp(line.product_id)"
                                        :aria-label="line.is_complimentary ? 'Un-mark complimentary' : 'Mark complimentary'"
                                        :data-testid="'cart-comp-' + line.product_id"
                                        :title="line.is_complimentary ? 'Un-comp this item' : 'Comp this item'"
                                        style="background: none; border: none; cursor: pointer; font-size: 15px; opacity: 0.7;"
                                        :style="line.is_complimentary ? 'opacity: 1;' : ''">🎁</button>
                                <button type="button" @click="$store.pos.removeFromCart(line.product_id)" aria-label="Remove"
                                        style="background: none; border: none; color: #B85C42; cursor: pointer; font-size: 16px;">×</button>
                            </li>
                        </template>
                    </ul>

                    <div class="lp-pos__totals" data-testid="cart-totals">
                        <div><span>Subtotal</span><span class="lp-mono">&#8358;<span x-text="$store.pos.subtotal().toLocaleString()"></span></span></div>
                        <template x-if="$store.pos.taxAmount() > 0">
                            <div><span>Tax ({{ rtrim(rtrim((string) $outlet->tax_rate, '0'), '.') }}%)</span><span class="lp-mono">&#8358;<span x-text="$store.pos.taxAmount().toLocaleString()"></span></span></div>
                        </template>
                        <div class="grand"><span>Total</span><span class="lp-mono">&#8358;<span x-text="$store.pos.total().toLocaleString()"></span></span></div>
                    </div>

                    <div class="flex gap-2 mt-4" x-show="!$store.pos.paymentOpen">
                        <button type="button" @click="$store.pos.clearCart()" class="lp-btn" data-testid="cart-clear" style="flex: 0;">Clear</button>
                        <button type="button" @click="$store.pos.openPayment()" class="lp-btn lp-btn--primary" data-testid="cart-pay" style="flex: 1;">
                            Take payment · &#8358;<span x-text="$store.pos.total().toLocaleString()"></span>
                        </button>
                    </div>
                </div>
            </template>
        </aside>
    </div>

    {{-- Payment action sheet, bottom-sheet-on-mobile / centered-card-
         on-desktop overlay. Rendered at the terminal level so it can
         slide over the whole surface without being clipped by the cart
         aside. --}}
    @include('livewire.dashboard.pos._payment-sheet-modal', [
        'bookingRef' => $bookingRef,
        'bookingIdResolved' => $bookingIdResolved,
        'bookingLookupError' => $bookingLookupError,
        'inHouseBookings' => $inHouseBookings,
    ])

    {{-- Charge-to-room guest picker modal, stacks on top of the
         payment sheet at a higher z-index. Trigger buttons live inside
         _booking-lookup.blade.php via $store.pos.openBookingModal(). --}}
    @include('livewire.dashboard.pos._booking-lookup-modal', [
        'bookingRef' => $bookingRef,
        'bookingIdResolved' => $bookingIdResolved,
        'bookingLookupError' => $bookingLookupError,
        'inHouseBookings' => $inHouseBookings,
    ])

    {{-- Credit-line picker, quick chip strip visible when the room
         tender is active. Picking a line clears any booking selection
         (a sale routes to one anchor). --}}
    @if ($creditLines->isNotEmpty())
        <div x-show="($store.pos.method === 'room' || $store.pos.tenders.some(t => t.method === 'room')) && $store.pos.paymentOpen"
             x-cloak
             data-testid="pos-credit-line-picker"
             style="position: fixed; left: 0; right: 0; bottom: 0;
                    background: var(--color-card); border-top: 1px solid var(--color-line-soft);
                    padding: 10px 14px; z-index: 60;
                    box-shadow: 0 -8px 20px rgba(0,0,0,0.06);">
            <div class="flex items-center gap-2 mb-2">
                <span class="lp-mono text-[10.5px]"
                      style="letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-ink-mute);">
                    Or charge to a credit-line tab
                </span>
                <span x-show="$store.pos.creditLineId"
                      x-cloak
                      class="lp-mono text-[11px]"
                      style="background: #DCFCE7; color: #166534; padding: 2px 8px; border-radius: 999px;">
                    <span x-text="$store.pos.creditLineName"></span>
                </span>
                <button type="button"
                        x-show="$store.pos.creditLineId"
                        x-cloak
                        wire:click="clearCreditLineSelection"
                        data-testid="pos-credit-line-clear"
                        class="lp-btn" style="margin-left: auto; height: 26px; padding: 0 10px; font-size: 11px;">
                    Clear
                </button>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                @foreach ($creditLines as $cl)
                    <button type="button"
                            wire:click="pickCreditLine({{ $cl->id }})"
                            data-testid="pos-credit-line-chip-{{ $cl->id }}"
                            class="lp-btn"
                            style="height: 30px; padding: 0 10px; font-size: 12px;">
                        {{ $cl->name }}
                        <span class="lp-mono text-[10px]" style="color: var(--color-ink-mute); margin-left: 4px;">
                            · {{ $cl->rooms->pluck('room_number')->implode(',') }}
                        </span>
                    </button>
                @endforeach
            </div>
            @if ($creditLineLookupError)
                <p class="text-[12px] mt-2" style="color: #B85C42;"
                   data-testid="pos-credit-line-error">
                    {{ $creditLineLookupError }}
                </p>
            @endif
        </div>
    @endif

    {{-- ── Kiosk mode chrome ─────────────────────────────────────
         Client-side toggle. Adds a `data-kiosk="true"` attribute
         to <html> when active, which the CSS at the bottom uses
         to hide the sidebar + topbar. Also requests browser
         fullscreen and pins an exit chip in the corner. State
         persists per device via localStorage so a tablet stays
         in kiosk mode after a page reload / power cycle. Scoped
         under a script so we don't ship it globally. --}}
    <button type="button"
            data-testid="pos-kiosk-exit"
            onclick="window.KevernaPosKiosk && window.KevernaPosKiosk.exit()"
            class="lp-pos-kiosk-exit"
            aria-label="Exit kiosk mode"
            hidden>
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden><path d="M4 4l8 8M12 4l-8 8"/></svg>
        Exit kiosk
    </button>

    <script>
    (function () {
        const KEY = 'keverna.pos.kioskMode';
        const html = document.documentElement;

        function applyClass(on) {
            if (on) {
                html.setAttribute('data-kiosk', 'true');
            } else {
                html.removeAttribute('data-kiosk');
            }
            document.querySelectorAll('[data-testid="pos-kiosk-exit"]').forEach((el) => {
                if (on) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); }
            });
        }

        async function requestFullscreen() {
            try {
                if (document.fullscreenElement) return;
                if (html.requestFullscreen) await html.requestFullscreen();
                else if (html.webkitRequestFullscreen) await html.webkitRequestFullscreen();
            } catch (_) { /* user cancelled or unsupported; kiosk still works */ }
        }

        async function exitFullscreen() {
            try {
                if (!document.fullscreenElement) return;
                if (document.exitFullscreen) await document.exitFullscreen();
                else if (document.webkitExitFullscreen) await document.webkitExitFullscreen();
            } catch (_) { /* noop */ }
        }

        async function enter() {
            try { localStorage.setItem(KEY, '1'); } catch (_) {}
            applyClass(true);
            await requestFullscreen();
        }

        async function exit() {
            try { localStorage.setItem(KEY, '0'); } catch (_) {}
            applyClass(false);
            await exitFullscreen();
        }

        function toggle() {
            const active = html.getAttribute('data-kiosk') === 'true';
            return active ? exit() : enter();
        }

        // Restore state on load. We DO NOT auto-request fullscreen on
        // restore because browsers require a user gesture — instead we
        // just re-apply the chrome-hidden class so the layout is right,
        // and the operator can hit F11 or click the button once to
        // re-enter fullscreen.
        try {
            if (localStorage.getItem(KEY) === '1') applyClass(true);
        } catch (_) {}

        // Escape key is the standard "get me out" for full-screen apps.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && html.getAttribute('data-kiosk') === 'true') {
                exit();
            }
        });

        // If the operator leaves fullscreen via a browser control
        // (Esc, tab-switch, OS shortcut) exit kiosk mode too so the
        // layout snaps back to the dashboard chrome.
        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement && html.getAttribute('data-kiosk') === 'true') {
                exit();
            }
        });

        window.KevernaPosKiosk = { enter, exit, toggle };
    })();
    </script>
</div>
