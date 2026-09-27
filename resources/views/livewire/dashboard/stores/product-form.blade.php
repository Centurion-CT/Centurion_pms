<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $isNew ? 'New store product' : 'Edit store product' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $isNew ? 'Add product to store' : $name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                @if ($isNew)
                    Enter the product's details. Outlets request from this catalog; the sell
                    price you set here is what outlets inherit when they first receive this SKU.
                @else
                    Update the product details. Existing outlet copies of this SKU keep their
                    own prices. Only newly-provisioned outlet products pick up the new defaults.
                @endif
            </p>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 18px;">
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="p-name" class="lp-form-label">Name</label>
                <input id="p-name" type="text" wire:model="name" required maxlength="160"
                       data-testid="store-product-name" class="lp-input" placeholder="e.g. Malt, Guinness small, Origin bitters">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="p-sku" class="lp-form-label">SKU <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></label>
                <input id="p-sku" type="text" wire:model="sku" maxlength="60"
                       data-testid="store-product-sku" class="lp-input" placeholder="e.g. MALT-330">
                @error('sku') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="p-barcode" class="lp-form-label">Barcode <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></label>
                <input id="p-barcode" type="text" wire:model="barcode" maxlength="64"
                       data-testid="store-product-barcode" class="lp-input" placeholder="e.g. 5449000000439">
                @error('barcode') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            @if ($canManagePrices)
                <div>
                    <label for="p-price" class="lp-form-label">Default sell price (&#8358;)</label>
                    <input id="p-price" type="number" wire:model="price" required min="0"
                           data-testid="store-product-price" class="lp-input" placeholder="e.g. 500">
                    <p class="mt-1 text-[11.5px]" style="color: var(--color-ink-mute);">
                        Whole Naira. Sell price outlets inherit when they first request this SKU.
                    </p>
                    @error('price') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="p-cost" class="lp-form-label">Cost (&#8358;)</label>
                    <input id="p-cost" type="number" wire:model="cost" required min="0"
                           data-testid="store-product-cost" class="lp-input" placeholder="e.g. 200">
                    <p class="mt-1 text-[11.5px]" style="color: var(--color-ink-mute);">
                        Whole Naira. Last-received wins on Receive; used for COGS.
                    </p>
                    @error('cost') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="sm:col-span-2 text-[12.5px]" style="color: var(--color-ink-mute);">
                    Price + cost are manager-only fields, ask a manager to set them.
                </div>
            @endif

            <div>
                <label for="p-threshold" class="lp-form-label">Low-stock threshold</label>
                <input id="p-threshold" type="number" wire:model="low_stock_threshold" min="0"
                       data-testid="store-product-threshold" class="lp-input" placeholder="0 = no alert">
                @error('low_stock_threshold') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            @if ($isNew)
                <div>
                    <label for="p-initial" class="lp-form-label">Initial stock <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></label>
                    <input id="p-initial" type="number" wire:model="initial_stock" min="0"
                           data-testid="store-product-initial" class="lp-input" placeholder="Opening quantity in the store today">
                    <p class="mt-1 text-[11.5px]" style="color: var(--color-ink-mute);">
                        Seeds an InitialStock ledger row so the audit trail shows where the qty came from.
                    </p>
                    @error('initial_stock') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="sm:col-span-2">
                <label for="p-description" class="lp-form-label">Description <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></label>
                <textarea id="p-description" wire:model="description" maxlength="2000" rows="3"
                          data-testid="store-product-description" class="lp-input"></textarea>
                @error('description') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2" style="font-size: 13.5px;">
                    <input type="checkbox" wire:model="active" data-testid="store-product-active">
                    <span>Active</span>
                </label>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.store.products.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-btn lp-btn--primary" data-testid="store-product-save">
                {{ $isNew ? 'Create product' : 'Save changes' }}
            </button>
        </div>
    </form>
</div>
