{{-- Close-for-maintenance modal.

     Visibility state is Alpine-side (`open`), toggled via browser
     events dispatched by the page's action buttons, no Livewire
     round-trip just to open or close. Only the form submit hits the
     server (`wire:submit`).

     Teleported to <body> so it escapes any parent stacking context
     and centers over the whole viewport.
--}}
<template x-teleport="body">
<div class="lp-page" style="display: contents;">
    <div x-data="{ open: false }"
         x-on:reservations-modal-open.window="if ($event.detail?.name === 'maintenance') { open = true; }"
         x-on:reservations-modal-close.window="if ($event.detail?.name === 'maintenance') { open = false; }"
         x-show="open"
         x-cloak
         @click.self="open = false"
         @keydown.escape.window="open = false"
         data-testid="maintenance-panel"
         class="lp-sheet-backdrop">
        <div class="lp-sheet-panel">
            <div class="lp-sheet-head">
                <h2 class="lp-sheet-title">Close room for maintenance</h2>
                <button type="button" @click="open = false"
                        aria-label="Close" class="lp-sheet-close">×</button>
            </div>
            <p class="text-[12.5px]" style="color: var(--color-ink-mute); margin: 0 0 14px;">
                Writes availability blocks for every night in the range so the room can't be booked.
            </p>

            <form wire:submit="submitMaintenance" style="display: grid; gap: 14px;">
                <div>
                    <label for="m-room" class="lp-form-label">Room</label>
                    <select id="m-room" wire:model="maintenanceRoomId"
                            data-testid="maintenance-room" class="lp-input" required>
                        <option value="">- pick a room -</option>
                        @foreach ($rooms as $r)
                            <option value="{{ $r->id }}">
                                Rm {{ $r->room_number }}
                                @if ($r->category) · {{ $r->category->name }} @endif
                            </option>
                        @endforeach
                    </select>
                    @error('maintenanceRoomId') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="m-from" class="lp-form-label">From</label>
                        <input id="m-from" type="date" wire:model="maintenanceFrom" required
                               data-testid="maintenance-from" class="lp-input">
                        @error('maintenanceFrom') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="m-to" class="lp-form-label">To (exclusive)</label>
                        <input id="m-to" type="date" wire:model="maintenanceTo" required
                               data-testid="maintenance-to" class="lp-input">
                        @error('maintenanceTo') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="m-reason" class="lp-form-label">Reason</label>
                    <input id="m-reason" type="text" wire:model="maintenanceReason" maxlength="240"
                           data-testid="maintenance-reason" class="lp-input"
                           placeholder="Deep-clean, plumbing, repainting…">
                    @error('maintenanceReason') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 pt-2"
                     style="border-top: 1px solid var(--color-line-soft);">
                    <button type="button" @click="open = false" class="lp-btn">Cancel</button>
                    <button type="submit" class="lp-btn lp-btn--primary" data-testid="maintenance-submit">
                        Close room
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</template>
