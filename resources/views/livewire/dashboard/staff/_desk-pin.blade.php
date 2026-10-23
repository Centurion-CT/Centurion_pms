{{-- Desk PIN: unlocks the desk app on the shared reception PC. --}}
<span x-data="{ open: false, pin: '' }" style="position: relative;">
    <button type="button" @click="open = ! open"
            data-testid="staff-desk-pin-{{ $op->id }}"
            style="background: transparent; border: 0; cursor: pointer; font-family: inherit; font-size: 12.5px; color: var(--color-accent-deep); text-decoration: underline;">
        {{ $op->hasStaffPin() ? 'Change desk PIN' : 'Set desk PIN' }}
    </button>
    <form x-show="open" x-cloak @click.outside="open = false"
          @submit.prevent="$wire.setDeskPin({{ $op->id }}, pin).then(() => { open = false; pin = '' })"
          class="lp-dash-card"
          style="position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; width: 260px; display: grid; gap: 10px; text-align: left; box-shadow: 0 12px 32px rgba(26,36,48,.14);">
        <label class="lp-form-label" for="desk-pin-{{ $op->id }}">New desk PIN</label>
        <input id="desk-pin-{{ $op->id }}" type="text" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" x-model="pin" class="lp-input" placeholder="4 to 6 digits" required autocomplete="off"
               data-testid="staff-desk-pin-input-{{ $op->id }}">
        <p class="lp-form-help" style="margin: 0;">Used to unlock {{ config('brand.name') }} Desk at reception.</p>
        <button type="submit" class="lp-pill" style="justify-self: start;" data-testid="staff-desk-pin-save-{{ $op->id }}">Save</button>
    </form>
</span>
