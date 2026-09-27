/*
 * IndexedDB-backed cache for the dashboard's topbar booking search.
 *
 * Feeds the search dropdown from a per-operator, per-tenant local
 * store so the operator gets instant hits without a server round-
 * trip. Backend fallback still runs when the cache misses OR when
 * they explicitly submit the form.
 *
 * Store name (per-tenant, per-operator so shared kiosks don't
 * leak):
 *   keverna-cache__<tenant_slug>__op<operator_id>
 *
 * Object stores:
 *   * bookings — keyPath id, indexes by_ref / by_name_lc / by_phone
 *   * meta     — keyPath key, holds { last_synced_at, property_id }
 *
 * Exposed as `window.KevernaSearchCache`. Feature-gated on
 * `localStorage.feature.search_cache === 'on'` so we can roll to
 * a subset of tenants first.
 */

const DB_VERSION = 1;
// Push sync via Reverb keeps the cache fresh, so the pull is now a
// heartbeat (5 min) rather than the primary sync path. If Reverb is
// unavailable the poll silently carries the load at a slower cadence.
const PULL_INTERVAL_MS = 5 * 60 * 1000;
const MAX_SEARCH_HITS = 8;
const FEATURE_KEY = 'feature.search_cache';

const state = {
    dbPromise: null,
    dbName: null,
    endpoint: null,
    propertyId: null,
    tenantId: null,
    pullTimer: null,
    echoChannel: null,
    started: false,
};

function log(...args) {
    // eslint-disable-next-line no-console
    console.info('[keverna-search-cache]', ...args);
}

function warn(...args) {
    // eslint-disable-next-line no-console
    console.warn('[keverna-search-cache]', ...args);
}

function enabled() {
    try {
        return window.localStorage?.getItem(FEATURE_KEY) === 'on';
    } catch {
        return false;
    }
}

function open() {
    if (state.dbPromise) return state.dbPromise;
    if (!('indexedDB' in window) || !state.dbName) {
        state.dbPromise = Promise.reject(new Error('IndexedDB unavailable'));
        return state.dbPromise;
    }
    state.dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(state.dbName, DB_VERSION);
        req.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('bookings')) {
                const store = db.createObjectStore('bookings', { keyPath: 'id' });
                store.createIndex('by_ref', 'booking_ref', { unique: false });
                store.createIndex('by_name_lc', 'guest_name_lc', { unique: false });
                store.createIndex('by_phone', 'guest_phone_normalised', { unique: false });
            }
            if (!db.objectStoreNames.contains('meta')) {
                db.createObjectStore('meta', { keyPath: 'key' });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return state.dbPromise;
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

async function putRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) return;
    const db = await open();
    return new Promise((resolve, reject) => {
        const t = db.transaction('bookings', 'readwrite');
        const store = t.objectStore('bookings');
        for (const row of rows) store.put(row);
        t.oncomplete = () => resolve();
        t.onerror = () => reject(t.error);
    });
}

async function deleteIds(ids) {
    if (!Array.isArray(ids) || ids.length === 0) return;
    const db = await open();
    return new Promise((resolve, reject) => {
        const t = db.transaction('bookings', 'readwrite');
        const store = t.objectStore('bookings');
        for (const id of ids) store.delete(id);
        t.oncomplete = () => resolve();
        t.onerror = () => reject(t.error);
    });
}

async function getMeta(key) {
    try {
        const store = await tx('meta');
        const row = await reqToPromise(store.get(key));
        return row?.value ?? null;
    } catch {
        return null;
    }
}

async function setMeta(key, value) {
    try {
        const store = await tx('meta', 'readwrite');
        return reqToPromise(store.put({ key, value }));
    } catch {
        // best effort
    }
}

async function getAll() {
    try {
        const store = await tx('bookings');
        return reqToPromise(store.getAll());
    } catch {
        return [];
    }
}

/**
 * Pull deltas from the server and merge into IDB. Called on start
 * (cold hydration when the store is empty), on a 30s tick while
 * the tab is visible, and on window focus.
 */
