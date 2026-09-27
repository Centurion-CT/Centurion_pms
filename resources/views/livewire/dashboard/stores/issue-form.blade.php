<div class="lp-dash">

    <div>
        <span class="lp-eyebrow-strip">Business</span>
        <div class="flex flex-wrap items-start justify-between gap-3 mt-3">
            <div>
                <h1 class="lp-display text-[clamp(24px,3vw,32px)]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                    Issue to outlet
                </h1>
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                    Release stock from the store to an outlet. Writes a paired
                    ledger entry: &minus;qty at the store, &plus;qty at the outlet.
                </p>
            </div>
            <div>
                <a href="{{ route('tenant.store.index') }}" wire:navigate class="lp-btn">
                    &larr; Back to Store
                </a>
            </div>
        </div>
    </div>

    @if ($store === null)
        <section class="lp-dash-card" data-testid="issue-no-store"
                 style="padding: 32px 24px; text-align: center;">
            <p style="color: var(--color-ink-mute);">Central store not provisioned. Contact support.</p>
        </section>
    @elseif ($storeProducts->isEmpty())
        <section class="lp-dash-card" data-testid="issue-empty-store"
                 style="padding: 32px 24px; text-align: center;">
            <h2 class="text-[17px]" style="color: var(--color-ink); font-weight: 500;">
                Nothing to issue yet.
            </h2>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                Receive stock from a supplier first. The store is empty.
            </p>
        </section>
    @else
        <form wire:submit.prevent="submit" class="lp-dash-card" data-testid="issue-form">
            <div class="grid gap-4">
                <label class="lp-field">
                    <span class="lp-field-label">Store product</span>
                    <select wire:model.live="storeProductId"
                            class="lp-input" data-testid="issue-store-product">
                        <option value="">- Select store product -</option>
                        @foreach ($storeProducts as $sp)
                            <option value="{{ $sp->id }}">
                                {{ $sp->name }} ({{ $sp->quantity_on_hand }} on hand)
                            </option>
                        @endforeach
                    </select>
                    @error('storeProductId') <span class="lp-field-error">{{ $message }}</span> @enderror
                </label>

                <label class="lp-field">
                    <span class="lp-field-label">Send to outlet</span>
                    <select wire:model.live="outletId"
                            class="lp-input" data-testid="issue-outlet">
                        <option value="">- Select outlet -</option>
                        @foreach ($outlets as $o)
                            <option value="{{ $o->id }}">{{ $o->name }}</option>
                        @endforeach
                    </select>
                    @error('outletId') <span class="lp-field-error">{{ $message }}</span> @enderror
                </label>

                <label class="lp-field">
                    <span class="lp-field-label">Outlet product to receive</span>
                    <select wire:model="outletProductId"
                            class="lp-input"
                            @disabled($outletId === null)
                            data-testid="issue-outlet-product">
                        <option value="">
                            @if ($outletId === null)
                               , Pick an outlet first -
                            @elseif ($outletProducts->isEmpty())
                               , No stock-tracked products at this outlet -
                            @else
                               , Select outlet product -
                            @endif
                        </option>
                        @foreach ($outletProducts as $op)
                            <option value="{{ $op->id }}">{{ $op->name }}</option>
                        @endforeach
                    </select>
                    @error('outletProductId') <span class="lp-field-error">{{ $message }}</span> @enderror
                </label>

                <label class="lp-field">
                    <span class="lp-field-label">Quantity</span>
                    <input type="number" min="1" step="1" wire:model="quantity"
                           class="lp-input" data-testid="issue-quantity">
                    @error('quantity') <span class="lp-field-error">{{ $message }}</span> @enderror
                </label>

                <label class="lp-field">
                    <span class="lp-field-label">Note (optional)</span>
                    <input type="text" wire:model="note" placeholder="e.g. Weekend restock"
                           class="lp-input" data-testid="issue-note">
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" class="lp-btn lp-btn--primary"
                            data-testid="issue-submit">
                        Issue to outlet
                    </button>
                    <a href="{{ route('tenant.store.index') }}" wire:navigate
                       class="lp-btn">Cancel</a>
                </div>
            </div>
        </form>
    @endif
</div>
