@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div class="mx-auto" style="max-width: 640px;">
        <div class="text-center">
            <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                Step 1 of 3
            </span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.06;">
                Tell us about your property
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                A few basics so your booking page and dashboard know what to show.
            </p>
        </div>

        <form wire:submit="save" data-testid="property-basics-form"
              class="lp-dash-card mt-8 grid gap-6" style="padding: 28px 24px;">

            {{-- Property name comes from registration and already appears in
                 the sidebar/topbar chrome, so we don't ask for it again
                 here. Keep the underlying validation via a hidden input
                 so a tampered form still fails cleanly. --}}
            <input type="hidden" wire:model="name" />

            {{-- How do you rent? shapes the dashboard: hotel-style
                 (rooms per category) or single-unit (each apartment
                 is its own listing). --}}
            <fieldset class="lp-fieldset" data-testid="unit-mode-fieldset">
                <legend>How do you rent?</legend>
                <div class="mt-2 grid gap-3">
                    <label class="mode-card"
                           style="background: {{ $unitMode === 'multi_room' ? 'rgba(51, 103, 153, 0.08)' : 'transparent' }};"
                           data-testid="unit-mode-multi_room">
                        <input type="radio" wire:model.live="unitMode" value="multi_room"
                               style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                        <span>
                            <b style="font-size: 14px; color: var(--color-ink);">
                                Hotel or apartment complex
                            </b>
                            <span style="display: block; font-size: 12.5px; color: var(--color-ink-mute); margin-top: 2px;">
                                Multiple rooms or units at one address, managed together with a room chart, categories and housekeeping rota.
                            </span>
                        </span>
                    </label>
                    <label class="mode-card"
                           style="background: {{ $unitMode === 'single_unit' ? 'rgba(51, 103, 153, 0.08)' : 'transparent' }};"
                           data-testid="unit-mode-single_unit">
                        <input type="radio" wire:model.live="unitMode" value="single_unit"
                               style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                        <span>
                            <b style="font-size: 14px; color: var(--color-ink);">
                                Standalone units in different locations
                            </b>
                            <span style="display: block; font-size: 12.5px; color: var(--color-ink-mute); margin-top: 2px;">
                                A condo in one neighbourhood, a loft in another, each with its own address. You'll add more from the Portfolio page after this one.
                            </span>
                        </span>
                    </label>
                </div>
                @error('unitMode') <p class="lp-form-error">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="lp-fieldset">
                <legend>Property type</legend>
                <div class="mt-2 grid gap-2">
                    @foreach ($types as $type)
                        <label class="flex items-center gap-3 py-1 text-[14px] cursor-pointer"
                               style="color: var(--color-ink);">
                            <input type="radio" wire:model="type" value="{{ $type->value }}"
                                   class="h-4 w-4"
                                   style="accent-color: var(--color-accent-deep);" />
                            {{ $type->label() }}
                        </label>
                    @endforeach
                </div>
                @error('type') <p data-error="type" class="lp-form-error">{{ $message }}</p> @enderror
            </fieldset>

            <div>
                <label for="prop-address" class="lp-form-label">Full address</label>
                <input id="prop-address" type="text" wire:model="address" required
                       class="lp-input @error('address') is-invalid @enderror"
                       placeholder="Street, area" />
                @error('address') <p data-error="address" class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <label for="prop-city" class="lp-form-label">City</label>
                    <input id="prop-city" type="text" wire:model="city"
                           class="lp-input" placeholder="Abuja" />
                </div>
                <div>
                    <label for="prop-state" class="lp-form-label">State</label>
                    <input id="prop-state" type="text" wire:model="state"
                           class="lp-input" placeholder="FCT" />
                </div>
            </div>

            <div>
                <label for="prop-description" class="lp-form-label">Brief description</label>
                <textarea id="prop-description" wire:model="description" rows="4"
                          class="lp-input"
                          placeholder="A sentence or two guests will read on your booking page."></textarea>
            </div>

            {{-- Currency the property will run in. Defaults to NGN
                 (Keverna's home market); operators outside Nigeria
                 pick their local currency here. Changeable later
                 from Settings. --}}
            <div>
                <label for="prop-currency" class="lp-form-label">Currency</label>
                <select id="prop-currency" wire:model="currencyCode"
                        class="lp-input" data-testid="prop-currency">
                    @foreach ($currenciesByRegion as $region => $currencies)
                        <optgroup label="{{ $region }}">
                            @foreach ($currencies as $c)
                                <option value="{{ $c->value }}">
                                    {{ $c->symbol() }} {{ $c->value }} · {{ $c->label() }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="lp-form-help mt-1" style="font-size: 12px; color: var(--color-ink-mute);">
                    All invoices, receipts, and dashboard amounts render in this currency.
                    You can change it anytime from Settings.
                </p>
                @error('currencyCode') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="mt-2 flex justify-end">
                <button type="submit" class="lp-pill" data-testid="property-basics-submit">
                    <span wire:loading.remove wire:target="save">Save &amp; continue</span>
                    <span wire:loading wire:target="save">Saving…</span>
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>
    </div>
    <style>
        .mode-card {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; border-radius: 10px;
            border: 1px solid var(--color-line, #EBEBEB);
            cursor: pointer; transition: background 120ms ease;
        }
    </style>
</div>
