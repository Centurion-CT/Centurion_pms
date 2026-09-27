{{-- Centre spinner overlay for Livewire wire:click requests (as
     opposed to wire:navigate, which _nav-spinner already handles).

     The guest self-service portal is a single Livewire component
     that transitions between screens via wire:click — no full-page
     navigation — so the tile taps that open Extend, Order, etc.
     never fire the livewire:navigate event. This partial hooks the
     Livewire request lifecycle instead and shows the same visual
     as _nav-spinner after a 180ms delay, so instant round-trips
     don't flash but slow ones give the guest clear feedback.

     Include just before @livewireScripts in layouts where the golden
     path is wire:click-driven (tenant-public in particular). Keep
     it OUT of layouts where wire:model.live is common — the spinner
     would flash on every input keystroke. --}}
<div id="lp-wire-spinner" aria-hidden="true"
     style="position: fixed; inset: 0; z-index: 9999;
            display: none; align-items: center; justify-content: center;
            background: rgba(20,17,12,0.28); backdrop-filter: blur(1.5px);
            pointer-events: none;">
    <div style="width: 52px; height: 52px; border-radius: 50%;
                background: #fff; box-shadow: 0 12px 40px rgba(20,17,12,0.24);
                display: flex; align-items: center; justify-content: center;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none"
             style="animation: lp-wire-spin 0.9s linear infinite;">
            <circle cx="12" cy="12" r="9" stroke="rgba(20,17,12,0.12)" stroke-width="2.5"/>
            <path d="M21 12a9 9 0 0 0-9-9" stroke="var(--color-ink, #14171A)"
                  stroke-width="2.5" stroke-linecap="round"/>
        </svg>
    </div>
</div>
<style>
    @keyframes lp-wire-spin { to { transform: rotate(360deg); } }
</style>
<script>
    (() => {
        let showTimer = null;
        let inFlight = 0;
        const el = () => document.getElementById('lp-wire-spinner');

        const show = () => {
            const n = el();
            if (n) n.style.display = 'flex';
        };
        const hide = () => {
            if (showTimer) { clearTimeout(showTimer); showTimer = null; }
            const n = el();
            if (n) n.style.display = 'none';
        };

        const arm = () => {
            // Only arm on the first in-flight request so overlapping
            // fetches don't keep resetting the delay.
            if (inFlight > 0) return;
            if (showTimer) clearTimeout(showTimer);
            showTimer = setTimeout(show, 180);
        };
        const disarm = () => {
            if (inFlight > 0) return;
            hide();
        };

        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('request', ({ respond, fail }) => {
                inFlight++;
                arm();
                respond(() => {
                    inFlight = Math.max(0, inFlight - 1);
                    disarm();
                });
                fail(() => {
                    inFlight = Math.max(0, inFlight - 1);
                    disarm();
                });
            });
        });

        // Belt + braces: if a full-page navigation happens mid-request
        // the spinner would stick. pageshow fires on fresh loads +
        // bfcache restores, so we can safely reset the overlay.
        window.addEventListener('pageshow', () => {
            inFlight = 0;
            hide();
        });
    })();
</script>
