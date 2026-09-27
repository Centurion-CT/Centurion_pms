{{-- Universal "Back" affordance. Fires window.history.back() so the
     operator lands wherever they were before this page. Replaces the
     old per-page anchors like "All customers" / "All rooms" which
     forced a hardcoded destination even when the operator arrived
     via search or a deep link.

     Rendered as a <button> so it never fights wire:navigate or leaves
     a stray href in the address bar. CSS in resources/css/app.css
     resets the button chrome so .lp-back looks identical to the old
     anchor. --}}
<button type="button"
        onclick="window.history.back()"
        class="lp-back"
        data-testid="back-button"
        aria-label="Back">
    <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6H3M5.5 3L3 6l2.5 3"/></svg>
    Back
</button>
