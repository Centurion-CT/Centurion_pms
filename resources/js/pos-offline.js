/*
 * POS offline queue
 * -----------------
 *
 * The Livewire terminal is online-first — building the cart requires
 * round-trips. But once the operator hits "Complete", they've committed
 * to the transaction and money's already changing hands. If the network
 * drops right then, we can't lose the sale.
 *
 * This module:
 *   1. Watches connectivity (navigator.onLine + periodic heartbeat).
 *   2. Publishes queue state via a MutationObserver-friendly attribute
 *      on <html> so the Blade layer can style an offline banner + queue
 *      count without a re-render.
 *   3. Exposes window.KevernaPos.enqueue(payload) for the terminal
 *      to call when a Livewire "complete" request fails, or when the
 *      operator taps a fallback offline button.
 *   4. Drains the queue on `online`, on load, and on manual retry.
 *
 * Persistence is localStorage keyed under KEY. Each queued item has a
 * `client_key` (crypto.randomUUID) so the server can dedupe if a POST
 * succeeds but the JS never sees the response.
 */

const KEY = 'keverna_pos_queue_v1';
const HEARTBEAT_MS = 20_000;

function readQueue() {
    try {
        const raw = localStorage.getItem(KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function writeQueue(items) {
    try {
        localStorage.setItem(KEY, JSON.stringify(items));
    } catch {
        // Storage full or blocked — nothing we can do; surface as a warning below.
        console.warn('[keverna-pos] Failed to write offline queue to localStorage.');
    }
    updateHtmlAttrs(items.length);
    dispatchEvent(new CustomEvent('keverna-pos:queue-changed', { detail: { count: items.length } }));
}

function updateHtmlAttrs(count) {
    const html = document.documentElement;
    html.dataset.posQueueCount = String(count);
    if (count > 0) {
        html.dataset.posQueued = 'true';
    } else {
        delete html.dataset.posQueued;
    }
    // Reflect count + status into the on-page banner without a Livewire
    // re-render — cheap DOM sync, avoids waking the server just to
    // change a label.
    document.querySelectorAll('.lp-pos__conn-queue').forEach(el => {
        const label = count === 0 ? '' : (count + ' queued');
        el.textContent = label;
    });
    document.querySelectorAll('.lp-pos__conn-label').forEach(el => {
        const online = document.documentElement.dataset.posOnline !== 'false';
        el.textContent = online
            ? (count > 0 ? 'Syncing queued sales…' : '')
            : 'Offline — sales will queue and send on reconnect.';
    });
}

function updateOnlineAttr(online) {
    document.documentElement.dataset.posOnline = online ? 'true' : 'false';
    dispatchEvent(new CustomEvent('keverna-pos:online-changed', { detail: { online } }));
}

let effectiveOnline = navigator.onLine;

function csrfToken() {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
}

async function heartbeat() {
    if (!navigator.onLine) {
        if (effectiveOnline) {
            effectiveOnline = false;
            updateOnlineAttr(false);
        }
        return;
    }
    try {
        const res = await fetch('/dashboard/pos/heartbeat', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const nextOnline = res.ok;
        if (nextOnline !== effectiveOnline) {
            effectiveOnline = nextOnline;
            updateOnlineAttr(effectiveOnline);
            if (effectiveOnline) drain();
        }
    } catch {
        if (effectiveOnline) {
            effectiveOnline = false;
            updateOnlineAttr(false);
        }
    }
}

async function postOne(item) {
    const res = await fetch('/dashboard/pos/queue', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(item),
    });
    if (res.status >= 500 || res.status === 0) {
        throw new Error('server_transient');
    }
    // 401 = paired device revoked (or session died). Do NOT retry —
    // every future flush would 401 too. Signal upward so drain() can
    // move everything to the failed queue and show an overlay.
    if (res.status === 401) {
        return { dropped: true, revoked: true, reason: 'device_revoked', status: 401 };
    }
    if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        return { dropped: true, reason: body.error ?? 'unknown', status: res.status };
    }
    return { dropped: false };
}

let draining = false;
let deviceRevoked = false;

async function drain() {
    if (draining) return;
    if (deviceRevoked) return;
    if (!effectiveOnline) return;
    draining = true;
    try {
        let queue = readQueue();
        while (queue.length > 0) {
            const item = queue[0];
            try {
                const result = await postOne(item);
                queue = queue.slice(1);
                writeQueue(queue);
                if (result?.dropped && !result.revoked) {
                    // Permanent failure — hand to the failed store so the
                    // operator sees WHY and can retry / dismiss instead of
                    // losing the sale silently.
                    if (window.KevernaPosDB) {
                        await window.KevernaPosDB.recordFailed(item, result.reason, result.status);
                        dispatchEvent(new CustomEvent('keverna-pos:failed-changed'));
                    }
                    console.warn('[keverna-pos] Sale rejected, moved to Needs attention.', item.client_key, result.reason);
                }
                if (result?.revoked) {
                    // Device was unlinked. Everything else in the queue
                    // will 401 too — move them all and stop.
                    deviceRevoked = true;
                    if (window.KevernaPosDB) {
                        for (const q of [item, ...queue]) {
                            await window.KevernaPosDB.recordFailed(q, 'device_revoked', 401);
                        }
                    }
                    writeQueue([]);
                    dispatchEvent(new CustomEvent('keverna-pos:failed-changed'));
                    dispatchEvent(new CustomEvent('keverna-pos:device-revoked'));
                    break;
                }
            } catch (e) {
                // Transient — stop draining and try again next tick.
                console.info('[keverna-pos] Transient failure while draining; will retry.', e.message);
                break;
            }
        }
    } finally {
        draining = false;
    }
}

function enqueue(payload) {
    const item = { ...payload, client_key: payload.client_key ?? cryptoUuid() };
    const queue = readQueue();
    // Dedupe by client_key so a double-tap doesn't queue twice.
    if (queue.some(q => q.client_key === item.client_key)) return item;
    queue.push(item);
    writeQueue(queue);
    // Fire-and-forget attempt in case we're actually online.
    drain();
    return item;
}

function cryptoUuid() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    // Fallback — good-enough randomness.
    return 'q-' + Math.random().toString(36).slice(2) + '-' + Date.now();
}

// ── Public API ─────────────────────────────────────────────────────
window.KevernaPos = {
    enqueue,
    drain,
    clear: () => writeQueue([]),
    getQueue: () => readQueue().slice(),
    isEffectivelyOnline: () => effectiveOnline,
    isDeviceRevoked: () => deviceRevoked,
};

// ── Wire up events ────────────────────────────────────────────────
window.addEventListener('online', () => { heartbeat(); });
window.addEventListener('offline', () => {
    effectiveOnline = false;
    updateOnlineAttr(false);
});

document.addEventListener('DOMContentLoaded', () => {
    updateHtmlAttrs(readQueue().length);
    updateOnlineAttr(effectiveOnline);
    // The connectivity heartbeat only belongs on the POS terminal + kiosk.
    // On every other page (marketing home, tenant login, dashboard) the
    // /dashboard/pos/heartbeat endpoint 404s or 401s, which used to spam
    // the console + the server access log every 20s.
    if (!document.querySelector('[data-testid="pos-terminal"], [data-testid="pos-kiosk-shell"]')) {
        return;
    }
    heartbeat();
    setInterval(heartbeat, HEARTBEAT_MS);
});

// Livewire hook — when a "complete" wire method fails with a network
// error, we don't get a status code; the request just never lands.
// The terminal wraps its Complete button in a listener that pushes
// the payload here on failure. See resources/views/livewire/dashboard/pos/terminal.blade.php.
