/*
 * IndexedDB wrapper for the POS offline cache.
 *
 * Stores:
 *   - products      keyPath id, indexes by_outlet / by_barcode / by_sku
 *   - meta          keyPath key         — {last_synced_at, outlet_id}
 *   - failed_sales  keyPath client_key  — sales the server rejected on flush
 *
 * The Alpine cart store consumes this via window.KevernaPosDB. We keep the
 * API small and Promise-based so callers don't have to touch the raw
 * IndexedDB event soup. All calls degrade gracefully — if IndexedDB is
 * blocked (private mode on old Safari, storage full), reads return empty
 * arrays and writes silently no-op. The POS still renders from whatever
 * was server-inlined at page load.
 */

const DB_NAME = 'keverna_pos';
const DB_VERSION = 1;

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
            if (!db.objectStoreNames.contains('products')) {
                const store = db.createObjectStore('products', { keyPath: 'id' });
                store.createIndex('by_outlet', 'outlet_id', { unique: false });
                store.createIndex('by_barcode', 'barcode', { unique: false });
                store.createIndex('by_sku', 'sku', { unique: false });
            }
            if (!db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta', { keyPath: 'key' });
            }
            if (!db.objectStoreNames.contains('failed_sales')) {
                db.createObjectStore('failed_sales', { keyPath: 'client_key' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

function tx(storeName, mode = 'readonly') {
    return open().then((db) => db.transaction(storeName, mode).objectStore(storeName));
}

function reqToPromise(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function putAll(storeName, items) {
    const db = await open();
    return new Promise((resolve, reject) => {
        const t = db.transaction(storeName, 'readwrite');
        const store = t.objectStore(storeName);
        for (const item of items) store.put(item);
        t.oncomplete = () => resolve();
        t.onerror = () => reject(t.error);
    });
}

async function clearOutletProducts(outletId) {
    const db = await open();
    return new Promise((resolve, reject) => {
        const t = db.transaction('products', 'readwrite');
        const store = t.objectStore('products');
        const idx = store.index('by_outlet');
        const cursorReq = idx.openCursor(IDBKeyRange.only(outletId));
        cursorReq.onsuccess = (event) => {
            const cursor = event.target.result;
            if (cursor) {
                cursor.delete();
                cursor.continue();
            }
        };
        t.oncomplete = () => resolve();
        t.onerror = () => reject(t.error);
    });
}

/**
 * Pull the outlet's product list from the server and replace the local
 * copy. Called on terminal load and after each successful sync. If the
 * fetch fails we keep whatever's already cached.
 */
async function syncProducts(outletId) {
    // Guard: outletId can be NaN or 0 if the Alpine store's init() never
    // ran (a stale listener firing on a non-POS page after wire:navigate
    // out of the terminal). Refuse to build a URL like
    // /dashboard/pos/NaN/products.json + refuse to open an IDBKeyRange
    // with NaN, which throws DataError. Empty array is the safe default.
    if (!Number.isFinite(outletId) || outletId <= 0) {
        return [];
    }
    let payload;
    try {
        const res = await fetch(`/dashboard/pos/${outletId}/products.json`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(`status ${res.status}`);
        payload = await res.json();
    } catch (e) {
        console.info('[keverna-pos-db] products sync failed, using cached copy', e.message);
        return getProducts(outletId);
    }

    const products = (payload.products || []).map((p) => ({
        id: Number(p.id),
        outlet_id: Number(p.outlet_id),
        sku: p.sku ?? null,
        barcode: p.barcode ?? null,
        name: String(p.name),
        price: Number(p.price),
        track_stock: Boolean(p.track_stock),
        quantity_on_hand: Number(p.quantity_on_hand),
        updated_at: p.updated_at ?? null,
    }));

    try {
        await clearOutletProducts(outletId);
        if (products.length > 0) await putAll('products', products);
        await putAll('meta', [{
            key: `products:outlet:${outletId}`,
            last_synced_at: payload.synced_at ?? new Date().toISOString(),
            outlet_id: outletId,
            count: products.length,
        }]);
    } catch (e) {
        console.warn('[keverna-pos-db] products write failed', e.message);
    }

    return products;
}

/** Optionally seed the cache from a script tag embedded in the page. */
async function seedProducts(outletId, products) {
    if (!Array.isArray(products) || products.length === 0) return;
    try {
        await clearOutletProducts(outletId);
        await putAll('products', products.map((p) => ({
            id: Number(p.id),
            outlet_id: Number(p.outlet_id ?? outletId),
            sku: p.sku ?? null,
            barcode: p.barcode ?? null,
            name: String(p.name),
            price: Number(p.price),
            track_stock: Boolean(p.track_stock),
            quantity_on_hand: Number(p.quantity_on_hand ?? 0),
            updated_at: p.updated_at ?? null,
        })));
    } catch (e) {
        console.info('[keverna-pos-db] products seed failed', e.message);
    }
}

async function getProducts(outletId) {
    // IDBKeyRange.only(NaN) throws DataError; refuse invalid keys upstream
    // rather than crash the cursor + the Alpine store that awaits it.
    if (!Number.isFinite(outletId) || outletId <= 0) return [];
    try {
        const store = await tx('products');
        return new Promise((resolve) => {
            const results = [];
            const idx = store.index('by_outlet');
            const cursorReq = idx.openCursor(IDBKeyRange.only(outletId));
            cursorReq.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    results.push(cursor.value);
                    cursor.continue();
                } else {
                    results.sort((a, b) => a.name.localeCompare(b.name));
                    resolve(results);
                }
            };
            cursorReq.onerror = () => resolve([]);
        });
    } catch {
        return [];
    }
}

/**
 * Barcode / SKU lookup used by the scanner while offline. Returns
 * the product or null. Matches on barcode first, then SKU as fallback.
 */
async function findByCode(outletId, code) {
    if (!code) return null;
    const products = await getProducts(outletId);
    return products.find((p) => p.barcode === code) ?? products.find((p) => p.sku === code) ?? null;
}

/* ── Failed sales store ─────────────────────────────────────────── */

async function recordFailed(item, reason, httpStatus) {
    try {
        await putAll('failed_sales', [{
            client_key: item.client_key,
            payload: item,
            reason: reason || 'unknown',
            http_status: httpStatus ?? null,
            failed_at: new Date().toISOString(),
        }]);
    } catch (e) {
        console.info('[keverna-pos-db] failed store write failed', e.message);
    }
}

async function getFailed() {
    try {
        const store = await tx('failed_sales');
        return reqToPromise(store.getAll());
    } catch {
        return [];
    }
}

async function removeFailed(clientKey) {
    try {
        const store = await tx('failed_sales', 'readwrite');
        return reqToPromise(store.delete(clientKey));
    } catch {
        // best effort
    }
}

async function clearFailed() {
    try {
        const store = await tx('failed_sales', 'readwrite');
        return reqToPromise(store.clear());
    } catch {
        // best effort
    }
}

window.KevernaPosDB = {
    open,
    syncProducts,
    seedProducts,
    getProducts,
    findByCode,
    recordFailed,
    getFailed,
    removeFailed,
    clearFailed,
};
