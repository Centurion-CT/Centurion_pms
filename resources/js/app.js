import './bootstrap';
import './photo-manager';
import './pos-sw-register';
import './pos-db';
import './pos-offline';
import './pos-failed';
import './pos-cart';
import './pos-camera-scan';
import './rowclick';
import './offline-reservations';
import './platform-pulse';
import './search-cache';
import './operator-prefs';

// Boot the dashboard search cache when the layout ships a config
// blob. Feature-gated inside search-cache.js on
// localStorage.feature.search_cache; without the flag it early-
// exits without touching IndexedDB.
document.addEventListener('DOMContentLoaded', () => {
    const cfg = window.KEVERNA_SEARCH_CACHE_CONFIG;
    if (cfg && window.KevernaSearchCache?.start) {
        window.KevernaSearchCache.start(cfg);
    }
});

// Force a blur on the focused input just before any form submit so
// pending wire:model values flush to the component before wire:submit
// dispatches. Without this, hitting Enter inside a text input on a
// Livewire form fires the submit while the input is still focused, so
// its typed value never syncs and authenticate()/save() runs with the
// stale (usually empty) state.
document.addEventListener('submit', function () {
    var el = document.activeElement;
    if (el && el.tagName && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && typeof el.blur === 'function') {
        el.blur();
    }
}, true);

// Suppress Livewire's default full-screen HTML error modal on failed
// requests. Session-expired (419) is still a hard-reload because there
// is nothing the operator can do about it locally; everything else is
// routed to the sync-status pill which shows "Connection issue -
// Retry" and lets the operator recover without losing form state.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            preventDefault();
            if (status === 419) {
                window.location.reload();
            }
            // 4xx / 5xx / 0 fall through - the wire-sync-status
            // partial's own hook picks up the fail and surfaces the
            // Connection-issue toast with a Retry button.
        });
    });

    // Skeleton / shimmer overlay on every `.lp-table` while a Livewire
    // request is in flight. Attached globally so no blade needs its own
    // wire:loading directive; the .is-loading class is picked up by
    // the CSS in resources/css/app.css (kv-shimmer keyframes).
    // Debounced by 120ms so instantly-resolving requests don't flash.
    window.Livewire.hook('request', ({ component, respond }) => {
        const scope = component?.el ?? document;
        const tables = scope.querySelectorAll
            ? scope.querySelectorAll('.lp-table')
            : null;
        if (!tables || tables.length === 0) return;

        const timer = setTimeout(() => {
            tables.forEach(t => t.classList.add('is-loading'));
        }, 120);

        respond(() => {
            clearTimeout(timer);
            tables.forEach(t => t.classList.remove('is-loading'));
        });
    });
});

// Password reveal - delegated so it survives Livewire re-renders and
// wire:navigate transitions. Toggles the input type + the wrapper's
// `is-revealed` class (which CSS uses to swap the eye/slash icon).
document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    const field = button.closest('[data-password-field]');
    if (!field) return;
    const input = field.querySelector('input');
    if (!input) return;
    const hidden = input.type === 'password';
    input.type = hidden ? 'text' : 'password';
    field.classList.toggle('is-revealed', hidden);
    button.setAttribute('aria-pressed', String(hidden));
    button.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
});

// "Back" links act like the browser Back button when there's real
// history to go back to - otherwise they fall through to their href.
//
// Pages use <a class="lp-back" href="…"> to render a Back arrow whose
// href points at the parent page (All reservations, Accounting hub,
// etc.). Always navigating to that parent is wrong when the user just
// arrived from a peer page (e.g. reservation → reservation via search,
// or clicked a link on Guest Requests): they expect the back arrow to
// undo the previous step, not throw them to the section root.
//
// Heuristic for "real history":
//  - the initial page-load referrer is same-origin, OR
//  - we've done at least one wire:navigate in this session (tracked in
//    sessionStorage since document.referrer isn't updated on SPA nav).
// A fresh tab opened via bookmark / direct URL has neither, so the
// browser's history stack points at whatever came before Keverna (or
// nothing at all) - in that case we honour the href.
document.addEventListener('livewire:navigated', function () {
    const n = Number(sessionStorage.getItem('kv_nav_depth') || 0);
    sessionStorage.setItem('kv_nav_depth', String(n + 1));

    // Honour URL hash after a wire:navigate. Livewire's SPA nav
    // preserves the hash in the URL but skips the browser's native
    // scroll-into-view, so deep links like /reservations#arrivals-today
    // would land at the top of the page instead of the target section.
    // Wait one frame so the target element is present in the swapped
    // DOM before we scroll.
    if (window.location.hash) {
        requestAnimationFrame(function () {
            const target = document.querySelector(window.location.hash);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
});

document.addEventListener('click', function (event) {
    const link = event.target.closest('a.lp-back');
    if (! link) return;
    // Respect Cmd/Ctrl/Shift/middle-click intents - new tab / new window.
    if (event.button !== 0 || event.metaKey || event.ctrlKey
        || event.shiftKey || event.altKey) return;

    let sameOriginReferrer = false;
    try {
        sameOriginReferrer = !! document.referrer
            && new URL(document.referrer).origin === window.location.origin;
    } catch (_) { /* invalid referrer URL - treat as external */ }

    const spaHistory = Number(sessionStorage.getItem('kv_nav_depth') || 0) > 0;
    if ((sameOriginReferrer || spaHistory) && history.length > 1) {
        event.preventDefault();
        history.back();
    }
    // else: let the default anchor navigation happen (fallback to href).
});
