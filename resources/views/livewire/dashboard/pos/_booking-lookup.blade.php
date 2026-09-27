{{-- Compact charge-to-room trigger, sits inside the payment sheet
     for both the single-tender and split-tender paths. The heavy list
     + search + manual-ref UI lives in the modal partial (rendered
     once at the terminal level) so the payment sheet stays small even
     when the in-house roster is dozens of guests.

     "Selected" state is a chip with a Change link that clears back to
     the trigger. --}}
<div class="mt-3" data-testid="pay-booking-picker">
    <template x-if="!$store.pos.bookingId">
        <button type="button"
                @click="$store.pos.openBookingModal()"
                data-testid="pay-booking-pick-open"
                class="lp-dash-card"
                style="width: 100%; padding: 12px 14px; background: var(--color-card); border: 1px dashed var(--color-line); border-radius: 10px; cursor: pointer; display: flex; align-items: center; gap: 10px; font-family: inherit; text-align: left;">
            <span style="width: 28px; height: 28px; border-radius: 50%; background: var(--color-accent-soft); color: var(--color-accent-deep); display: inline-flex; align-items: center; justify-content: center; font-size: 14px;">👤</span>
            <span style="flex: 1;">
                <span style="display: block; color: var(--color-ink); font-weight: 500; font-size: 14px;">Pick a guest to charge</span>
                <span style="display: block; color: var(--color-ink-mute); font-size: 11.5px;">In-house roster + manual ref</span>
            </span>
            <span style="color: var(--color-ink-mute);">›</span>
        </button>
    </template>

    <template x-if="$store.pos.bookingId">
        @php
            // On re-render Livewire only knows the ref via the wire:model
            // bridge, the guest name comes from Alpine after the pick.
        @endphp
        <div class="lp-dash-card" data-testid="pay-booking-selected"
             style="padding: 10px 12px; background: var(--color-accent-soft); border: 1px solid var(--color-accent-deep); display: flex; align-items: center; gap: 10px;">
            <span style="color: var(--color-accent-deep); font-size: 16px;">&#10003;</span>
            <div style="flex: 1;">
                <div style="color: var(--color-ink); font-weight: 500;" data-testid="pay-booking-selected-name">
                    {{ optional($inHouseBookings->firstWhere('id', $bookingIdResolved))->guest_name ?? 'Booking selected' }}
                </div>
                <div class="lp-mono text-[11px]" style="color: var(--color-ink-mute);">
                    <span x-text="$store.pos.bookingRef || '-'"></span>
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="button"
                        @click="$store.pos.openBookingModal()"
                        data-testid="pay-booking-change"
                        style="background: none; border: 0; padding: 4px 8px; color: var(--color-ink-mute); text-decoration: underline; cursor: pointer; font-family: inherit; font-size: 12px;">
                    Change
                </button>
                <button type="button" wire:click="clearBookingSelection"
                        data-testid="pay-booking-clear"
                        style="background: none; border: 0; padding: 4px 8px; color: var(--color-ink-mute); text-decoration: underline; cursor: pointer; font-family: inherit; font-size: 12px;">
                    Clear
                </button>
            </div>
        </div>
    </template>
</div>
