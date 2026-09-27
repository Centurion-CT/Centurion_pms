@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    <h1 class="lp-display mt-4 text-[clamp(26px,3.4vw,36px)]"
        style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
        {{ $isEdit ? 'Edit unit' : 'Add unit' }}
    </h1>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;" data-testid="unit-form">

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="u-name">Unit name</label>
                <input id="u-name" type="text" wire:model.live="name"
                       class="lp-input" required maxlength="160" data-testid="name"
                       placeholder="e.g. Flat 3B">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="u-addr">Address</label>
                <input id="u-addr" type="text" wire:model.live="address"
                       class="lp-input" required maxlength="240" data-testid="address">
                @error('address') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="u-city">City</label>
                <input id="u-city" type="text" wire:model.live="city" class="lp-input" maxlength="64" data-testid="city">
            </div>
            <div>
                <label class="lp-form-label" for="u-state">State</label>
                <input id="u-state" type="text" wire:model.live="state" class="lp-input" maxlength="64" data-testid="state">
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="u-beds">Bedrooms</label>
                <input id="u-beds" type="number" min="0" max="20" wire:model.live="bedrooms"
                       class="lp-input" required data-testid="bedrooms">
            </div>
            <div>
                <label class="lp-form-label" for="u-baths">Bathrooms</label>
                <input id="u-baths" type="number" min="0" max="20" wire:model.live="bathrooms"
                       class="lp-input" required data-testid="bathrooms">
            </div>
            <div>
                <label class="lp-form-label" for="u-size">Size (sqm)</label>
                <input id="u-size" type="number" min="0" wire:model.live="size_sqm" class="lp-input" data-testid="size">
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="lp-form-label" for="u-ar">Annual rent (₦)</label>
                <input id="u-ar" type="number" min="0" wire:model.live="annual_rent_naira"
                       class="lp-input" required data-testid="annual">
                @error('annual_rent_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="u-mr">Monthly rent (₦)</label>
                <input id="u-mr" type="number" min="0" wire:model.live="monthly_rent_naira"
                       class="lp-input" data-testid="monthly">
            </div>
            <div>
                <label class="lp-form-label" for="u-dep">Deposit (₦)</label>
                <input id="u-dep" type="number" min="0" wire:model.live="deposit_naira"
                       class="lp-input" data-testid="deposit">
            </div>
            <div>
                <label class="lp-form-label" for="u-sc">Service charge / yr (₦)</label>
                <input id="u-sc" type="number" min="0" wire:model.live="service_charge_annual_naira"
                       class="lp-input" data-testid="service-charge">
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="u-desc">Description</label>
            <textarea id="u-desc" wire:model.live="description" rows="4" maxlength="8000"
                      class="lp-input" data-testid="description"
                      placeholder="What's included, appliances, amenities, parking…"></textarea>
        </div>

        <div>
            <label class="lp-form-label" for="u-status">Status</label>
            <select id="u-status" wire:model.live="status" class="lp-input" required data-testid="status">
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Add unit' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
