/*
 * Keverna POS service worker
 * --------------------------
 *
 * Goal: a paired phone can open /dashboard/pos/{outlet} while offline,
 * see the last-loaded product catalog, add items to cart, and complete
 * a sale into the offline queue. When the network returns, the queue
 * drains via the existing pos-offline.js pipeline.
 *
 * Design notes:
 *  - Vite hashed assets under /build/* are content-addressed, so
 *    cache-first-forever is safe. A new deploy generates new URLs;
 *    the old cache entries just become garbage collected on the next
 *    version bump.
 *  - HTML navigations use network-first with a 3 s timeout. If the
 *    network is up but slow, the operator still gets the fresh page
 *    within a beat; if it's truly down, they get the cached shell.
 *  - /dashboard/pos/{outlet}/products.json is stale-while-revalidate:
 *    render immediately from cache, refresh in the background.
 *  - POSTs are never intercepted. The offline queue (pos-offline.js)
 *    already handles that path end-to-end; a SW that swallowed POSTs
 *    would fight it.
 *  - The SW never signs requests. CSRF handling stays in the page-side
 *    JS which reads the <meta name="csrf-token"> tag.
 *
 * Bump the VERSION whenever the cache shape changes — the `activate`
 * handler prunes anything that doesn't match.
 */

const VERSION = 'v1';
const SHELL_CACHE = `kv-pos-shell-${VERSION}`;
const PAGES_CACHE = `kv-pos-pages-${VERSION}`;
const DATA_CACHE  = `kv-pos-data-${VERSION}`;
const MISC_CACHE  = `kv-pos-misc-${VERSION}`;
const ALL_CACHES  = [SHELL_CACHE, PAGES_CACHE, DATA_CACHE, MISC_CACHE];

const SHELL_ASSETS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/pos-192.png',
    '/icons/pos-512.png',
    '/icons/pos-maskable-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(SHELL_ASSETS)),
    );
    // Skip waiting so a fresh SW activates immediately on next reload.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((k) => k.startsWith('kv-pos-') && !ALL_CACHES.includes(k))
                    .map((k) => caches.delete(k)),
            );
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Never intercept non-GET. The offline queue owns POSTs, and
    // intercepting Livewire's POST /livewire/update would break the
    // online path entirely.
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Only handle same-origin traffic. Google Fonts / CDNs fall through.
    if (url.origin !== self.location.origin) return;

    // Heartbeat: pure network, no caching. Failure surfaces via the
    // usual offline detection in pos-offline.js.
    if (url.pathname === '/dashboard/pos/heartbeat') return;

    // Livewire update wire: hands-off; Livewire falls back to its own
    // error surface if the network is down (page-side JS handles it).
    if (url.pathname.startsWith('/livewire/')) return;

    // Vite hashed assets — cache-first, forever.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(req, SHELL_CACHE));
        return;
    }

    // Manifest + icons.
    if (SHELL_ASSETS.includes(url.pathname)) {
        event.respondWith(cacheFirst(req, SHELL_CACHE));
        return;
    }

    // Product feed — stale-while-revalidate keeps offline browsing
    // instant while the background fetch refreshes stock counts.
    if (url.pathname.match(/^\/dashboard\/pos\/[^/]+\/products\.json$/)) {
        event.respondWith(staleWhileRevalidate(req, DATA_CACHE));
        return;
    }

    // POS navigations — network-first with cached-shell fallback so
    // the terminal opens even when the phone is fully offline.
    if (req.mode === 'navigate' && url.pathname.startsWith('/dashboard/pos')) {
        event.respondWith(navigationNetworkFirst(req));
        return;
    }

    // Everything else same-origin GET: network-first, cache the good
    // ones so a repeat visit works offline too. Skip anything we
    // can't be sure is idempotent.
    if (req.mode === 'navigate') {
        event.respondWith(navigationNetworkFirst(req));
        return;
    }

    event.respondWith(networkFirst(req, MISC_CACHE));
});

async function cacheFirst(req, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(req);
    if (cached) return cached;
    const res = await fetch(req);
    if (res.ok) cache.put(req, res.clone());
    return res;
}

async function networkFirst(req, cacheName) {
    const cache = await caches.open(cacheName);
    try {
        const res = await fetch(req);
        if (res.ok) cache.put(req, res.clone());
        return res;
    } catch (_e) {
        const cached = await cache.match(req);
        if (cached) return cached;
        throw _e;
    }
}

async function staleWhileRevalidate(req, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(req);
    const network = fetch(req)
        .then((res) => {
            if (res.ok) cache.put(req, res.clone());
            return res;
        })
        .catch(() => null);
    return cached || (await network) || Response.error();
}

async function navigationNetworkFirst(req) {
    const cache = await caches.open(PAGES_CACHE);
    try {
        const res = await fetchWithTimeout(req, 3000);
        if (res && res.ok) cache.put(req, res.clone());
        return res;
    } catch (_e) {
        const cached = await cache.match(req);
        if (cached) return cached;
        // Last resort — the pre-cached offline shell.
        const offline = await caches.match('/offline.html');
        if (offline) return offline;
        return new Response('Offline', { status: 503, statusText: 'Offline' });
    }
}

function fetchWithTimeout(req, ms) {
    return new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('timeout')), ms);
        fetch(req).then(
            (res) => { clearTimeout(t); resolve(res); },
            (err) => { clearTimeout(t); reject(err); },
        );
    });
}

// Listen for a message from the page — `postMessage({type: 'skipWaiting'})`
// after a new SW install lets the page trigger the swap without a reload.
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'skipWaiting') {
        self.skipWaiting();
    }
});
