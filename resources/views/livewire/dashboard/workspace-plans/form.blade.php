@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    <h1 class="lp-display mt-4 text-[clamp(26px,3.4vw,36px)]"
        style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
        {{ $isEdit ? 'Edit plan' : 'New plan' }}
    </h1>
    <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
        Plans describe how a workspace is sold, monthly memberships, day passes, hourly rates.
    </p>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;" data-testid="plan-form">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="p-name">Plan name</label>
                <input id="p-name" type="text" wire:model.live="name"
                       class="lp-input" required maxlength="120" data-testid="name"
                       placeholder="e.g. Hot Desk Monthly">
                @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="p-ws">Workspace</label>
                <select id="p-ws" wire:model.live="workspace_id" class="lp-input" data-testid="workspace">
                    <option value="">Any workspace</option>
                    @foreach ($workspaces as $w)
                        <option value="{{ $w->id }}">{{ $w->name }} · {{ $w->type->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="p-int">Billing interval</label>
                <select id="p-int" wire:model.live="billing_interval" class="lp-input" data-testid="interval">
                    @foreach ($intervals as $i)
                        <option value="{{ $i->value }}">{{ $i->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="lp-form-label" for="p-pr">Price (₦)</label>
                <input id="p-pr" type="number" min="0" wire:model.live="price_naira"
                       class="lp-input" data-testid="price">
                @error('price_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="p-cap">Member cap</label>
                <input id="p-cap" type="number" min="1" wire:model.live="member_cap"
                       class="lp-input" placeholder="Optional" data-testid="cap">
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="p-desc">What's included</label>
            <textarea id="p-desc" wire:model.live="description" rows="4" maxlength="5000"
                      class="lp-input" data-testid="description"
                      placeholder="Wi-Fi, coffee, meeting room credits, mailbox…"></textarea>
        </div>

        <label class="flex items-center gap-2 text-[13px]" style="color: var(--color-ink-soft);">
            <input type="checkbox" wire:model.live="is_active"
                   style="accent-color: var(--color-accent-deep);" data-testid="active">
            Show on public site
        </label>

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Create plan' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
