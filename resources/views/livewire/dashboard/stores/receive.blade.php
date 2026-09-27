<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Receive stock</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Log a supplier delivery
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Everything received here lands in the central store first and
                issue to outlets on daily requests.
                @if ($canManagePrices)
                    Unit cost is optional; when set it becomes the store item&rsquo;s new cost basis.
                @else
                    Cost changes are manager-only, so this form updates quantity only.
                @endif
            </p>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 18px;">
        @if ($store === null)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="store-receive-no-store">
                Central store not provisioned. Contact support.
            </p>
        @elseif ($products->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="store-receive-empty">
                No store products yet.
                <a href="{{ route('tenant.store.products.create') }}" wire:navigate
                   style="color: var(--color-accent-deep); text-decoration: underline;">
                    Add one to the catalog
                </a>
                first, then come back to receive.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Store product</th>
                            <th style="text-align: right;">Quantity</th>
                            @if ($canManagePrices)
                                <th style="text-align: right;">Unit cost (&#8358;) <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span></th>
                            @endif
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $index => $line)
                            <tr>
                                <td>
                                    <select wire:model="lines.{{ $index }}.product_id" class="lp-input"
                                            data-testid="store-receive-product-{{ $index }}" style="min-width: 240px;">
                                        <option value="">- pick a store product -</option>
                                        @foreach ($products as $p)
                                            <option value="{{ $p->id }}">{{ $p->name }} · on hand {{ $p->quantity_on_hand }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="text-align: right;">
                                    <input type="number" wire:model="lines.{{ $index }}.quantity" min="1"
                                           data-testid="store-receive-qty-{{ $index }}" class="lp-input" style="width: 120px; text-align: right;">
                                </td>
                                @if ($canManagePrices)
                                    <td style="text-align: right;">
                                        <input type="number" wire:model="lines.{{ $index }}.unit_cost" min="0"
                                               data-testid="store-receive-cost-{{ $index }}" class="lp-input" style="width: 140px; text-align: right;">
                                    </td>
                                @endif
                                <td>
                                    <button type="button" wire:click="removeLine({{ $index }})"
                                            aria-label="Remove line"
                                            style="color: #B85C42; background: none; border: none; cursor: pointer; font-size: 18px;">×</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" wire:click="addLine" class="lp-btn" data-testid="store-receive-add-line" style="justify-self: start;">+ Add another line</button>
        @endif

        <div>
            <label for="r-note" class="lp-form-label">Delivery note</label>
            <input id="r-note" type="text" wire:model="note" maxlength="240"
                   data-testid="store-receive-note" class="lp-input" placeholder="Supplier + invoice #, or any other reference">
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.store.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" @disabled($store === null || $products->isEmpty()) class="lp-btn lp-btn--primary" data-testid="store-receive-save">
                Record delivery
            </button>
        </div>
    </form>
</div>
