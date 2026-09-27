// Platform-pulse subscriber. Powers the live counter tick on the
// marketing kobo widget + the floating "just received" toast on
// public surfaces (welcome, pricing, marketplace stays).
//
// Bootstraps only when the DOM opts in via `<meta name="kv-pulse"
// content="on">` so the operator dashboard doesn't open a socket
// it never uses. Uses laravel-echo over the Reverb (Pusher) protocol.

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const meta = document.querySelector('meta[name="kv-pulse"]');
if (meta && meta.getAttribute('content') === 'on') {
    boot();
}

function boot() {
    const key = readMeta('kv-reverb-key');
    const host = readMeta('kv-reverb-host');
    const port = readMeta('kv-reverb-port');
    const scheme = readMeta('kv-reverb-scheme') || 'https';

    if (!key || !host) {
        // Missing config means the app isn't wired for Reverb yet.
        // The one-time reveal animation on the counter still runs;
        // silent-fail keeps marketing pages resilient.
        return;
    }

    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: Number(port) || 80,
        wssPort: Number(port) || 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });

    window.Echo
        .channel('platform-pulse')
        .listen('.payment.pulse', (payload) => {
            handlePulse(payload);
        });
}

function readMeta(name) {
    const el = document.querySelector(`meta[name="${name}"]`);
    return el ? el.getAttribute('content') : null;
}

// ── Counter tick ──────────────────────────────────────────────────
// Adds the padded increment to whatever the counter currently shows
// (post one-time reveal). Smooth ~600ms ease-out landing on the new
// total so a burst of pulses looks alive without the digits flashing
// past faster than the eye can follow.

function bumpCounter(delta) {
    const el = document.querySelector('[data-kobo-value]');
    if (!el || !Number.isFinite(delta) || delta <= 0) return;

    const block = el.closest('[data-kobo-target]');
    if (!block) return;

    const current = parseInt(block.getAttribute('data-kobo-target'), 10) || 0;
    const next = current + delta;
    block.setAttribute('data-kobo-target', String(next));

    const reduced = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) {
        el.textContent = format(next);
        return;
    }

    const startVal = current;
    const endVal = next;
    const duration = 600;
    let start = null;

    function step(now) {
        if (start === null) start = now;
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - t, 3);
        el.textContent = format(startVal + (endVal - startVal) * eased);
        if (t < 1) requestAnimationFrame(step);
        else el.textContent = format(endVal);
    }
    requestAnimationFrame(step);
}

function format(n) {
    const s = String(Math.floor(n));
    let out = '';
    for (let i = s.length; i > 0; i -= 3) {
        out = s.slice(Math.max(0, i - 3), i) + (out ? ',' : '') + out;
    }
    return '₦' + out;
}

// ── Toast queue ──────────────────────────────────────────────────
// Server-side jitter would need a queued listener; keeping it client
// side is simpler and lets us cap frequency so a burst of ten
// payments doesn't stack ten toasts on screen. Show one every ~2.4s,
// drop anything older than 5 pending so the queue never runs away.

const queue = [];
let running = false;
const MIN_GAP_MS = 2400;
const MAX_QUEUE = 5;
const DWELL_MS = 4200;
const LABELS = {
    stays: 'stays',
    outlet: 'food & drink',
    workspace: 'workspaces',
    extras: 'extras',
    ar: 'corporate',
};

function handlePulse(payload) {
    if (!payload || typeof payload.display !== 'number') return;
    bumpCounter(Number(payload.amount) || 0);
    enqueueToast(Number(payload.display), String(payload.category || ''));
}

function enqueueToast(display, category) {
    if (queue.length >= MAX_QUEUE) queue.shift();
    queue.push({ display, category });
    if (!running) drain();
}

function drain() {
    const next = queue.shift();
    if (!next) { running = false; return; }
    running = true;
    showToast(next.display, next.category);
    setTimeout(drain, MIN_GAP_MS);
}

function showToast(display, category) {
    const host = document.getElementById('kv-pulse-toast');
    if (!host) return;

    const label = LABELS[category] || 'the platform';
    const el = document.createElement('div');
    el.className = 'kv-pulse-toast-item';
    el.setAttribute('role', 'status');
    el.innerHTML =
        '<span class="kv-pulse-dot" aria-hidden="true"></span>'
        + '<span class="kv-pulse-amount">' + format(display) + '</span>'
        + '<span class="kv-pulse-sep" aria-hidden="true">&middot;</span>'
        + '<span class="kv-pulse-label">just received &middot; ' + label + '</span>';

    host.appendChild(el);

    // Kick reflow so the enter animation is picked up.
    void el.offsetWidth;
    el.classList.add('is-in');

    setTimeout(() => {
        el.classList.remove('is-in');
        el.classList.add('is-out');
        setTimeout(() => el.remove(), 380);
    }, DWELL_MS);
}
