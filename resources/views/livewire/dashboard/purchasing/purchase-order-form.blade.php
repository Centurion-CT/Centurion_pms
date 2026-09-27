<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Purchasing</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $orderId ? 'Edit draft PO' : 'New purchase order' }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Pick a supplier + destination outlet, then add lines. Save as draft or send now, either way, stock only moves on receipt.
            </p>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" data-testid="po-form" style="display: grid; gap: 18px;">
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label for="p-supplier" class="lp-form-label">Supplier *</label>
                <select id="p-supplier" wire:model="supplierId" class="lp-input" data-testid="po-supplier" @disabled($orderId) required>
                    <option value="">- pick a supplier -</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('supplierId') <span class="lp-form-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="p-outlet" class="lp-form-label">Deliver to outlet *</label>
                <select id="p-outlet" wire:model.live="outletId" class="lp-input" data-testid="po-outlet" @disabled($orderId) required>
                    <option value="">- pick an outlet -</option>
                    @foreach ($outlets as $o)
                        <option value="{{ $o->id }}">{{ $o->name }}</option>
                    @endforeach
                </select>
                @error('outletId') <span class="lp-form-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="p-expected" class="lp-form-label">Expected date</label>
                <input id="p-expected" type="date" wire:model="expectedAt" class="lp-input" data-testid="po-expected">
            </div>
        </div>

        @if (! $outletId && ! $orderId)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">
                Pick an outlet first, line items come from that outlet's product list.
            </p>
        @elseif ($products->isEmpty() && ! $orderId)
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="po-no-products">
                That outlet has no stock-tracked products yet.
                <a href="{{ route('tenant.inventory.outlets.show', $outletId) }}" wire:navigate
                   style="color: var(--color-accent-deep); text-decoration: underline;">Add some first.</a>
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align: right;">Quantity</th>
                            <th style="text-align: right;">Unit cost (&#8358;)</th>
                            <th style="text-align: right;">Line total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lines as $index => $line)
                            <tr>
                                <td>
                                    <select wire:model="lines.{{ $index }}.product_id" class="lp-input"
                                            data-testid="po-line-product-{{ $index }}"
                                            style="min-width: 240px;" @disabled($orderId)>
                                        <option value="">- pick a product -</option>
                                        @foreach ($products as $p)
                                            <option value="{{ $p->id }}">{{ $p->name }} · on hand {{ $p->quantity_on_hand }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="text-align: right;">
                                    <input type="number" wire:model.live="lines.{{ $index }}.quantity" min="1"
                                           data-testid="po-line-qty-{{ $index }}" class="lp-input"
                                           style="width: 110px; text-align: right;" @disabled($orderId)>
                                </td>
                                <td style="text-align: right;">
                                    <input type="number" wire:model.live="lines.{{ $index }}.unit_cost" min="0"
                                           data-testid="po-line-cost-{{ $index }}" class="lp-input"
                                           style="width: 130px; text-align: right;" @disabled($orderId)>
                                </td>
                                <td style="text-align: right;" class="lp-mono">
                                    &#8358;{{ number_format((int) ($line['quantity'] ?? 0) * (int) ($line['unit_cost'] ?? 0)) }}
                                </td>
                                <td>
                                    @if (! $orderId)
                                        <button type="button" wire:click="removeLine({{ $index }})"
                                                aria-label="Remove"
                                                style="color: #B85C42; background: none; border: none; cursor: pointer; font-size: 18px;">×</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td colspan="3" style="text-align: right; font-weight: 600; color: var(--color-ink);">Subtotal</td>
                            <td style="text-align: right;">
                                <span class="lp-table-amount" data-testid="po-subtotal">&#8358;{{ number_format($subtotal) }}</span>
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            @if (! $orderId)
                <button type="button" wire:click="addLine" class="lp-btn"
                        data-testid="po-add-line" style="justify-self: start;">+ Add another line</button>
            @endif
        @endif

        <div>
            <label for="p-notes" class="lp-form-label">Notes</label>
            <textarea id="p-notes" wire:model="notes" rows="2" maxlength="2000"
                      class="lp-input" data-testid="po-notes"
                      placeholder="Delivery instructions, supplier invoice ref, etc."></textarea>
        </div>

        @if (! $orderId)
            <label class="inline-flex items-center gap-2 text-[13.5px]" style="color: var(--color-ink-soft);">
                <input type="checkbox" wire:model="sendNow" data-testid="po-send-now">
                Send now, I've already placed the order with the supplier.
            </label>
        @endif

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.purchasing.orders.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-btn lp-btn--primary" data-testid="po-save">
                {{ $orderId ? 'Save changes' : 'Create order' }}
            </button>
        </div>
    </form>
</div>
