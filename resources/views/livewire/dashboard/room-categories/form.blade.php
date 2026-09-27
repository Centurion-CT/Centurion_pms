@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back + header ───────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $categoryId ? 'Edit category' : 'New category' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $categoryId ? 'Edit category' : 'Create a category' }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Set the pricing, capacity, rules and photos for this class of rooms.
                Individual rooms in this category will inherit everything here.
            </p>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">

        {{-- Identity --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="c-name" class="lp-form-label">Name</label>
                <input id="c-name" type="text" wire:model="name" required maxlength="120"
                       data-testid="c-name" class="lp-input" placeholder="Deluxe Suite">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="c-type" class="lp-form-label">Type</label>
                <input id="c-type" type="text" wire:model="type" required maxlength="64"
                       data-testid="c-type" class="lp-input" placeholder="Suite, Studio, Loft…">
                @error('type') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label for="c-description" class="lp-form-label">Description</label>
            <textarea id="c-description" wire:model="description" rows="4"
                      data-testid="c-description" class="lp-input"
                      placeholder="What makes this room class special? Views, layout, amenities…"></textarea>
            @error('description') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Capacity --}}
        <fieldset class="lp-fieldset">
            <legend>Capacity</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label for="c-adults" class="lp-form-label">Adults</label>
                    <input id="c-adults" type="number" wire:model.number="capacity_adults"
                           min="1" max="50" required data-testid="c-adults" class="lp-input">
                    @error('capacity_adults') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-children" class="lp-form-label">Children</label>
                    <input id="c-children" type="number" wire:model.number="capacity_children"
                           min="0" max="50" required data-testid="c-children" class="lp-input">
                    @error('capacity_children') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        {{-- Pricing --}}
        <fieldset class="lp-fieldset">
            <legend>Pricing &middot; NGN</legend>
            <div class="grid gap-5 sm:grid-cols-3 mt-2">
                <div>
                    <label for="c-base-rate" class="lp-form-label">Base nightly</label>
                    <input id="c-base-rate" type="number" wire:model.number="base_rate" min="1"
                           required data-testid="c-base-rate" class="lp-input">
                    @error('base_rate') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-breakfast-rate" class="lp-form-label">
                        With breakfast <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span>
                    </label>
                    <input id="c-breakfast-rate" type="number" wire:model.number="breakfast_included_rate" min="1"
                           data-testid="c-breakfast-rate" class="lp-input"
                           placeholder="e.g. {{ $base_rate ? (int) $base_rate + 5000 : 55000 }}">
                    <p class="lp-form-help">
                        Leave blank to disable. When set, guests + operators can pick either the
                        base rate or this "with complimentary breakfast" tier at booking time.
                    </p>
                    @error('breakfast_included_rate') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-monthly-rate" class="lp-form-label">Monthly (long-stay)</label>
                    <input id="c-monthly-rate" type="number" wire:model.number="monthly_rate" min="0"
                           data-testid="c-monthly-rate" class="lp-input">
                    @error('monthly_rate') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-min-nights" class="lp-form-label">Minimum nights</label>
                    <input id="c-min-nights" type="number" wire:model.number="min_nights" min="1" max="90"
                           required data-testid="c-min-nights" class="lp-input">
                    @error('min_nights') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 mt-3">
                <div>
                    <label for="c-weekly-discount" class="lp-form-label">Weekly discount %</label>
                    <input id="c-weekly-discount" type="number" wire:model.number="weekly_discount_percent"
                           min="0" max="100" class="lp-input">
                    @error('weekly_discount_percent') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-monthly-discount" class="lp-form-label">Monthly discount %</label>
                    <input id="c-monthly-discount" type="number" wire:model.number="monthly_discount_percent"
                           min="0" max="100" class="lp-input">
                    @error('monthly_discount_percent') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        {{-- Deposit --}}
        <fieldset class="lp-fieldset">
            <legend>Hold deposit</legend>
            <div class="grid gap-1 mt-2">
                @foreach ($depositOptions as $opt)
                    <label class="lp-radio-row">
                        <input type="radio" wire:model="deposit_type" value="{{ $opt->value }}"
                               data-testid="c-deposit-{{ $opt->value }}">
                        <span>{{ ucfirst(str_replace('_', ' ', $opt->value)) }}</span>
                    </label>
                @endforeach
            </div>
            <div class="mt-3">
                <label for="c-deposit-value" class="lp-form-label">Value</label>
                <input id="c-deposit-value" type="number" wire:model.number="deposit_value"
                       min="0" data-testid="c-deposit-value" class="lp-input">
                @error('deposit_value') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        {{-- Booking type --}}
        <fieldset class="lp-fieldset">
            <legend>Booking type</legend>
            <div class="grid gap-1 mt-2">
                @foreach ($bookingTypeOptions as $opt)
                    <label class="lp-radio-row">
                        <input type="radio" wire:model="booking_type" value="{{ $opt->value }}"
                               data-testid="c-booking-{{ $opt->value }}">
                        <span>{{ ucfirst(str_replace('_', ' ', $opt->value)) }}</span>
                    </label>
                @endforeach
            </div>
            @error('booking_type') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Status --}}
        <fieldset class="lp-fieldset">
            <legend>Visibility</legend>
            <div class="grid gap-1 mt-2">
                @foreach ($statusOptions as $opt)
                    <label class="lp-radio-row">
                        <input type="radio" wire:model="status" value="{{ $opt->value }}"
                               data-testid="c-status-{{ $opt->value }}">
                        <span><strong>{{ ucfirst($opt->value) }}</strong></span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- Housekeeping checklist bundles, reusable lists a manager
             authored on the Housekeeping page. Tick the ones that
             should snapshot onto every fresh inspection for rooms
             in this category. Existing inspections are not affected;
             item labels are snapshotted at open time. --}}
        <fieldset class="lp-fieldset" data-testid="c-checklist-bundles">
            <legend>Housekeeping checklists</legend>
            <p class="text-[13px] mb-3" style="color: var(--color-ink-soft);">
                Pick which checklist bundles apply to this category. Every ticked bundle contributes
                its items to a new inspection when a room checks out. Manage bundles + item text on the
                <a href="{{ route('tenant.housekeeping.index') }}" wire:navigate
                   style="color: var(--color-accent-deep); text-decoration: underline; text-underline-offset: 3px;">
                    Housekeeping page
                </a>.
            </p>

            @if ($availableChecklists->isEmpty())
                <p class="text-[12.5px]" style="color: var(--color-ink-mute); font-style: italic;">
                    No bundles authored yet.
                    <a href="{{ route('tenant.housekeeping.index') }}" wire:navigate
                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                        Go author your first bundle
                    </a>
                    on the Housekeeping page, then come back to attach it.
                </p>
            @else
                <div style="display: grid; gap: 8px;">
                    @foreach ($availableChecklists as $bundle)
                        <label wire:key="c-bundle-{{ $bundle->id }}"
                               data-testid="c-bundle-row-{{ $bundle->id }}"
                               style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px;
                                      background: var(--color-card); border: 1px solid var(--color-line-soft);
                                      border-radius: 8px; cursor: pointer;">
                            <input type="checkbox"
                                   wire:model="attachedChecklistIds"
                                   value="{{ $bundle->id }}"
                                   data-testid="c-bundle-checkbox-{{ $bundle->id }}"
                                   style="margin-top: 3px; flex-shrink: 0;">
                            <span style="flex: 1;">
                                <strong style="color: var(--color-ink);">{{ $bundle->name }}</strong>
                                <span class="lp-mono text-[11px]" style="color: var(--color-ink-mute); margin-left: 6px;">
                                    · {{ $bundle->items_count }} item{{ $bundle->items_count === 1 ? '' : 's' }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </fieldset>

        {{-- House rules --}}
        <div>
            <label for="c-rules" class="lp-form-label">House rules / policies</label>
            <textarea id="c-rules" wire:model="rules" rows="4"
                      data-testid="c-rules" class="lp-input"
                      placeholder="Check-in 3pm, check-out 11am · No pets · Quiet hours after 10pm…"></textarea>
            <p class="lp-form-help">Shown to guests on the public room detail page.</p>
            @error('rules') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Photos, new categories stage files here and commit them on
             Save (photos need a saved category to attach to). Existing
             categories reorder / mark hero / delete inline. --}}
        <fieldset class="lp-fieldset">
            <legend>Photos</legend>
            <p class="text-[13px] mb-3" style="color: var(--color-ink-soft);">
                Up to 20 images per category. Used on the public booking page for every room in this class.
                @if (! $categoryId)
                    New categories: files stage in the picker. Press <strong style="color: var(--color-ink); font-weight: 500;">{{ $categoryId ? 'Save changes' : 'Create category' }}</strong> to upload them.
                @endif
            </p>

            <x-photo-manager
                :media="$gallery"
                :pending="$newPhotos"
                wireModel="newPhotos"
                :wireReorder="$categoryId ? 'reorderPhotos' : null"
                :wireSetHero="$categoryId ? 'setPhotoHero' : null"
                :wireDelete="$categoryId ? 'deletePhoto' : null"
                :wireDeleteMany="$categoryId ? 'deletePhotos' : null"
                :maxFiles="20"
                label="Category photos"
                emptyLabel="No photos yet." />

            @error('newPhotos.*') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Actions --}}
        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.room-categories.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-pill" data-testid="c-save"
                    wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $categoryId ? 'Save changes' : 'Create category' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