async function pullDelta() {
    if (!state.endpoint) return;

    let since = await getMeta('last_synced_at');
    const params = new URLSearchParams();
    if (since) params.set('since', since);
    if (state.propertyId != null) params.set('property_id', String(state.propertyId));

    let payload;
    try {
        const res = await fetch(state.endpoint + (params.toString() ? '?' + params.toString() : ''), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(`status ${res.status}`);
        payload = await res.json();
    } catch (e) {
        warn('pull failed, using cached copy', e.message);
        return;
    }

    const rows = Array.isArray(payload?.rows?.data) ? payload.rows.data : (payload?.rows ?? []);
    const deleted = Array.isArray(payload?.deleted_ids) ? payload.deleted_ids : [];

    try {
        await putRows(rows);
        await deleteIds(deleted);
        if (payload?.server_time) {
            await setMeta('last_synced_at', payload.server_time);
        }
        if (state.propertyId != null) {
            await setMeta('property_id', state.propertyId);
        }
        log('pulled', { new: rows.length, deleted: deleted.length, has_more: payload?.has_more });

        // If the endpoint said there's more, drain the next page
        // right away with the new since cursor.
        if (payload?.has_more) {
            await pullDelta();
        }
    } catch (e) {
        warn('write failed', e.message);
    }
}

/**
 * Public search API — called by the Alpine wrapper on every
 * keystroke. Case-insensitive, matches booking_ref prefix (KV-…)
 * or guest_name / guest_phone anywhere; guest_email prefix.
 * Bounded at MAX_SEARCH_HITS so a wide query can't stall the UI.
 */
async function search(rawTerm) {
    if (!enabled()) return [];
    const term = (rawTerm ?? '').trim();
    if (term.length < 2) return [];

    const qLower = term.toLowerCase();
    const qUpper = term.toUpperCase();
    const qDigits = term.replace(/\D+/g, '');

    const all = await getAll();
    if (all.length === 0) return [];

    const hits = [];
    for (const b of all) {
        if (b.booking_ref && b.booking_ref.startsWith(qUpper)) { hits.push(b); continue; }
        if (b.guest_name_lc && b.guest_name_lc.includes(qLower)) { hits.push(b); continue; }
        if (b.guest_email_lc && b.guest_email_lc.startsWith(qLower)) { hits.push(b); continue; }
        if (qDigits.length >= 4 && b.guest_phone_normalised && b.guest_phone_normalised.includes(qDigits)) {
            hits.push(b);
        }
        if (hits.length >= MAX_SEARCH_HITS * 4) break; // walk cap
    }

    hits.sort((a, b) => (b.checkin_date ?? '').localeCompare(a.checkin_date ?? ''));
    return hits.slice(0, MAX_SEARCH_HITS);
}

function startPollLoop() {
    if (state.pullTimer) return;
    state.pullTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            pullDelta();
        }
    }, PULL_INTERVAL_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            pullDelta();
        }
    });
    window.addEventListener('focus', () => pullDelta());
}

function stopPollLoop() {
    if (state.pullTimer) {
        clearInterval(state.pullTimer);
        state.pullTimer = null;
    }
}

/**
 * Called from the tenant layout with the operator + tenant context.
 * Idempotent — subsequent calls no-op if already started.
 */
async function start({ tenantSlug, operatorId, tenantId, propertyId, endpoint }) {
    if (state.started) return;
    if (!enabled()) {
        log('feature flag off; skipping');
        return;
    }
    if (!tenantSlug || !operatorId || !endpoint) {
        warn('start() missing config', { tenantSlug, operatorId, endpoint });
        return;
    }

    state.dbName = `keverna-cache__${tenantSlug}__op${operatorId}`;
    state.endpoint = endpoint;
    state.propertyId = propertyId ?? null;
    state.tenantId = tenantId ?? null;
    state.started = true;

    log('starting', { db: state.dbName, endpoint, propertyId, tenantId });

    // Cold hydration or delta pull, whichever the meta store implies.
    try {
        await pullDelta();
    } catch (e) {
        warn('initial pull failed', e.message);
    }

    startPollLoop();
    startEchoSubscription();
}

