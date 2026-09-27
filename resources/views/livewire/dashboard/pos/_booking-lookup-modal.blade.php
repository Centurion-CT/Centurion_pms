{{-- Charge-to-room guest picker modal. Rendered once at the terminal
     level (not inside the payment sheet) so it can slide up over the
     whole terminal, needed once the in-house roster grows past a
     handful and the inline picker becomes a scroll well.

     Teleported to <body> for the same reason as the payment sheet
     modal, the kiosk shell creates a containing block that would
     pin position:fixed to the cart column on desktop.

     Full-screen bottom sheet on phones, centered card on desktop.
     Auto-closes when Livewire dispatches `pos-booking-resolved` (either
     from the list tap or the manual ref lookup), wiring lives in the
     Alpine store's init handler. --}}
<template x-teleport="body">
{{-- .lp-page wrapper restores CSS scope for .lp-btn / .lp-input after
     the teleport; display:contents keeps the wrapper out of layout. --}}
<div class="lp-page" style="display: contents;">
<div x-show="$store.pos.bookingModalOpen"
     x-cloak
     x-data="{ query: '' }"
     x-init="$watch('$store.pos.bookingModalOpen', open => { if (open) { query = ''; $nextTick(() => $refs.searchInput?.focus()); } })"
     @click.self="$store.pos.closeBookingModal()"
     @keydown.escape.window="$store.pos.closeBookingModal()"
     data-testid="pay-booking-modal"
     class="lp-booking-modal-backdrop">
    <div class="lp-booking-modal-panel">

        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px;">
            <h2 class="" style="font-family: var(--font-body); letter-spacing: -0.01em; margin: 0; font-size: 18px; font-weight: 600; color: var(--color-ink);">
                Charge to which guest?
            </h2>
            <button type="button"
                    @click="$store.pos.closeBookingModal()"
                    data-testid="pay-booking-modal-close"
                    aria-label="Close"
                    style="background: none; border: 0; font-size: 24px; line-height: 1; color: var(--color-ink-mute); cursor: pointer; padding: 2px 6px;">
                ×
            </button>
        </div>

        {{-- Search box, filters the in-house list client-side by name / ref. --}}
        <input type="search"
               x-model="query"
               x-ref="searchInput"
               data-testid="pay-booking-search"
               placeholder="Search guest name or booking ref"
               autocomplete="off"
               class="lp-input"
               style="margin-bottom: 12px;">

        {{-- In-house roster. --}}
        @if ($inHouseBookings->isNotEmpty())
            <div class="lp-pos__guest-list" data-testid="pay-inhouse-list"
                 style="display: grid; gap: 6px; overflow-y: auto; flex: 1; min-height: 0; margin-bottom: 14px; padding-right: 2px;">
                @foreach ($inHouseBookings as $b)
                    @php
                        $needle = strtolower($b->guest_name.' '.$b->booking_ref);
                    @endphp
                    <button type="button"
                            wire:click="pickInHouseBooking({{ $b->id }})"
                            data-testid="pay-inhouse-{{ $b->id }}"
                            x-show="query.length === 0 || {{ \Illuminate\Support\Js::from($needle) }}.includes(query.toLowerCase())"
                            class="lp-pos__guest-row"
                            style="text-align: left; padding: 10px 12px; border: 1px solid var(--color-line-soft); border-radius: 8px; background: var(--color-card); cursor: pointer; display: flex; align-items: center; gap: 10px;">
                        <div style="flex: 1; min-width: 0;">
                            <div style="color: var(--color-ink); font-weight: 500; font-size: 13.5px;">
                                {{ $b->guest_name }}
                            </div>
                            <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                                {{ $b->booking_ref }}
                                &middot; {{ $b->checkin_date?->format('M j') }}
                                &rarr; {{ $b->checkout_date?->format('M j') }}
                            </div>
                        </div>
                        <span class="lp-mono text-[10px]"
                              style="padding: 3px 8px; border-radius: 999px; letter-spacing: 0.06em; text-transform: uppercase;
                                     background: {{ $b->booking_status->value === 'checked_in' ? 'rgba(60,120,90,0.14)' : 'rgba(0,0,0,0.05)' }};
                                     color: {{ $b->booking_status->value === 'checked_in' ? 'var(--color-accent-deep)' : 'var(--color-ink-mute)' }};">
                            {{ $b->booking_status->label() }}
                        </span>
                    </button>
                @endforeach

                {{-- Empty-search hint, shown when the query filters out
                     every row. Purely client-side. --}}
                <p x-show="query.length > 0 && {{ \Illuminate\Support\Js::from(collect($inHouseBookings)->map(fn ($b) => strtolower($b->guest_name.' '.$b->booking_ref))->values()) }}.every(n => !n.includes(query.toLowerCase()))"
                   x-cloak
                   data-testid="pay-inhouse-no-match"
                   style="padding: 16px 8px; text-align: center; color: var(--color-ink-mute); font-size: 12.5px;">
                    No in-house guest matches “<span x-text="query"></span>”.
                </p>
            </div>
        @else
            <p class="text-[12.5px]" style="color: var(--color-ink-mute); margin-bottom: 14px; padding: 20px 8px; text-align: center;"
               data-testid="pay-inhouse-empty">
                No guests currently in-house. Type a booking reference below if you have one.
            </p>
        @endif

        {{-- Manual fallback, pre-arrival guests or a walk-in with a printout. --}}
        <div style="border-top: 1px solid var(--color-line); padding-top: 12px;">
            <label for="pay-booking" class="lp-form-label" style="font-size: 10.5px;">Or enter a booking reference</label>
            <div class="flex gap-2">
                <input id="pay-booking" type="text" wire:model="bookingRef"
                       data-testid="pay-booking-ref" class="lp-input"
                       placeholder="KV-XXXXXX" style="text-transform: uppercase;">
                <button type="button" wire:click="lookupBooking" class="lp-btn" data-testid="pay-booking-lookup">Look up</button>
            </div>
            @if ($bookingLookupError)
                <p class="lp-form-error" data-testid="pay-booking-err">{{ $bookingLookupError }}</p>
            @endif
        </div>
    </div>
</div>

</div>
</template>

<style>
    /* Base styling in CSS (not inline), Alpine's x-show clears
       el.style.display when revealing, so inline `display: flex` gets
       wiped and the sheet renders as a top-left block. Class-based
       rules survive that. */
    .lp-booking-modal-backdrop {
        position: fixed; inset: 0;
        background: rgba(20, 20, 30, 0.55);
        display: flex; align-items: flex-end; justify-content: center;
        z-index: 900; padding: 0;
    }
    .lp-booking-modal-panel {
        background: var(--color-card); width: 100%; max-width: 520px;
        border-radius: 16px 16px 0 0; padding: 20px 20px 24px;
        max-height: 88vh; display: flex; flex-direction: column;
        box-shadow: 0 -20px 60px rgba(0, 0, 0, 0.20);
    }
    /* Desktop: centered card instead of bottom-sheet. */
    @media (min-width: 640px) {
        .lp-booking-modal-backdrop { align-items: center; padding: 24px; }
        .lp-booking-modal-panel { border-radius: 16px; }
    }
</style>
