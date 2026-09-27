<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Recipe</span>
            <h1 class="lp-display mt-3 text-[clamp(24px,3vw,30px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $product->name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                What is this made from? Each sale decrements every component by (recipe qty × sale qty), so ingredient stock stays honest even when you don't count assembled drinks.
            </p>
        </div>
    </div>

    @if (session('status'))
        <div class="lp-flash-success">{{ session('status') }}</div>
    @endif
    @error('items') <div class="lp-flash-error">{{ $message }}</div> @enderror

    <form wire:submit="save" class="lp-dash-card" data-testid="recipe-form" style="display: grid; gap: 18px;">
        @if ($candidates->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);" data-testid="recipe-no-components">
                No stock-tracked components in {{ $product->outlet->name }} to build a recipe from. Add some products with tracked stock first.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th>Component</th>
                            <th style="text-align: right;">Quantity per {{ $product->name }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $index => $item)
                            <tr>
                                <td>
                                    <select wire:model="items.{{ $index }}.component_product_id" class="lp-input"
                                            data-testid="recipe-component-{{ $index }}" style="min-width: 260px;">
                                        <option value="">- pick a component -</option>
                                        @foreach ($candidates as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }} · on hand {{ $c->quantity_on_hand }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="text-align: right;">
                                    <input type="number" wire:model="items.{{ $index }}.quantity" min="1"
                                           data-testid="recipe-qty-{{ $index }}"
                                           class="lp-input" style="width: 110px; text-align: right;">
                                </td>
                                <td>
                                    <button type="button" wire:click="removeItem({{ $index }})"
                                            aria-label="Remove"
                                            style="color: #B85C42; background: none; border: none; cursor: pointer; font-size: 18px;">×</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" wire:click="addItem" class="lp-btn" data-testid="recipe-add" style="justify-self: start;">+ Add component</button>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <button type="button" wire:click="clear" wire:confirm="Remove the recipe and sell this as a discrete unit again?"
                    class="lp-btn" style="color: #B85C42;" data-testid="recipe-clear">Clear recipe</button>
            <div class="flex items-center gap-3">
                <a href="{{ route('tenant.inventory.outlets.show', $product->outlet_id) }}" wire:navigate class="lp-btn">Cancel</a>
                <button type="submit" @disabled($candidates->isEmpty()) class="lp-btn lp-btn--primary" data-testid="recipe-save">
                    Save recipe
                </button>
            </div>
        </div>
    </form>
</div>
