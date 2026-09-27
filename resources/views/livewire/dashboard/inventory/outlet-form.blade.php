<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $outletId ? 'Edit outlet' : 'New outlet' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $outletId ? 'Edit outlet' : 'Create an outlet' }}
            </h1>
        </div>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;">
        <div>
            <label for="o-name" class="lp-form-label">Name</label>
            <input id="o-name" type="text" wire:model="name" required maxlength="120"
                   data-testid="outlet-name" class="lp-input" placeholder="Rooftop Bar">
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="o-type" class="lp-form-label">Type</label>
                <select id="o-type" wire:model="type" class="lp-input" data-testid="outlet-type">
                    @foreach ($outletTypes as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
                <p class="lp-form-help mt-1">Drives the room-charge category (Bar/Kitchen/Restaurant → Food &amp; beverage, Laundry → Laundry, everything else → Room service).</p>
            </div>
            <div>
                <label for="o-tax" class="lp-form-label">
                    Tax rate (%)
                    @if (! $canManagePrices)
                        <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">&middot; managers only</span>
                    @endif
                </label>
                @if ($canManagePrices)
                    <input id="o-tax" type="number" wire:model="tax_rate" min="0" max="100" step="0.01"
                           data-testid="outlet-tax" class="lp-input" placeholder="0">
                    <p class="lp-form-help mt-1">Applied on top of subtotal on every sale. Leave 0 for tax-inclusive pricing.</p>
                    @error('tax_rate') <p class="lp-form-error">{{ $message }}</p> @enderror
                @else
                    <div class="lp-input lp-mono" data-testid="outlet-tax-readonly"
                         style="background: color-mix(in srgb, var(--color-ink) 4%, transparent); cursor: not-allowed;">
                        {{ rtrim(rtrim($tax_rate, '0'), '.') ?: '0' }}%
                    </div>
                    <p class="lp-form-help mt-1">Ask a manager if the tax rate needs to change.</p>
                @endif
            </div>
        </div>

        <fieldset class="lp-fieldset">
            <legend>Active</legend>
            <label class="flex items-start gap-3 text-[13.5px]" style="color: var(--color-ink);">
                <input type="checkbox" wire:model="active" data-testid="outlet-active"
                       style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                <span>
                    Show in POS terminals + product catalogs. Uncheck to archive without deleting history.
                </span>
            </label>
        </fieldset>

        <fieldset class="lp-fieldset" data-testid="outlet-operators-block">
            <legend>PosStaff operators assigned to this outlet</legend>
            @if ($posStaffOperators->isEmpty())
                <p class="lp-form-help mt-2">
                    No PosStaff on your team yet. Add them in
                    <a href="{{ route('tenant.staff.index') }}" wire:navigate
                       style="color: var(--color-accent-deep); text-decoration: underline;">Staff</a>,
                    then come back to assign them here. Managers see every outlet by default and don&rsquo;t need to be listed.
                </p>
            @else
                <div class="grid gap-2 mt-2">
                    @foreach ($posStaffOperators as $op)
                        <label class="flex items-start gap-3 text-[13.5px]" style="color: var(--color-ink);">
                            <input type="checkbox" wire:model="assignedOperatorIds" value="{{ $op->id }}"
                                   data-testid="outlet-op-{{ $op->id }}"
                                   style="accent-color: var(--color-accent-deep); margin-top: 3px;">
                            <span>
                                <strong style="font-weight: 500;">{{ $op->name }}</strong>
                                <span class="lp-mono text-[11px]" style="display:block; color: var(--color-ink-mute);">{{ $op->email }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </fieldset>

        <div>
            <label for="o-notes" class="lp-form-label">Notes <span style="color: var(--color-ink-mute); text-transform: none; letter-spacing: 0;">· optional, internal-only</span></label>
            <textarea id="o-notes" wire:model="notes" rows="3" class="lp-input" data-testid="outlet-notes"
                      placeholder="Opening hours, cash-drawer conventions, anything the team should know."></textarea>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2"
             style="border-top: 1px solid var(--color-line-soft);">
            <a href="{{ route('tenant.inventory.index') }}" wire:navigate class="lp-btn">Cancel</a>
            <button type="submit" class="lp-btn lp-btn--primary" data-testid="outlet-save">
                {{ $outletId ? 'Save changes' : 'Create outlet' }}
            </button>
        </div>
    </form>
</div>
