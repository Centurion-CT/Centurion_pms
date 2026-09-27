/*
 * Registers the POS service worker (public/sw.js) whenever the page
 * loads within the /dashboard/pos scope. Elsewhere in the app we don't
 * want the SW active — non-POS pages don't need offline caching and it
 * would just eat storage.
 *
 * On update: the new SW installs in the background. On next reload it
 * activates. We don't force skipWaiting from the page — the operator
 * shouldn't have their cart wiped by a background update mid-shift.
 */

if ('serviceWorker' in navigator) {
    const inPosScope = location.pathname.startsWith('/dashboard/pos');
    if (inPosScope) {
        window.addEventListener('load', () => {
            navigator.serviceWorker
                .register('/sw.js', { scope: '/dashboard/pos' })
                .then((reg) => {
                    // Log so it's visible in devtools when someone's debugging.
                    if (reg.installing) console.info('[keverna-pos] SW installing');
                    else if (reg.waiting) console.info('[keverna-pos] SW waiting');
                    else if (reg.active) console.info('[keverna-pos] SW active');
                })
                .catch((err) => {
                    console.warn('[keverna-pos] SW registration failed', err);
                });
        });
    }
}
