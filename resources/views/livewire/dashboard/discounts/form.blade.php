@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back + header ───────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $discountId ? 'Edit discount' : 'New discount' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $discountId ? 'Edit' : 'Create a discount' }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Skip the code field to make this an <strong>auto-apply</strong> rule. Set a code (e.g.
                <span class="lp-mono">SUMMER25</span>) for a <strong>coupon</strong> guests or operators enter at booking.
            </p>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">

        {{-- Identity --}}
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="d-name" class="lp-form-label">Name</label>
                <input id="d-name" type="text" wire:model="name" required maxlength="120"
                       data-testid="d-name" class="lp-input" placeholder="Festive season promo">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="d-code" class="lp-form-label">
                    Code <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· leave empty to auto-apply</span>
                </label>
                <input id="d-code" type="text" wire:model="code" maxlength="32"
                       data-testid="d-code" class="lp-input lp-mono"
                       style="text-transform: uppercase;"
                       placeholder="SUMMER25">
                @error('code') <p class="lp-form-error">{{ $message }}</p> @enderror
                <p class="lp-form-help">Letters, numbers, dashes, underscores only.</p>
            </div>
        </div>

        {{-- Discount value --}}
        <fieldset class="lp-fieldset">
            <legend>Amount off</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label for="d-type" class="lp-form-label">Type</label>
                    <select id="d-type" wire:model.live="type" data-testid="d-type" class="lp-input">
                        @foreach ($typeOptions as $opt)
                            <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="d-value" class="lp-form-label">
                        Value {{ $type === 'percent' ? '· %' : '· ₦' }}
                    </label>
                    <input id="d-value" type="number" wire:model="value" min="1" required
                           data-testid="d-value" class="lp-input"
                           placeholder="{{ $type === 'percent' ? '15' : '5000' }}">
                    @error('value') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        {{-- Stay length --}}
        <fieldset class="lp-fieldset">
            <legend>Stay length <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span></legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label for="d-min-nights" class="lp-form-label">Min nights</label>
                    <input id="d-min-nights" type="number" wire:model="min_nights" min="1" max="365"
                           data-testid="d-min-nights" class="lp-input" placeholder="e.g. 3">
                    @error('min_nights') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-max-nights" class="lp-form-label">Max nights</label>
                    <input id="d-max-nights" type="number" wire:model="max_nights" min="1" max="365"
                           data-testid="d-max-nights" class="lp-input" placeholder="e.g. 14">
                    @error('max_nights') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        {{-- Validity window --}}
        <fieldset class="lp-fieldset">
            <legend>Validity window <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional</span></legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label for="d-starts-on" class="lp-form-label">Starts on</label>
                    <input id="d-starts-on" type="date" wire:model="starts_on"
                           data-testid="d-starts-on" class="lp-input">
                    @error('starts_on') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="d-ends-on" class="lp-form-label">Ends on</label>
                    <input id="d-ends-on" type="date" wire:model="ends_on"
                           data-testid="d-ends-on" class="lp-input">
                    @error('ends_on') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="lp-form-help mt-2">
                Check-in date must overlap with this window. Leave both empty to apply year-round.
            </p>
        </fieldset>

        {{-- Channels --}}
        <fieldset class="lp-fieldset">
            <legend>Channels <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· empty = all</span></legend>
            <div class="grid gap-1 mt-2">
                @foreach ($channelOptions as $opt)
                    <label class="lp-check-row">
                        <input type="checkbox" wire:model="channels" value="{{ $opt['value'] }}"
                               data-testid="d-channel-{{ $opt['value'] }}">
                        <span>{{ $opt['label'] }}</span>
                    </label>
                @endforeach
            </div>
            @error('channels.*') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Room categories --}}
        <fieldset class="lp-fieldset">
            <legend>Categories <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· empty = any category</span></legend>
            <p class="lp-form-help" style="margin: 0 0 8px;">
                Best for "20% off any Deluxe stay". Pick the room class instead of listing every
                individual room.
            </p>
            @if ($allCategories->isEmpty())
                <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                    No categories yet. This discount will apply to every category you add later.
                </p>
            @else
                <div class="grid gap-1 mt-2" data-testid="d-categories">
                    @foreach ($allCategories as $cat)
                        <label class="lp-check-row">
                            <input type="checkbox" wire:model="roomCategories" value="{{ $cat->id }}"
                                   data-testid="d-category-{{ $cat->id }}">
                            <span>{{ $cat->name }} <span style="color: var(--color-ink-mute);">· {{ $cat->type }}</span></span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('roomCategories.*') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Individual rooms --}}
        <fieldset class="lp-fieldset">
            <legend>Individual rooms <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· empty = any room</span></legend>
            <p class="lp-form-help" style="margin: 0 0 8px;">
                Finer-grained than category targeting. Useful for "10% off Room 305 this month
                only". Combined with categories, the intersection wins.
            </p>
            @if ($allRooms->isEmpty())
                <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                    No active rooms yet. This discount will apply to every room you add later.
                </p>
            @else
                <div class="grid gap-1 mt-2" data-testid="d-rooms">
                    @foreach ($allRooms as $room)
                        <label class="lp-check-row">
                            <input type="checkbox" wire:model="rooms" value="{{ $room->id }}"
                                   data-testid="d-room-{{ $room->id }}">
                            <span>
                                {{ $room->category?->name ?? 'Uncategorised' }}
                                <span style="color: var(--color-ink-mute);">· #{{ $room->room_number ?? $room->id }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('rooms.*') <p class="lp-form-error">{{ $message }}</p> @enderror
        </fieldset>

        {{-- Usage cap --}}
        <div>
            <label for="d-max-uses" class="lp-form-label">
                Maximum uses <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· optional, leave empty for unlimited</span>
            </label>
            <input id="d-max-uses" type="number" wire:model="max_uses" min="1"
                   data-testid="d-max-uses" class="lp-input" placeholder="e.g. 50">
            @error('max_uses') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Description / internal note --}}
        <div>
            <label for="d-description" class="lp-form-label">Internal description</label>
            <textarea id="d-description" wire:model="description" rows="3" maxlength="2000"
                      data-testid="d-description" class="lp-input"
                      placeholder="Notes for your team, what this promo is for, where it was distributed, etc."></textarea>
            @error('description') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Active flag --}}
        <label class="lp-check-row">
            <input type="checkbox" wire:model="active" data-testid="d-active">
            <span><strong>Active</strong>, eligible to apply to new bookings</span>
        </label>

        {{-- Actions --}}
        <div class="flex flex-wrap justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.discounts.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" data-testid="d-save" class="lp-pill">
                <span wire:loading.remove wire:target="save">{{ $discountId ? 'Save changes' : 'Create discount' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
