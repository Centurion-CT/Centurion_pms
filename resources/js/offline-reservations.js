/*
 * Offline reservation queue for the manual-reservation flows.
 *
 * When the front desk is offline (or a Livewire submit fails), the
 * reserve form serialises its state and hands the payload to
 * queueReservation() below, which stores it in IndexedDB.
 *
 * A single sync worker drains that queue by POSTing each item to
 * /dashboard/reservations/offline-sync, guarded by a client-generated
 * `offline_key` UUID so a re-drive after a partial network fail never
 * lands as a duplicate booking.
 *
 * The queue survives page reloads. Auto-drain fires on load, on the
 * `online` event, and on a manual trigger from the sync-status pill.
 *
 * Kept small + Promise-based so callers don't touch the raw IndexedDB
 * event soup. Graceful degradation: if IndexedDB is unavailable (older
 * browsers, private-mode Safari, storage full), reads resolve to []
 * and writes reject with a swallowable error so the caller can fall
 * back to inline error UI.
 */

const DB_NAME = 'keverna_offline_reservations';
const DB_VERSION = 1;
const STORE = 'queued_reservations';
const SYNC_URL = '/dashboard/reservations/offline-sync';

let dbPromise = null;

function open() {
    if (dbPromise) return dbPromise;
    if (!('indexedDB' in window)) {
        dbPromise = Promise.reject(new Error('IndexedDB unavailable'));
        return dbPromise;
    }
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'offline_key' });
                store.createIndex('by_status', 'status', { unique: false });
                store.createIndex('by_queued_at', 'queued_at', { unique: false });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error || new Error('IndexedDB open failed'));
    });
    return dbPromise;
}

function tx(mode, fn) {
    return open().then((db) => new Promise((resolve, reject) => {
        const t = db.transaction(STORE, mode);
        const store = t.objectStore(STORE);
        let result;
        try {
            result = fn(store);
        } catch (e) {
            reject(e);
            return;
        }
        t.oncomplete = () => resolve(result);
        t.onerror = () => reject(t.error);
        t.onabort = () => reject(t.error);
    }));
}

function reqAsPromise(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

function uuidv4() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    // RFC4122 v4 fallback for older browsers.
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/**
 * Stash a reservation payload for later sync. Assigns an offline_key
 * UUID if the caller didn't provide one. Returns the assigned key so
 * the caller can surface it in the "Saved locally" toast.
 */
export async function queueReservation(payload) {
    const record = {
        ...payload,
        offline_key: payload.offline_key || uuidv4(),
        status: 'queued',           // queued | syncing | conflict | failed | synced
        queued_at: Date.now(),
        last_error: null,
        booking_ref: null,
    };
    await tx('readwrite', (store) => store.put(record));
    return record.offline_key;
}

/**
 * @returns {Promise<Array<object>>} all queued reservations sorted by queued_at asc
 */
export async function listQueued() {
    try {
        return await tx('readonly', (store) => {
            const results = [];
            return new Promise((resolve, reject) => {
                const req = store.index('by_queued_at').openCursor();
                req.onsuccess = () => {
                    const cursor = req.result;
                    if (cursor) {
                        results.push(cursor.value);
                        cursor.continue();
                    } else {
                        resolve(results);
                    }
                };
                req.onerror = () => reject(req.error);
            });
        });
    } catch (e) {
        return [];
    }
}

async function updateRecord(offlineKey, patch) {
    return tx('readwrite', async (store) => {
        const existing = await reqAsPromise(store.get(offlineKey));
        if (!existing) return null;
        const next = { ...existing, ...patch };
        store.put(next);
        return next;
    });
}

async function deleteRecord(offlineKey) {
    return tx('readwrite', (store) => store.delete(offlineKey));
}

/**
 * Best-effort CSRF token lookup — Laravel bakes one into a <meta> tag
 * on every rendered page. Fall back to reading the XSRF-TOKEN cookie
 * when the meta isn't present.
 */
function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) return meta.getAttribute('content') || '';
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function syncOne(record) {
    await updateRecord(record.offline_key, { status: 'syncing', last_error: null });

    let response;
    try {
        response = await fetch(SYNC_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                offline_key: record.offline_key,
                room_id: record.room_id,
                guest_name: record.guest_name,
                guest_phone: record.guest_phone,
                guest_email: record.guest_email || null,
                adults: record.adults,
                children: record.children || 0,
                checkin_date: record.checkin_date,
                checkout_date: record.checkout_date,
                special_requests: record.special_requests || null,
                source_note: record.source_note || null,
                payment_status: record.payment_status || 'pending',
                breakfast_included: !!record.breakfast_included,
                manual_discount_type: record.manual_discount_type || 'percent',
                manual_discount_percent: record.manual_discount_percent || 0,
                manual_discount_fixed_input: record.manual_discount_fixed_input || 0,
            }),
        });
    } catch (e) {
        // Network drop mid-request — mark queued again so the next
        // online event picks it up.
        await updateRecord(record.offline_key, {
            status: 'queued',
            last_error: 'Network unavailable',
        });
        return { ok: false, retryable: true };
    }

    let json = null;
    try { json = await response.json(); } catch (_) { /* non-JSON */ }

    if (response.ok || response.status === 200) {
        await updateRecord(record.offline_key, {
            status: 'synced',
            booking_ref: json?.booking_ref || null,
            last_error: null,
        });
        // Give the sync-status UI a beat to show 'synced' state, then
        // drop the record. Non-blocking.
        setTimeout(() => deleteRecord(record.offline_key).catch(() => {}), 5000);
        return { ok: true, status: json?.status || 'created', booking_ref: json?.booking_ref };
    }
    if (response.status === 409) {
        await updateRecord(record.offline_key, {
            status: 'conflict',
            last_error: json?.reason || 'Room already booked for those dates.',
        });
        return { ok: false, retryable: false };
    }
    // Everything else — validation error, 5xx — mark failed with the
    // reason so the operator can decide whether to retry.
    await updateRecord(record.offline_key, {
        status: 'failed',
        last_error: json?.reason || `Sync failed (${response.status})`,
    });
    return { ok: false, retryable: response.status >= 500 };
}

