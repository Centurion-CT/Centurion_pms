@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div>
        @include('_partials._back-button')

        <h1 class="lp-display mt-4 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            {{ $isEdit ? 'Edit workspace' : 'Add workspace' }}
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            A workspace is the bookable unit, a hot desk pool, dedicated desk, private office, or meeting room.
        </p>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;" data-testid="workspace-form">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="w-name">Name</label>
                <input id="w-name" type="text" wire:model.live="name"
                       class="lp-input" data-testid="name" required maxlength="120">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="w-type">Type</label>
                <select id="w-type" wire:model.live="type" class="lp-input" data-testid="type">
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
                @error('type') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="w-cap">Capacity</label>
                <input id="w-cap" type="number" min="1" max="1000"
                       wire:model.live="capacity" class="lp-input" data-testid="capacity">
                @error('capacity') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="w-hr">Hourly rate (₦)</label>
                <input id="w-hr" type="number" min="0"
                       wire:model.live="hourly_rate_naira" class="lp-input" data-testid="hourly">
                @error('hourly_rate_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="w-dr">Daily rate (₦)</label>
                <input id="w-dr" type="number" min="0"
                       wire:model.live="daily_rate_naira" class="lp-input" data-testid="daily">
                @error('daily_rate_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="w-desc">Description</label>
            <textarea id="w-desc" wire:model.live="description" rows="4" maxlength="5000"
                      class="lp-input" data-testid="description"
                      placeholder="What's included, chairs, monitors, coffee, lockers…"></textarea>
            @error('description') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-[13px]" style="color: var(--color-ink-soft);">
            <input type="checkbox" wire:model.live="is_active"
                   style="accent-color: var(--color-accent-deep);" data-testid="active">
            Visible on the public site
        </label>

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Add workspace' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
