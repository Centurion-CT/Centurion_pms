{{-- Standalone reserve action sheet. Pure Alpine visibility.

     Opens from the "+ Reserve room" header button (empty state), from
     a calendar drag-selection (dates prefilled server-side, we open
     concurrently on the client), and closes on backdrop / Escape /
     Cancel, no server round-trip for any of those.

     Only wire:submit hits the server. For the calendar-single-click
     flow, day-detail hosts its own inline reserve form (shared
     fields partial + submitReserve action) so we don't stack modals.
--}}
<template x-teleport="body">
<div class="lp-page" style="display: contents;">
    {{-- Listens for both the reservations-page-specific event (from
         the calendar drag / + Reserve button) and the generic
         quick-reserve event (from the Bookings page's + Add manual
         booking button) so the same modal template serves both hosts. --}}
    <div x-data="{ open: false }"
         x-on:quick-reserve-modal-open.window="open = true"
         x-on:quick-reserve-modal-close.window="open = false"
         x-on:reservations-modal-open.window="if ($event.detail?.name === 'reserve') { open = true; }"
         x-on:reservations-modal-close.window="if ($event.detail?.name === 'reserve') { open = false; }"
         x-show="open"
         x-cloak
         @click.self="open = false"
         @keydown.escape.window="open = false"
         data-testid="reserve-sheet"
         class="lp-sheet-backdrop">
        <div class="lp-sheet-panel">
            <div class="lp-sheet-head">
                <h2 class="lp-sheet-title">Reserve a room</h2>
                <button type="button" @click="open = false"
                        aria-label="Close" class="lp-sheet-close">×</button>
            </div>
            <p class="text-[12.5px]" style="color: var(--color-ink-mute); margin: 0 0 14px;">
                Quick booking for walk-ins &amp; phone calls. Start with the phone number.
            </p>

            <form wire:submit="submitReserve" style="display: grid; gap: 12px;"
                  x-on:submit.capture="offlineIntercept($event, $wire)"
                  data-testid="reserve-form">
                @include('livewire.dashboard.reservations._modals._reserve-form-fields')

                {{-- Offline notice — shows above the buttons when the
                     browser reports it's offline so the operator knows
                     the click will save locally, not submit live. --}}
                <div x-data="{ off: !navigator.onLine }"
                     x-init="window.addEventListener('online', () => off = false);
                             window.addEventListener('offline', () => off = true);"
                     x-show="off" x-cloak
                     data-testid="reserve-offline-hint"
                     class="text-[12px]"
                     style="padding: 8px 12px; border-radius: 8px;
                            background: #FEF3C7; color: #78350F;
                            border: 1px solid #FCD34D;">
                    You're offline. This reservation will be saved locally and synced automatically when the connection returns.
                </div>

                <div class="flex justify-between items-center gap-3 pt-2"
                     style="border-top: 1px solid var(--color-line-soft);">
                    <a href="{{ route('tenant.bookings.create') }}" wire:navigate
                       class="lp-mono text-[11.5px]"
                       style="color: var(--color-accent-deep); text-decoration: underline;"
                       data-testid="reserve-full-form-link">
                        Advanced (discount, package, customer lookup) &rarr;
                    </a>
                    <div class="flex gap-3">
                        <button type="button" @click="open = false" class="lp-btn">Cancel</button>
                        <button type="submit" class="lp-btn lp-btn--primary" data-testid="reserve-submit">
                            <span x-show="!navigator.onLine" x-cloak>Save offline</span>
                            <span x-show="navigator.onLine">Reserve</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</template>
