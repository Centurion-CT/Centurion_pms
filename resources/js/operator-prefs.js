/*
 * IndexedDB-backed cache for per-operator UI preferences.
 *
 * Right now the only key is `sidebar_variant` ("tiles" | "list").
 * More can slot in without a schema bump — the store's keyPath is
 * the pref name and the value shape is `{ key, value, updated_at }`.
 *
 * DB is source of truth: the server always renders the operator's
 * picked variant into `<html data-sidebar-variant>` on first paint,
 * so this cache is a nice-to-have write-through mirror, not the
 * authoritative store. If IndexedDB is unavailable (Safari private
 * mode, ancient browsers) the endpoint still works — the pick just
 * takes a slightly longer round-trip.
 *
 * Exposed as `window.KevernaOperatorPrefs`.
 */

const DB_NAME = 'keverna-operator-prefs';
const DB_VERSION = 1;
const STORE = 'preferences';

let dbPromise = null;

function openDB() {
    if (dbPromise) return dbPromise;
    if (typeof indexedDB === 'undefined') {
        return Promise.reject(new Error('IndexedDB unavailable'));
    }
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, DB_VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (! db.objectStoreNames.contains(STORE)) {
                db.createObjectStore(STORE, { keyPath: 'key' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

async function get(key) {
    try {
        const db = await openDB();
        return new Promise((resolve) => {
            const tx = db.transaction(STORE, 'readonly');
            const req = tx.objectStore(STORE).get(key);
            req.onsuccess = () => resolve(req.result?.value ?? null);
            req.onerror = () => resolve(null);
        });
    } catch {
        return null;
    }
}

async function set(key, value) {
    try {
        const db = await openDB();
        return new Promise((resolve) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put({
                key,
                value,
                updated_at: new Date().toISOString(),
            });
            tx.oncomplete = () => resolve(true);
            tx.onerror    = () => resolve(false);
        });
    } catch {
        return false;
    }
}

/**
 * Save the sidebar variant end-to-end: apply immediately to the
 * live DOM, cache locally, then persist to the server. Returns a
 * promise that resolves once the network round-trip finishes so
 * callers can fire their success/error notify off it.
 *
 * We DON'T revert the DOM if the server call fails — the operator
 * already sees the new layout and they can retry from the same UI.
 * A hard revert would surprise them mid-scroll.
 */
async function saveSidebarVariant(variant) {
    if (variant !== 'tiles' && variant !== 'list') {
        throw new Error('Unknown sidebar variant: ' + variant);
    }

    // Immediate visual flip.
    document.documentElement.setAttribute('data-sidebar-variant', variant);
    // Write-through cache (fire-and-forget — the network call is the
    // one that has to succeed, IndexedDB is a courtesy).
    set('sidebar_variant', variant);

    const token = document.querySelector('meta[name="csrf-token"]')?.content
                ?? document.querySelector('input[name="_token"]')?.value
                ?? '';

    const res = await fetch('/dashboard/preferences/sidebar-variant', {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ variant }),
    });

    if (! res.ok) {
        throw new Error('Server refused to save (HTTP ' + res.status + ')');
    }
    return res.json();
}

window.KevernaOperatorPrefs = {
    get,
    set,
    saveSidebarVariant,
};
