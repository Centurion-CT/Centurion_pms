@php
    // F&B outlets swap the vocabulary: Item + Portion instead of
    // Product + Unit. Falls back to Product when there's no outlet
    // context (deep-link into the form without an outlet param).
    $noun = $outlet?->type?->productNoun() ?? 'Product';
    $nounLower = mb_strtolower($noun);
@endphp

<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $productId ? 'Edit '.$nounLower : 'New '.$nounLower }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $productId ? 'Edit '.$nounLower : 'Add '.($nounLower === 'item' ? 'an item' : 'a product') }}
            </h1>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">
        <div class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="p-name" class="lp-form-label">Name</label>
                <input id="p-name" type="text" wire:model="name" required maxlength="160"
                       data-testid="product-name" class="lp-input" placeholder="Guinness Small">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="p-sku" class="lp-form-label">SKU <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">· optional</span></label>
                <input id="p-sku" type="text" wire:model="sku" maxlength="60"
                       data-testid="product-sku" class="lp-input" placeholder="GUIN-SM">
            </div>
        </div>

        <div>
            <label for="p-barcode" class="lp-form-label">
                Barcode
                <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">
                    &middot; scan or type &middot; UPC / EAN / Code&#8209;128
                </span>
            </label>
            <div style="display: flex; gap: 6px;">
                <input id="p-barcode" type="text" wire:model="barcode" maxlength="64"
                       data-testid="product-barcode" class="lp-input lp-mono"
                       inputmode="numeric"
                       autocomplete="off"
                       placeholder="Focus here and scan the label, or type it in."
                       style="flex: 1;">
                <button type="button" class="lp-btn"
                        data-testid="product-barcode-camera"
                        data-camera-scan
                        data-camera-scan-target="#p-barcode"
                        title="Scan with camera">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;">
                        <path d="M2 5h2l1-2h6l1 2h2v9H2z"/>
                        <circle cx="8" cy="9" r="2.5"/>
                    </svg>
                    Camera
                </button>
            </div>
            @error('barcode') <p class="lp-form-error">{{ $message }}</p> @enderror
            <p class="lp-form-help mt-1">
                USB scanners emit the digits then an Enter keystroke. Nothing else to configure.
                On a tablet or phone, tap Camera and point at the label.
                Cashiers will be able to add this product to a sale by scanning it at the terminal.
            </p>
        </div>

        <div>
            <label for="p-desc" class="lp-form-label">Description</label>
            <textarea id="p-desc" wire:model="description" rows="2" maxlength="2000"
                      data-testid="product-desc" class="lp-input" placeholder="Optional, shown on the POS tile"></textarea>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="p-price" class="lp-form-label">
                    Sell price (&#8358;)
                    @if (! $canManagePrices)
                        <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">
                            &middot; managers only
                        </span>
                    @endif
                </label>
                @if ($canManagePrices)
                    <input id="p-price" type="number" wire:model="price" min="0" required
                           data-testid="product-price" class="lp-input">
                    @error('price') <p class="lp-form-error">{{ $message }}</p> @enderror
                @else
                    <div class="lp-input lp-mono" data-testid="product-price-readonly"
                         style="background: color-mix(in srgb, var(--color-ink) 4%, transparent); cursor: not-allowed;">
                        &#8358;{{ number_format($price) }}
                    </div>
                    <p class="lp-form-help mt-1">Ask a manager if this needs to change.</p>
                @endif
            </div>
            <div>
                <label for="p-cost" class="lp-form-label">
                    Cost (&#8358;)
                    @if ($canManagePrices)
                        <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">&middot; optional</span>
                    @else
                        <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">&middot; managers only</span>
                    @endif
                </label>
                @if ($canManagePrices)
                    <input id="p-cost" type="number" wire:model="cost" min="0"
                           data-testid="product-cost" class="lp-input">
                    <p class="lp-form-help mt-1">Drives margin reporting later. Leave 0 if you don&rsquo;t track it.</p>
                @else
                    <div class="lp-input lp-mono" data-testid="product-cost-readonly"
                         style="background: color-mix(in srgb, var(--color-ink) 4%, transparent); cursor: not-allowed;">
                        &#8358;{{ number_format($cost) }}
                    </div>
                @endif
            </div>
        </div>

        <fieldset class="lp-fieldset">
            <legend>Stock</legend>
            <label class="flex items-start gap-3 text-[13.5px] mt-2" style="color: var(--color-ink);">
                <input type="checkbox" wire:model.live="track_stock" data-testid="product-track-stock"
                       style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                <span>
                    <strong style="font-weight: 500;">Track stock for this {{ $nounLower }}</strong>
                    <p class="text-[12.5px] mt-1" style="color: var(--color-ink-soft);">
                        Off for service items (laundry, spa treatments), sales won&rsquo;t decrement anything.
                    </p>
                </span>
            </label>
            @if ($track_stock)
                <div class="grid gap-5 sm:grid-cols-2 mt-4">
                    @if (! $productId)
                        <div>
                            <label for="p-initial" class="lp-form-label">Initial stock</label>
                            <input id="p-initial" type="number" wire:model="initial_stock" min="0"
                                   data-testid="product-initial" class="lp-input">
                            <p class="lp-form-help mt-1">Recorded as an InitialStock movement so the ledger shows where the count came from.</p>
                        </div>
                    @endif
                    <div>
                        <label for="p-low" class="lp-form-label">Low-stock threshold</label>
                        <input id="p-low" type="number" wire:model="low_stock_threshold" min="0"
                               data-testid="product-low" class="lp-input">
                        <p class="lp-form-help mt-1">Flags this {{ $nounLower }} on the outlet page when on-hand drops to this or below. 0 disables.</p>
                    </div>
                </div>
            @endif
        </fieldset>

        <fieldset class="lp-fieldset">
            <legend>Active</legend>
            <label class="flex items-start gap-3 text-[13.5px]" style="color: var(--color-ink);">
                <input type="checkbox" wire:model="active" data-testid="product-active"
                       style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                <span>Show in POS + {{ $nounLower }} catalog. Uncheck to archive.</span>
            </label>
        </fieldset>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ $outlet ? route('tenant.inventory.outlets.show', $outlet) : route('tenant.inventory.index') }}"
               wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-btn lp-btn--primary" data-testid="product-save">
                {{ $productId ? 'Save changes' : 'Create product' }}
            </button>
        </div>
    </form>
</div>
