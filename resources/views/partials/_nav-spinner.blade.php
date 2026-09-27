{{-- Centre spinner overlay for wire:navigate transitions.

     Livewire ships with a slim top progress bar for wire:navigate,
     but it's easy to miss — this partial layers a subtle centred
     spinner on top so the "something's happening" signal is
     unmissable. Include it inside any layout whose pages use
     wire:navigate, just before @livewireScripts.

     Timing:
       - livewire:navigate  → waits 180ms before showing (so
                              instant SPA transitions don't flash)
       - livewire:navigated → hides + cancels any pending show
       - pageshow (bfcache) → safety-net hide

     Fully self-contained: pure HTML + inline CSS + one small IIFE.
     No global namespace pollution. --}}
<div id="lp-nav-spinner" aria-hidden="true"
     style="position: fixed; inset: 0; z-index: 9999;
            display: none; align-items: center; justify-content: center;
            background: rgba(20,17,12,0.28); backdrop-filter: blur(1.5px);
            pointer-events: none;">
    <div style="width: 52px; height: 52px; border-radius: 50%;
                background: #fff; box-shadow: 0 12px 40px rgba(20,17,12,0.24);
                display: flex; align-items: center; justify-content: center;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
             style="animation: lp-nav-spin 0.9s linear infinite;">
            <circle cx="12" cy="12" r="9" stroke="rgba(20,17,12,0.12)" stroke-width="2.5"/>
            <path d="M21 12a9 9 0 0 0-9-9" stroke="var(--color-ink, #14171A)"
                  stroke-width="2.5" stroke-linecap="round"/>
        </svg>
    </div>
</div>
<style>
    @keyframes lp-nav-spin { to { transform: rotate(360deg); } }
</style>
<script>
    (() => {
        let showTimer = null;
        const el = () => document.getElementById('lp-nav-spinner');

        const show = () => {
            const n = el();
            if (n) n.style.display = 'flex';
        };
        const hide = () => {
            if (showTimer) { clearTimeout(showTimer); showTimer = null; }
            const n = el();
            if (n) n.style.display = 'none';
        };

        document.addEventListener('livewire:navigate', () => {
            if (showTimer) clearTimeout(showTimer);
            showTimer = setTimeout(show, 180);
        });
        document.addEventListener('livewire:navigated', hide);
        document.addEventListener('livewire:navigate.failed', hide);
        // If a full-page reload triggers mid-navigation the spinner
        // would stick — pageshow fires on both fresh loads + bfcache
        // restores so we can safely reset the overlay.
        window.addEventListener('pageshow', hide);
    })();
</script>