let draining = false;

/**
 * Try to drain every queued reservation. Skips items in status
 * 'conflict' or 'failed' unless force=true, so the operator can
 * inspect them before we retry.
 */
export async function drainQueue({ force = false } = {}) {
    if (draining) return { skipped: true };
    if (!navigator.onLine) return { skipped: true, offline: true };
    draining = true;
    let synced = 0, failed = 0, conflicts = 0;
    try {
        const items = await listQueued();
        for (const item of items) {
            const eligible =
                item.status === 'queued'
                || (force && (item.status === 'failed' || item.status === 'conflict'));
            if (!eligible) continue;
            const res = await syncOne(item);
            if (res.ok) synced++;
            else if (item.status === 'conflict') conflicts++;
            else failed++;
            // Let the UI observe intermediate state changes.
            window.dispatchEvent(new CustomEvent('kv-queue-changed'));
        }
    } finally {
        draining = false;
    }
    window.dispatchEvent(new CustomEvent('kv-queue-changed'));
    return { synced, failed, conflicts };
}

export async function forgetRecord(offlineKey) {
    await deleteRecord(offlineKey);
    window.dispatchEvent(new CustomEvent('kv-queue-changed'));
}

export async function pendingCount() {
    const items = await listQueued();
    return items.filter((i) => i.status !== 'synced').length;
}

/**
 * Alpine-level submit interceptor for the reserve form. Called via
 * x-on:submit.capture="offlineIntercept($event, $wire)".
 *
 * If the browser is online, we let Livewire handle the submit
 * normally. If it's offline, we harvest the reserve form's field
 * state from the wire component, prevent the Livewire submit, queue
 * the payload for background sync, and reset the modal so the
 * operator can move to the next walk-in without waiting.
 */
async function offlineIntercept(event, wire) {
    if (navigator.onLine) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    // Harvest every reserve* prop the offline endpoint needs. $wire.get()
    // reads local Alpine-side state (thanks to @entangle) with no server
    // round-trip, so this works even when the network is down.
    const g = (name) => {
        try { return wire.get(name); } catch (_) { return null; }
    };

    const payload = {
        room_id: g('reserveRoomId'),
        guest_name: (g('reserveGuestName') || '').trim(),
        guest_phone: (g('reservePhone') || '').trim(),
        adults: Number(g('reserveAdults')) || 1,
        checkin_date: g('reserveCheckin'),
        checkout_date: g('reserveCheckout'),
        payment_status: g('reservePaymentStatus') || 'pending',
        breakfast_included: !!g('reserveBreakfastIncluded'),
        manual_discount_type: g('reserveManualDiscountType') || 'percent',
        manual_discount_percent: Number(g('reserveManualDiscountPercent')) || 0,
        manual_discount_fixed_input: Number(g('reserveManualDiscountFixedInput')) || 0,
    };

    // Minimal client-side validation — same required fields the
    // server endpoint checks. If anything's missing we bail without
    // queuing so the operator sees the form still-open and can fix
    // (rather than the reservation silently going into limbo).
    if (!payload.room_id || !payload.guest_name || !payload.guest_phone
        || !payload.checkin_date || !payload.checkout_date) {
        window.dispatchEvent(new CustomEvent('kv-offline-reserve-error', {
            detail: { message: 'Fill guest name, phone, room, and dates before saving offline.' },
        }));
        return;
    }
    // Wallet payment offline would need the server to atomically
    // check + spend the balance. Force it to pending so the operator
    // settles it via the reservation-detail page once it syncs.
    if (payload.payment_status === 'wallet') payload.payment_status = 'pending';

    try {
        const key = await queueReservation(payload);
        window.dispatchEvent(new CustomEvent('kv-offline-reserve-queued', { detail: { offline_key: key } }));
        // Close the sheet locally — no wire trip needed.
        try { wire.set('reserveSheetOpen', false); } catch (_) {}
        window.dispatchEvent(new CustomEvent('quick-reserve-modal-close'));
    } catch (e) {
        window.dispatchEvent(new CustomEvent('kv-offline-reserve-error', {
            detail: { message: 'Could not save locally. Please try again when back online.' },
        }));
    }
}

// Auto-drain triggers.
if (typeof window !== 'undefined') {
    window.KevernaOfflineReservations = {
        queueReservation,
        listQueued,
        drainQueue,
        forgetRecord,
        pendingCount,
    };
    window.offlineIntercept = offlineIntercept;
    window.addEventListener('online', () => drainQueue().catch(() => {}));
    // First-load drain — a page reload after being offline needs to
    // pick up whatever's still in the queue.
    if (document.readyState === 'complete') {
        drainQueue().catch(() => {});
    } else {
        window.addEventListener('load', () => drainQueue().catch(() => {}), { once: true });
    }
}
