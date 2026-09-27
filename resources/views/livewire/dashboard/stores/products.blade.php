<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="lp-eyebrow-strip">Business</span>
                <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Store products
                </h1>
                <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                    Master catalog of SKUs held in the central store. Outlets request
                    from this list, approved requests auto-provision the outlet-side product.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="toggleArchived"
                        class="lp-btn" data-testid="store-products-toggle-archived">
                    {{ $showArchived ? 'Hide archived' : 'Show archived' }}
                </button>
                @if ($canManagePrices)
                    <a href="{{ route('tenant.store.products.create') }}" wire:navigate
                       class="lp-btn lp-btn--primary" data-testid="store-add-product">
                        + Add product
                    </a>
                @endif
            </div>
        </div>
    </div>
    <section class="lp-dash-card">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Catalog</span>
            <span class="lp-dash-card-meta">
                {{ $products->count() }} {{ Str::plural('product', $products->count()) }}
                @if ($showArchived) · <em>including archived</em> @endif
            </span>
        </div>

        @if ($store === null)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="store-products-no-store">
                Central store not provisioned. Contact support.
            </p>
        @elseif ($products->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="store-products-empty">
                @if ($canManagePrices)
                    No {{ $showArchived ? '' : 'active ' }}store products yet.
                    Hit <strong>+ Add product</strong> to seed the catalog, that's what outlets will request from.
                @else
                    No {{ $showArchived ? '' : 'active ' }}store products yet.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: right;">Sell price</th>
                            <th style="text-align: right;">Cost</th>
                            <th style="text-align: right;">On hand</th>
                            <th style="text-align: right;">Low threshold</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $p)
                            <tr data-testid="store-product-row-{{ $p->id }}">
                                <td style="color: var(--color-ink); font-weight: 500;">
                                    {{ $p->name }}
                                    @if (! $p->active)
                                        <span class="lp-mono text-[10.5px]" style="margin-left: 8px; padding: 2px 6px; border-radius: 999px; background: var(--color-card-deep); color: var(--color-ink-mute); letter-spacing: 0.06em; text-transform: uppercase;">Archived</span>
                                    @endif
                                    @if ($p->sku)
                                        <span class="lp-mono text-[10.5px]" style="display:block; color: var(--color-ink-mute);">SKU {{ $p->sku }}</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <span class="lp-table-amount">&#8358;{{ number_format((int) $p->price) }}</span>
                                </td>
                                <td style="text-align: right; color: var(--color-ink-soft);">
                                    <span class="lp-table-amount">&#8358;{{ number_format((int) $p->cost) }}</span>
                                </td>
                                <td style="text-align: right;" class="lp-mono" style="font-weight: 600;
                                        color: {{ $p->low_stock_threshold > 0 && $p->quantity_on_hand <= $p->low_stock_threshold ? '#B85C42' : 'var(--color-ink)' }};">
                                    {{ number_format($p->quantity_on_hand) }}
                                </td>
                                <td style="text-align: right;" class="lp-mono">
                                    {{ $p->low_stock_threshold > 0 ? number_format($p->low_stock_threshold) : '-' }}
                                </td>
                                <td class="lp-mono text-[11px]" style="text-align: right; white-space: nowrap;">
                                    <a href="{{ route('tenant.store.products.edit', $p) }}" wire:navigate
                                       data-testid="store-product-edit-{{ $p->id }}"
                                       style="color: var(--color-accent-deep); text-decoration: underline;">Edit</a>
                                    <span style="color: var(--color-line);"> · </span>
                                    <button type="button" wire:click="toggleActive({{ $p->id }})"
                                            data-testid="store-product-toggle-{{ $p->id }}"
                                            style="color: {{ $p->active ? '#B85C42' : 'var(--color-accent-deep)' }}; text-decoration: underline; background: none; border: none; padding: 0; cursor: pointer;">
                                        {{ $p->active ? 'Archive' : 'Restore' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
