/*
 * Alpine.js store owning the POS terminal's client-side state.
 *
 * Why Alpine and not Livewire: every wire:click needs a live server. When
 * the phone drops off Wi-Fi mid-shift, wire:click hangs; the operator
 * can't add another item until reconnect. Moving cart + payment sheet
 * state into the browser removes that dependency — the terminal keeps
 * working, and completion always funnels through /dashboard/pos/queue
 * (which the offline queue drains on reconnect).
 *
 * Livewire still owns booking lookup and the in-house guest picker —
 * those inherently need a server, and they're hidden offline via the
 * gates in terminal.blade.php.
 *
 * Store shape (Alpine.store('pos')):
 *   outletId, taxRate, isOnline
 *   products[]              — from IndexedDB, kept in sync with the server
 *   cart[]                  — {product_id, name, unit_price, quantity}
 *   method                  — 'cash' | 'transfer' | 'card' | 'room' | 'wallet'
 *   splitMode, tenders[]    — split-tender rows
 *   bookingRef, bookingId   — bridged in from Livewire when method=room|wallet
 *   walletBalance           — bridged in when a booking with a wallet-linked
 *                             customer is resolved; ₦0 means Wallet tender
 *                             is not available
 *   notes
 *   error, notice           — transient banners
 *   paymentOpen             — whether the payment sheet is shown
 *   pendingComplete         — while a POST is in-flight
 */

const CART_STORAGE_PREFIX = 'keverna_pos_cart_v1:';

function csrfToken() {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
}

function newClientKey() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    return 'c-' + Math.random().toString(36).slice(2) + '-' + Date.now();
}

function mapServerError(code) {
    switch (code) {
        case 'not_assigned_to_outlet':  return "You aren't assigned to this outlet.";
        case 'outlet_missing':          return "That outlet no longer exists.";
        case 'booking_ref_required':    return 'Charge-to-room needs a booking reference.';
        case 'booking_not_found':       return "That booking reference doesn't exist.";
        case 'booking_wrong_status':    return 'Booking status has changed — refresh and try again.';
        case 'unauthenticated':         return 'Signed out — reload the page.';
        default:                        return null;
    }
}

