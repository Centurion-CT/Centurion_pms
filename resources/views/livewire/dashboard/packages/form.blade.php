@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back + header ───────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $packageId ? 'Edit package' : 'New package' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $packageId ? 'Edit' : 'Create a package' }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Mark a package as <strong>complimentary</strong> to offer it free, or set a price to charge guests on top of the room rate.
            </p>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">

        {{-- Identity --}}
        <div>
            <label for="p-name" class="lp-form-label">Name</label>
            <input id="p-name" type="text" wire:model="name" required maxlength="120"
                   data-testid="p-name" class="lp-input" placeholder="Welcome breakfast">
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="p-description" class="lp-form-label">Description</label>
            <textarea id="p-description" wire:model="description" rows="3" maxlength="2000"
                      data-testid="p-description" class="lp-input"
                      placeholder="What the guest gets. Shown on the booking page and the guest portal."></textarea>
            @error('description') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Pricing --}}
        <fieldset class="lp-fieldset">
            <legend>Pricing</legend>
            <label class="lp-check-row mt-2">
                <input type="checkbox" wire:model.live="is_complimentary" data-testid="p-complimentary">
                <span><strong>Complimentary</strong>, offer this package free of charge</span>
            </label>
            <div class="mt-4" @if ($is_complimentary) style="opacity: 0.5; pointer-events: none;" @endif>
                <label for="p-price" class="lp-form-label">Price · ₦</label>
                <input id="p-price" type="number" wire:model="price" min="0" max="100000000"
                       data-testid="p-price" class="lp-input"
                       placeholder="e.g. 15000"
                       @if ($is_complimentary) disabled @endif>
                @error('price') <p class="lp-form-error">{{ $message }}</p> @enderror
                <p class="lp-form-help">Charged per unit selected by the guest.</p>
            </div>
        </fieldset>

        {{-- Availability --}}
        <fieldset class="lp-fieldset">
            <legend>Availability</legend>
            <div class="grid gap-1 mt-2">
                <label class="lp-check-row">
                    <input type="checkbox" wire:model="available_during_booking" data-testid="p-during">
                    <span>Show on the booking page (guests pick it before payment)</span>
                </label>
                <label class="lp-check-row">
                    <input type="checkbox" wire:model="available_after_booking" data-testid="p-after">
                    <span>Show in the guest portal (guests can add it after booking)</span>
                </label>
            </div>
        </fieldset>

        {{-- Quantity cap --}}
        <div>
            <label for="p-max" class="lp-form-label">Maximum per booking</label>
            <input id="p-max" type="number" wire:model="max_per_booking" min="1" max="50"
                   data-testid="p-max" class="lp-input" placeholder="1">
            @error('max_per_booking') <p class="lp-form-error">{{ $message }}</p> @enderror
            <p class="lp-form-help">Use 1 for once-per-stay add-ons, higher for things like extra breakfasts.</p>
        </div>

        {{-- Active flag --}}
        <label class="lp-check-row">
            <input type="checkbox" wire:model="active" data-testid="p-active">
            <span><strong>Active</strong>, offered on new bookings</span>
        </label>

        {{-- Actions --}}
        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.packages.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" data-testid="p-save" class="lp-pill">
                <span wire:loading.remove wire:target="save">{{ $packageId ? 'Save changes' : 'Create package' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