/**
 * Subscribe to the tenant's private channel for booking mutations.
 * Payload shape: { kind: 'upsert' | 'delete', payload: {...} }.
 * Silently degrades if window.Echo isn't ready — the pull loop is
 * still running as the safety net.
 */
async function ensureEcho(reverb) {
    if (window.Echo && typeof window.Echo.private === 'function') return true;
    if (!reverb || !reverb.key || !reverb.host) {
        log('reverb config missing, staying on pull heartbeat');
        return false;
    }
    try {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
            import('laravel-echo'),
            import('pusher-js'),
        ]);
        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: reverb.key,
            wsHost: reverb.host,
            wsPort: Number(reverb.port) || 80,
            wssPort: Number(reverb.port) || 443,
            forceTLS: (reverb.scheme || 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
        });
        return true;
    } catch (e) {
        warn('echo boot failed', e.message);
        return false;
    }
}

async function startEchoSubscription() {
    if (!state.tenantId) return;
    if (state.echoChannel) return;
    const reverb = window.KEVERNA_SEARCH_CACHE_CONFIG?.reverb;
    const ok = await ensureEcho(reverb);
    if (!ok) return;
    try {
        state.echoChannel = window.Echo.private(`tenant.${state.tenantId}.bookings`)
            .listen('.booking.search-cache.mutated', async (event) => {
                if (!event || !event.kind) return;
                try {
                    if (event.kind === 'upsert' && event.payload) {
                        // Property scoping: if this operator is looking
                        // at Property A and the mutation is for Property
                        // B, skip. Prevents cross-branch pollution on
                        // shared tenants.
                        if (state.propertyId != null
                            && event.payload.property_id != null
                            && event.payload.property_id !== state.propertyId
                        ) {
                            return;
                        }
                        await putRows([event.payload]);
                        log('push upsert', event.payload.booking_ref);
                    } else if (event.kind === 'delete' && event.payload?.id) {
                        await deleteIds([Number(event.payload.id)]);
                        log('push delete', event.payload.id);
                    }
                } catch (e) {
                    warn('push handler failed', e.message);
                }
            });
        log('subscribed to tenant.'+state.tenantId+'.bookings');
    } catch (e) {
        warn('subscription failed', e.message);
    }
}

function stopEchoSubscription() {
    if (state.echoChannel && state.tenantId && window.Echo?.leave) {
        try {
            window.Echo.leave(`private-tenant.${state.tenantId}.bookings`);
        } catch (e) {
            warn('leave failed', e.message);
        }
    }
    state.echoChannel = null;
}

async function stop() {
    stopPollLoop();
    stopEchoSubscription();
    state.started = false;
}

/**
 * Nuke the store and re-hydrate. Handy from support tooling ("try
 * clearing your local cache"). Also called when the operator flips
 * to a different property so we don't leak the previous branch's
 * bookings into the new search.
 */
async function purge() {
    if (!state.dbName) return;
    stopPollLoop();
    if (state.dbPromise) {
        try {
            const db = await state.dbPromise;
            db.close();
        } catch {
            // best effort
        }
    }
    state.dbPromise = null;
    try {
        await new Promise((resolve, reject) => {
            const req = indexedDB.deleteDatabase(state.dbName);
            req.onsuccess = () => resolve();
            req.onerror = () => reject(req.error);
            req.onblocked = () => resolve();
        });
    } catch (e) {
        warn('purge failed', e.message);
    }
    state.started = false;
}

/** Wipe every keverna-cache__ database on this browser. */
async function purgeAll() {
    try {
        const dbs = (await indexedDB.databases?.()) || [];
        await Promise.all(dbs
            .filter((d) => (d.name || '').startsWith('keverna-cache__'))
            .map((d) => new Promise((resolve) => {
                const req = indexedDB.deleteDatabase(d.name);
                req.onsuccess = () => resolve();
                req.onerror = () => resolve();
                req.onblocked = () => resolve();
            })));
        log('purged all keverna-cache__ databases');
    } catch (e) {
        warn('purgeAll failed', e.message);
    }
}

window.KevernaSearchCache = {
    start,
    stop,
    search,
    pullDelta,
    purge,
    purgeAll,
    enabled,
};
