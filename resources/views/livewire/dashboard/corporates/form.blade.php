<div class="lp-dash">

    @include('_partials._back-button')

    <div class="mt-4">
        <span class="lp-eyebrow-strip">Corporate</span>
        <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
            style="color: var(--color-ink); font-weight: 600;">
            {{ $isEdit ? 'Edit corporate' : 'Register a corporate' }}
        </h1>
        <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
            Set up the org so their profiled staff can check in without payment collection.
            Payment is billed to the corp at checkout.
        </p>
    </div>

    <section class="lp-dash-card">
        <form wire:submit.prevent="save" class="grid gap-3">
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="lp-form-label" for="corp-name">Name</label>
                    <input id="corp-name" type="text" wire:model="name"
                           data-testid="corp-name" class="lp-input" required>
                </div>
                <div>
                    <label class="lp-form-label" for="corp-email">Primary contact email</label>
                    <input id="corp-email" type="email" wire:model="primary_email"
                           data-testid="corp-email" class="lp-input" required>
                </div>
                <div>
                    <label class="lp-form-label" for="corp-phone">
                        Contact phone
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="corp-phone" type="text" wire:model="primary_phone"
                           data-testid="corp-phone" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="corp-billing-email">
                        Billing email
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="corp-billing-email" type="email" wire:model="billing_email"
                           data-testid="corp-billing-email" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="corp-address">
                        Address
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="corp-address" type="text" wire:model="address"
                           data-testid="corp-address" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="corp-city">
                        City
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="corp-city" type="text" wire:model="city"
                           data-testid="corp-city" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="corp-country">Country (ISO 2-letter)</label>
                    <input id="corp-country" type="text" wire:model="country" maxlength="2"
                           data-testid="corp-country" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="corp-reg">
                        Reg number
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="corp-reg" type="text" wire:model="registration_number"
                           data-testid="corp-reg" class="lp-input">
                </div>
                <div>
                    <label class="lp-form-label" for="corp-tax">
                        Tax ID
                        <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                    </label>
                    <input id="corp-tax" type="text" wire:model="tax_id"
                           data-testid="corp-tax" class="lp-input">
                </div>
            </div>
            <div>
                <label class="lp-form-label" for="corp-notes">
                    Notes
                    <span style="color: var(--color-ink-mute); font-weight: 400;">· optional</span>
                </label>
                <textarea id="corp-notes" wire:model="notes" rows="3"
                          data-testid="corp-notes" class="lp-input"></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-1">
                <a href="{{ route('tenant.corporates.index') }}" wire:navigate
                   class="lp-btn" style="font-size: 12.5px;">
                    Cancel
                </a>
                <button type="submit" data-testid="corp-save"
                        class="lp-btn lp-btn--primary" style="font-size: 12.5px;">
                    {{ $isEdit ? 'Save changes' : 'Register corporate' }}
                </button>
            </div>
        </form>
    </section>

</div>