// Module-scope visibility listener. Previously this was registered
// inside the Alpine store's init(), which meant every wire:navigate
// into (and back out of) a POS page stacked another closure on
// document — after N navigations there were N closures firing on
// every tab-focus, each pulling a stale `this` reference. Now there's
// exactly one listener; it reads Alpine.store('pos') at call time and
// no-ops when we're not on a POS surface.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    const store = window.Alpine?.store('pos');
    if (!store || !store.outletId || !store.isOnline) return;
    if (typeof store.loadProducts === 'function') {
        store.loadProducts({ background: true });
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.store('pos', {
        outletId: 0,
        taxRate: 0,
        isOnline: true,
        products: [],
        cart: [],
        method: 'cash',
        splitMode: false,
        tenders: [],
        cashTendered: 0,
        bookingRef: '',
        bookingId: null,
        walletBalance: 0,
        // House-account tab picked for "charge to room" sales. When
        // set, room tenders route to a CreditLineCharge instead of a
        // BookingCharge. Mutually exclusive with bookingId — the
        // Livewire terminal clears whichever one loses on pick.
        creditLineId: null,
        creditLineName: '',
        bookingModalOpen: false,
        notes: '',
        error: null,
        notice: null,
        paymentOpen: false,
        pendingComplete: false,
        productsLoading: false,
        productsError: null,
        queueCount: 0,
        failedCount: 0,

        // Sale-level complimentary. When true the whole sale skips the
        // tender loop and no Payment rows are written server-side.
        // Reason is required for the audit trail.
        saleComp: false,
        saleCompReason: '',

        // Customer tie. The operator can optionally punch a phone
        // number to look up an existing Customer, or profile a new
        // one inline so the sale attaches to a real person in the CRM.
        // Snapshot fields ride along even when customerId stays null,
        // so a walk-in still leaves a paper trail.
        customerId: null,
        customerName: '',
        customerPhone: '',
        customerLookupLoading: false,
        customerLookupError: null,
        customerNewOpen: false,

        /* ── Init ─────────────────────────────────────────────────── */

        async init(outletId, taxRate, bootProducts) {
            this.outletId = Number(outletId);
            this.taxRate = Number(taxRate);
            this.isOnline = window.KevernaPos?.isEffectivelyOnline() ?? navigator.onLine;

            // Seed IDB from the server-inlined snapshot so the very
            // first render has products even without a network round-trip.
            if (Array.isArray(bootProducts) && bootProducts.length > 0 && window.KevernaPosDB) {
                await window.KevernaPosDB.seedProducts(this.outletId, bootProducts);
            }

            this.restoreCart();
            await this.loadProducts();

            // Sync with the queue/online layer's state.
            this.queueCount = Number(document.documentElement.dataset.posQueueCount ?? 0);
            this.failedCount = await this.readFailedCount();

            window.addEventListener('keverna-pos:online-changed', (e) => {
                this.isOnline = Boolean(e.detail?.online);
            });
            // Auto-close the booking picker as soon as a selection lands
            // (either from the in-house list tap or the manual ref lookup).
            window.addEventListener('pos-booking-resolved', () => {
                this.bookingModalOpen = false;
            });
            window.addEventListener('keverna-pos:queue-changed', async (e) => {
                this.queueCount = Number(e.detail?.count ?? 0);
                this.failedCount = await this.readFailedCount();
            });
            window.addEventListener('keverna-pos:failed-changed', async () => {
                this.failedCount = await this.readFailedCount();
            });
        },

        async readFailedCount() {
            if (!window.KevernaPosDB) return 0;
            const failed = await window.KevernaPosDB.getFailed();
            return failed.length;
        },

        async loadProducts({ background = false } = {}) {
            if (!window.KevernaPosDB) return;
            if (!background) this.productsLoading = true;
            this.productsError = null;
            try {
                if (this.isOnline) {
                    this.products = await window.KevernaPosDB.syncProducts(this.outletId);
                } else {
                    this.products = await window.KevernaPosDB.getProducts(this.outletId);
                }
            } catch (e) {
                this.productsError = e.message ?? 'Failed to load products';
            } finally {
                this.productsLoading = false;
            }
        },

        /* ── Cart persistence (sessionStorage) ───────────────────── */

        persistCart() {
            try {
                sessionStorage.setItem(
                    CART_STORAGE_PREFIX + this.outletId,
                    JSON.stringify(this.cart),
                );
            } catch { /* full / disabled — cart still works in memory */ }
        },

        restoreCart() {
            try {
                const raw = sessionStorage.getItem(CART_STORAGE_PREFIX + this.outletId);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) this.cart = parsed;
            } catch { /* ignore */ }
        },

        /* ── Cart mutations ──────────────────────────────────────── */

        addToCart(product) {
            const existing = this.cart.find((l) => l.product_id === product.id);
            if (existing) {
                existing.quantity += 1;
            } else {
                this.cart.push({
                    product_id: Number(product.id),
                    name: String(product.name),
                    unit_price: Number(product.price),
                    quantity: 1,
                    is_complimentary: false,
                });
            }
            this.persistCart();
        },

        increment(productId) {
            const line = this.cart.find((l) => l.product_id === productId);
            if (line) { line.quantity += 1; this.persistCart(); }
        },

        decrement(productId) {
            const idx = this.cart.findIndex((l) => l.product_id === productId);
            if (idx === -1) return;
            this.cart[idx].quantity -= 1;
            if (this.cart[idx].quantity <= 0) this.cart.splice(idx, 1);
            this.persistCart();
        },

        removeFromCart(productId) {
            this.cart = this.cart.filter((l) => l.product_id !== productId);
            this.persistCart();
        },

        /* Per-line complimentary toggle. Line stays on the cart (still
           needs stock decrement on complete + still shows up in the
           receipt for the guest), but its subtotal contribution drops
           to ₦0. Reporting reads is_complimentary from the PosSaleLine. */
        toggleLineComp(productId) {
            const line = this.cart.find((l) => l.product_id === productId);
            if (!line) return;
            line.is_complimentary = !line.is_complimentary;
            this.persistCart();
        },

        clearCart() {
            this.cart = [];
            this.paymentOpen = false;
            this.splitMode = false;
            this.tenders = [];
            this.method = 'cash';
            this.bookingRef = '';
            this.bookingId = null;
            this.creditLineId = null;
            this.creditLineName = '';
            this.notes = '';
            this.cashTendered = 0;
            this.saleComp = false;
            this.saleCompReason = '';
            this.customerId = null;
            this.customerName = '';
            this.customerPhone = '';
            this.customerLookupError = null;
            this.customerNewOpen = false;
            try { sessionStorage.removeItem(CART_STORAGE_PREFIX + this.outletId); } catch { /* */ }
        },

        /* ── Barcode ─────────────────────────────────────────────── */

        async scanBarcode(code) {
            const trimmed = (code || '').trim();
            if (!trimmed) return { ok: false, reason: 'empty' };
            const product = window.KevernaPosDB
                ? await window.KevernaPosDB.findByCode(this.outletId, trimmed)
                : null;
            if (!product) return { ok: false, reason: 'not_found' };
            this.addToCart(product);
            return { ok: true, product };
        },

        /* ── Payment sheet ───────────────────────────────────────── */

        openPayment() {
            if (this.cart.length === 0) return;
            this.paymentOpen = true;
            this.error = null;
            this.method = 'cash';
            this.cashTendered = this.total();
            this.splitMode = false;
            this.tenders = [];
            this.bookingRef = '';
            this.bookingId = null;
            this.creditLineId = null;
            this.creditLineName = '';
            this.notes = '';
        },

        cancelPayment() {
            this.paymentOpen = false;
            this.error = null;
        },

        setMethod(method) {
            if (!this.isOnline && (method === 'room' || method === 'wallet')) return;
            if (method === 'wallet' && !this.walletAvailable(this.total())) return;
            this.method = method;
        },

        /**
         * Wallet tender is only allowed when a booking with a linked
         * customer is resolved AND that customer's wallet holds at
         * least the requested amount. Balance is bridged in from
         * Livewire via the pos-booking-resolved event.
         */
        walletAvailable(amount) {
            return this.isOnline && this.bookingId && Number(this.walletBalance) >= Number(amount || 0);
        },

        openBookingModal() {
            if (!this.isOnline) return;
            this.bookingModalOpen = true;
        },

        closeBookingModal() {
            this.bookingModalOpen = false;
        },

        enableSplit() {
            this.splitMode = true;
            this.tenders = [
                { method: this.method, amount: this.total() },
                { method: 'cash', amount: 0 },
            ];
        },

        disableSplit() {
            this.splitMode = false;
            this.tenders = [];
        },

        addTender() {
            this.tenders.push({ method: 'cash', amount: 0 });
        },

        removeTender(index) {
            this.tenders.splice(index, 1);
            if (this.tenders.length < 2) this.disableSplit();
        },

        /* ── Totals ──────────────────────────────────────────────── */

        // Chargeable subtotal — comped lines contribute ₦0, and a
        // whole-sale complimentary flag zeros the total unconditionally.
        subtotal() {
            if (this.saleComp) return 0;
            return this.cart.reduce((sum, l) => {
                if (l.is_complimentary) return sum;
                return sum + l.unit_price * l.quantity;
            }, 0);
        },

        // Value of the give-away — the ₦ amount that would have been
        // billed but isn't. Surfaced in the cart UI so the operator (or
        // a supervisor over their shoulder) sees the comp size.
        compTotal() {
            if (this.saleComp) {
                return this.cart.reduce((sum, l) => sum + l.unit_price * l.quantity, 0);
            }
            return this.cart.reduce((sum, l) => {
                if (!l.is_complimentary) return sum;
                return sum + l.unit_price * l.quantity;
            }, 0);
        },

        taxAmount() {
            return Math.round(this.subtotal() * (this.taxRate / 100));
        },

        total() {
            return this.subtotal() + this.taxAmount();
        },

        tenderTotal() {
            return this.tenders.reduce((sum, t) => sum + Number(t.amount || 0), 0);
        },

        tenderDelta() {
            return this.total() - this.tenderTotal();
        },

        change() {
            return Math.max(0, Number(this.cashTendered || 0) - this.total());
        },

        /* ── Completion ──────────────────────────────────────────── */

        needsBooking() {
            if (this.splitMode) {
                return this.tenders.some((t) => t.method === 'room' || t.method === 'wallet');
            }
            return this.method === 'room' || this.method === 'wallet';
        },

        walletTenderTotal() {
            if (this.splitMode) {
                return this.tenders
                    .filter((t) => t.method === 'wallet')
                    .reduce((sum, t) => sum + Number(t.amount || 0), 0);
            }
            return this.method === 'wallet' ? this.total() : 0;
        },

        canComplete() {
            if (this.cart.length === 0) return false;
            if (this.pendingComplete) return false;

            // Complimentary sale: reason required, no tender validation
            // (nothing is being taken from the guest). Skips the rest
            // of the tender guards below.
            if (this.saleComp) {
                return this.saleCompReason.trim() !== '';
            }

            if (this.splitMode && this.tenderDelta() !== 0) return false;
            if (this.needsBooking() && !this.isOnline) return false;
            // A room tender can settle against a picked credit line
            // instead of a booking; wallet still needs a booking.
            const hasRoom = this.splitMode
                ? this.tenders.some((t) => t.method === 'room')
                : this.method === 'room';
            const hasWallet = this.splitMode
                ? this.tenders.some((t) => t.method === 'wallet')
                : this.method === 'wallet';
            if (hasWallet && !this.bookingId) return false;
            if (hasRoom && !this.bookingId && !this.creditLineId) return false;
            // Wallet tender must fit inside the guest's balance.
            const walletTotal = this.walletTenderTotal();
            if (walletTotal > 0 && Number(this.walletBalance) < walletTotal) return false;
            return true;
        },

        buildPayload() {
            // Complimentary sale: no tenders. Sale total floors to 0
            // server-side; CompletePosSale short-circuits the Payment
            // loop but still runs stock decrements.
            const tenders = this.saleComp
                ? []
                : (this.splitMode
                    ? this.tenders.map((t) => ({ method: t.method, amount: Number(t.amount || 0) }))
                    : [{ method: this.method, amount: this.total() }]);

            // needsBooking is true for room or wallet tenders. Room
            // tenders can settle against a credit line instead of a
            // booking — when the operator picked one, we send the
            // credit_line_id and skip the booking_ref requirement.
            const hasRoomTender = !this.saleComp && (this.splitMode
                ? this.tenders.some((t) => t.method === 'room')
                : this.method === 'room');
            const hasWalletTender = !this.saleComp && (this.splitMode
                ? this.tenders.some((t) => t.method === 'wallet')
                : this.method === 'wallet');
            const routingToCreditLine = hasRoomTender && this.creditLineId !== null;

            return {
                client_key: newClientKey(),
                outlet_id: this.outletId,
                cart: this.cart.map((l) => ({
                    product_id: l.product_id,
                    name: l.name,
                    unit_price: l.unit_price,
                    quantity: l.quantity,
                    is_complimentary: !!l.is_complimentary,
                })),
                notes: this.notes || null,
                booking_ref: (hasWalletTender || (hasRoomTender && !routingToCreditLine))
                    ? this.bookingRef
                    : null,
                credit_line_id: routingToCreditLine ? this.creditLineId : null,
                tenders,
                is_complimentary: this.saleComp,
                complimentary_reason: this.saleComp ? this.saleCompReason.trim() : null,
                customer_id: this.customerId,
                customer_phone: this.customerPhone || null,
                customer_name: this.customerName || null,
                client_completed_at: new Date().toISOString(),
            };
        },

        /* ── Customer lookup ────────────────────────────────────────
           Sends the punched phone number to the server, which looks
           up (tenant + branch scoped) Customer rows. On match, we
           bind customerId + name so the sale attaches to the CRM;
           on miss we open the inline profile form. The lookup uses
           a Livewire method on the terminal so tenant scoping + auth
           come along for the ride. */
        async lookupCustomer() {
            const phone = (this.customerPhone || '').trim();
            if (phone.length < 3) {
                this.customerLookupError = 'Enter a phone number to search.';
                return;
            }
            this.customerLookupLoading = true;
            this.customerLookupError = null;
            try {
                const wireEl = document.querySelector('[data-testid="pos-terminal"][wire\\:id]');
                if (!wireEl) throw new Error('terminal wire component not found');
                const wireId = wireEl.getAttribute('wire:id');
                const cmp = window.Livewire?.find(wireId);
                if (!cmp) throw new Error('livewire component missing');
                const result = await cmp.call('lookupCustomerByPhone', phone);
                if (result && result.found) {
                    this.customerId = Number(result.id);
                    this.customerName = String(result.name || '');
                    this.customerPhone = String(result.phone || phone);
                    this.customerNewOpen = false;
                } else {
                    this.customerId = null;
                    this.customerName = '';
                    this.customerNewOpen = true;
                }
            } catch (e) {
                this.customerLookupError = "Couldn't reach the customer service; try again.";
            } finally {
                this.customerLookupLoading = false;
            }
        },

        async saveNewCustomer() {
            const phone = (this.customerPhone || '').trim();
            const name = (this.customerName || '').trim();
            if (name.length < 2) {
                this.customerLookupError = 'Give the customer a name.';
                return;
            }
            this.customerLookupLoading = true;
            this.customerLookupError = null;
            try {
                const wireEl = document.querySelector('[data-testid="pos-terminal"][wire\\:id]');
                const wireId = wireEl.getAttribute('wire:id');
                const cmp = window.Livewire?.find(wireId);
                const result = await cmp.call('saveQuickCustomer', phone, name);
                if (result && result.id) {
                    this.customerId = Number(result.id);
                    this.customerName = String(result.name);
                    this.customerNewOpen = false;
                } else {
                    this.customerLookupError = "Couldn't save that customer.";
                }
            } catch (e) {
                this.customerLookupError = "Couldn't save that customer.";
            } finally {
                this.customerLookupLoading = false;
            }
        },

        clearCustomer() {
            this.customerId = null;
            this.customerName = '';
            this.customerPhone = '';
            this.customerNewOpen = false;
            this.customerLookupError = null;
        },

        async complete() {
            if (!this.canComplete()) return;
            this.error = null;
            this.notice = null;
            this.pendingComplete = true;

            const payload = this.buildPayload();

            // Offline: enqueue + optimistic clear. The queue drains
            // automatically on reconnect (see pos-offline.js).
            if (!window.KevernaPos?.isEffectivelyOnline()) {
                window.KevernaPos.enqueue(payload);
                this.notice = 'Offline — sale queued. It will send when the connection returns.';
                this.clearCart();
                this.pendingComplete = false;
                return;
            }

            // Online path — direct POST for immediate feedback +
            // navigation to the sale detail. On transient failure,
            // fall back to enqueue so the operator isn't blocked.
            try {
                const res = await fetch('/dashboard/pos/queue', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                if (res.ok) {
                    const body = await res.json();
                    this.clearCart();
                    // Livewire terminal navigates to the sale detail on complete;
                    // mirror that so the operator's next tap is "New sale".
                    window.location.assign(`/dashboard/pos/sales/${body.sale_id}`);
                    return;
                }
                if (res.status >= 500 || res.status === 0) {
                    // Transient — queue for later retry.
                    window.KevernaPos.enqueue(payload);
                    this.notice = 'Server unavailable — sale queued for retry.';
                    this.clearCart();
                    return;
                }
                // 4xx — show a friendly reason; DON'T clear cart so the
                // operator can adjust and retry.
                const body = await res.json().catch(() => ({}));
                this.error = mapServerError(body.error) || `Sale rejected (${res.status}).`;
            } catch (e) {
                // Network mid-request — queue it, operator carries on.
                window.KevernaPos.enqueue(payload);
                this.notice = 'Connection dropped — sale queued for retry.';
                this.clearCart();
            } finally {
                this.pendingComplete = false;
            }
        },
    });
});
