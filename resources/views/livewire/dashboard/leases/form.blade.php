@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    @include('_partials._back-button')

    <h1 class="lp-display mt-4 text-[clamp(26px,3.4vw,36px)]"
        style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
        {{ $isEdit ? 'Edit lease' : 'New lease' }}
    </h1>
    <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
        Saving creates the rent collection schedule for this lease automatically.
    </p>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 20px;"
          enctype="multipart/form-data" data-testid="lease-form">

        {{-- Tenant block --}}
        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="l-name">Tenant name</label>
                <input id="l-name" type="text" wire:model.live="customer_name"
                       class="lp-input" required maxlength="120" data-testid="cust-name">
                @error('customer_name') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="l-email">Email</label>
                <input id="l-email" type="email" wire:model.live="customer_email"
                       class="lp-input" maxlength="160" data-testid="cust-email">
            </div>
            <div>
                <label class="lp-form-label" for="l-phone">Phone</label>
                <input id="l-phone" type="text" wire:model.live="customer_phone"
                       class="lp-input" maxlength="32" data-testid="cust-phone">
            </div>
        </div>

        <div>
            <label class="lp-form-label" for="l-unit">Unit</label>
            <select id="l-unit" wire:model.live="unit_id" class="lp-input" required data-testid="unit">
                <option value="">Choose a unit…</option>
                @foreach ($units as $u)
                    <option value="{{ $u->id }}">{{ $u->name }} · {{ $u->address }}</option>
                @endforeach
            </select>
            @error('unit_id') <p class="lp-form-error">{{ $message }}</p> @enderror
        </div>

        {{-- Term --}}
        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label class="lp-form-label" for="l-start">Started</label>
                <input id="l-start" type="date" wire:model.live="started_at" class="lp-input" required data-testid="started">
            </div>
            <div>
                <label class="lp-form-label" for="l-exp">Expires</label>
                <input id="l-exp" type="date" wire:model.live="expires_at" class="lp-input" required data-testid="expires">
            </div>
            <div>
                <label class="lp-form-label" for="l-tm">Term (months)</label>
                <input id="l-tm" type="number" min="1" max="120" wire:model.live="term_months"
                       class="lp-input" required data-testid="term">
            </div>
        </div>

        {{-- Money --}}
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="lp-form-label" for="l-ar">Annual rent (₦)</label>
                <input id="l-ar" type="number" min="0" wire:model.live="annual_rent_naira"
                       class="lp-input" required data-testid="annual">
                @error('annual_rent_naira') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="lp-form-label" for="l-mr">Monthly rent (₦)</label>
                <input id="l-mr" type="number" min="0" wire:model.live="monthly_rent_naira"
                       class="lp-input" data-testid="monthly">
            </div>
            <div>
                <label class="lp-form-label" for="l-dep">Deposit (₦)</label>
                <input id="l-dep" type="number" min="0" wire:model.live="deposit_naira"
                       class="lp-input" data-testid="deposit">
            </div>
            <div>
                <label class="lp-form-label" for="l-sc">Service charge (₦)</label>
                <input id="l-sc" type="number" min="0" wire:model.live="service_charge_naira"
                       class="lp-input" data-testid="service-charge">
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="lp-form-label" for="l-rem">Renewal reminder (days before)</label>
                <input id="l-rem" type="number" min="0" max="365"
                       wire:model.live="renewal_reminder_days" class="lp-input" data-testid="reminder">
            </div>
            <div>
                <label class="lp-form-label" for="l-st">Status</label>
                <select id="l-st" wire:model.live="status" class="lp-input" required data-testid="status">
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Document --}}
        <div>
            <label class="lp-form-label" for="l-doc">Lease document (PDF)</label>
            @if ($existingDocumentPath)
                <p class="text-[12.5px]" style="color: var(--color-ink-soft);">
                    Current: <a href="{{ \Storage::disk('public')->url($existingDocumentPath) }}" target="_blank" rel="noopener"
                                style="text-decoration: underline;">view existing PDF</a>
                </p>
            @endif
            <input id="l-doc" type="file" wire:model="document" accept="application/pdf" class="lp-input" data-testid="document">
            @error('document') <p class="lp-form-error">{{ $message }}</p> @enderror
            <p class="lp-form-help">Max 10 MB. PDF only.</p>
        </div>

        <div>
            <label class="lp-form-label" for="l-notes">Notes</label>
            <textarea id="l-notes" wire:model.live="notes" rows="3" maxlength="8000"
                      class="lp-input" data-testid="notes"></textarea>
        </div>

        @if (! $isEdit && $corporates->isNotEmpty())
            <fieldset class="lp-fieldset" data-testid="corporate-fieldset">
                <legend>Corporate (optional)</legend>
                <p class="lp-form-help" style="margin-top: 4px;">
                    Lease to one of your corporate clients. The first year's rent + deposit + service charge
                    is held against their wallet at the negotiated rate.
                </p>
                <div class="grid gap-5 sm:grid-cols-2 mt-3">
                    <div>
                        <label class="lp-form-label" for="l-corp">Corporate</label>
                        <select id="l-corp" wire:model.live="corporate_id" class="lp-input" data-testid="corporate-id">
                            <option value="">- No corporate -</option>
                            @foreach ($corporates as $corp)
                                <option value="{{ $corp->id }}">{{ $corp->name }}</option>
                            @endforeach
                        </select>
                        @error('corporate_id') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="lp-form-label" for="l-mem">Member</label>
                        <select id="l-mem" wire:model.live="corporate_member_id" class="lp-input"
                                data-testid="corporate-member-id"
                                @if ($corporate_id === null) disabled @endif>
                            <option value="">- Bill the company directly -</option>
                            @foreach ($corporateMembers as $mem)
                                <option value="{{ $mem->id }}">{{ $mem->name }} · {{ $mem->email }}</option>
                            @endforeach
                        </select>
                        @error('corporate_member_id') <p class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>
        @endif

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Create lease' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
