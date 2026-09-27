@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    @include('_partials._back-button')

    <div class="mt-4">
        <span class="lp-eyebrow-strip">B2B</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            {{ $isEdit ? 'Edit corporate' : 'Onboard corporate' }}
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            A wallet is created automatically. Members, rates and domains are managed from the next page.
        </p>
    </div>

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 22px;" data-testid="corporate-form">

        <fieldset class="lp-fieldset">
            <legend>Company</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div class="sm:col-span-2">
                    <label class="lp-form-label" for="c-name">Legal name</label>
                    <input id="c-name" type="text" wire:model.live="name" class="lp-input" required maxlength="160" data-testid="name">
                    @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lp-form-label" for="c-pe">Primary email</label>
                    <input id="c-pe" type="email" wire:model.live="primary_email" class="lp-input" required maxlength="180" data-testid="primary-email">
                    @error('primary_email') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="lp-form-label" for="c-pp">Primary phone</label>
                    <input id="c-pp" type="text" wire:model.live="primary_phone" class="lp-input" maxlength="32" data-testid="primary-phone">
                </div>
                <div>
                    <label class="lp-form-label" for="c-be">Billing email</label>
                    <input id="c-be" type="email" wire:model.live="billing_email" class="lp-input" maxlength="180" placeholder="Optional · same as primary if blank">
                </div>
                <div>
                    <label class="lp-form-label" for="c-cn">Country (ISO 2)</label>
                    <input id="c-cn" type="text" wire:model.live="country" class="lp-input" maxlength="2" required style="text-transform: uppercase;">
                </div>
                <div class="sm:col-span-2">
                    <label class="lp-form-label" for="c-ad">Address</label>
                    <input id="c-ad" type="text" wire:model.live="address" class="lp-input" maxlength="240">
                </div>
                <div>
                    <label class="lp-form-label" for="c-ct">City</label>
                    <input id="c-ct" type="text" wire:model.live="city" class="lp-input" maxlength="80">
                </div>
            </div>
        </fieldset>

        <fieldset class="lp-fieldset">
            <legend>Compliance</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label class="lp-form-label" for="c-rn">Registration number</label>
                    <input id="c-rn" type="text" wire:model.live="registration_number" class="lp-input" maxlength="64">
                </div>
                <div>
                    <label class="lp-form-label" for="c-tx">Tax ID</label>
                    <input id="c-tx" type="text" wire:model.live="tax_id" class="lp-input" maxlength="64">
                </div>
                <div>
                    <label class="lp-form-label" for="c-st">Account status</label>
                    <select id="c-st" wire:model.live="status" class="lp-input" required>
                        @foreach ($statusOptions as $opt)
                            <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="lp-form-label" for="c-kyc">KYC status</label>
                    <select id="c-kyc" wire:model.live="kyc_status" class="lp-input" required>
                        @foreach ($kycOptions as $opt)
                            <option value="{{ $opt->value }}">{{ $opt->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="lp-form-label" for="c-kn">KYC notes</label>
                    <textarea id="c-kn" wire:model.live="kyc_notes" rows="3" maxlength="5000" class="lp-input"
                              placeholder="Docs received, follow-ups outstanding…"></textarea>
                </div>
            </div>
        </fieldset>

        <fieldset class="lp-fieldset">
            <legend>Spend controls</legend>
            <div class="grid gap-5 sm:grid-cols-2 mt-2">
                <div>
                    <label class="lp-form-label" for="c-ap">Pre-approval threshold (₦)</label>
                    <input id="c-ap" type="number" min="0" wire:model.live="approval_threshold_naira" class="lp-input"
                           placeholder="Blank = no pre-approval required">
                    <p class="lp-form-help" style="margin-top: 6px;">
                        Bookings above this amount notify the corporate admin before confirming.
                    </p>
                </div>
                <div>
                    <label class="lp-form-label" for="c-no">Internal notes</label>
                    <textarea id="c-no" wire:model.live="notes" rows="3" maxlength="5000" class="lp-input"></textarea>
                </div>
            </div>
        </fieldset>

        <div class="flex justify-end pt-2" style="border-top: 1px solid var(--color-line-soft);">
            <button type="submit" class="lp-pill" data-testid="save">
                <span wire:loading.remove wire:target="save">{{ $isEdit ? 'Save changes' : 'Create corporate' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
                <span class="lp-pill-dot">{!! $pillArrow !!}</span>
            </button>
        </div>
    </form>
</div>
