<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Settings</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Your business
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            How guests and staff see your business: on your booking site, the staff login and in emails.
        </p>
    </div>

    @include('livewire.dashboard.settings._tabs', ['active' => 'business'])

    <form wire:submit="save" class="lp-dash-card" style="display: grid; gap: 18px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Business details</span>
        </div>

        <div>
            <label for="biz-name" class="lp-form-label">Business name</label>
            <input id="biz-name" type="text" wire:model="name" required maxlength="120" class="lp-input" data-testid="business-name">
            @error('name') <p class="lp-form-error">{{ $message }}</p> @enderror
            <p class="lp-form-help mt-1">Shown on your booking site, the staff login, receipts and as the sender of guest emails.</p>
        </div>
        <div>
            <label for="biz-title" class="lp-form-label">Booking site title <span style="color: var(--color-ink-mute); font-weight: 400;">(optional)</span></label>
            <input id="biz-title" type="text" wire:model="siteTitle" maxlength="120" class="lp-input" placeholder="{{ $name }}">
            @error('siteTitle') <p class="lp-form-error">{{ $message }}</p> @enderror
            <p class="lp-form-help mt-1">What search results and browser tabs call your booking site. Leave empty to use the business name.</p>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="biz-phone" class="lp-form-label">Phone</label>
                <input id="biz-phone" type="tel" wire:model="phone" maxlength="30" class="lp-input" placeholder="0803 000 0000">
                @error('phone') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="biz-email" class="lp-form-label">Email</label>
                <input id="biz-email" type="email" wire:model="email" required maxlength="190" class="lp-input">
                @error('email') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <p class="lp-form-help" style="margin-top: -6px;">Guests see this phone and email on your booking site.</p>

        <div style="display: flex; align-items: center; gap: 14px; padding-top: 4px; border-top: 1px solid var(--color-line-soft);">
            @if ($logo)
                <img src="{{ $logo }}" alt="" style="width: 44px; height: 44px; object-fit: contain; border-radius: 10px; background: #fff; box-shadow: inset 0 0 0 1px var(--color-line);">
                <p class="lp-form-help" style="margin: 0;">Your logo. Change it under <a href="{{ route('tenant.branding.photos') }}" wire:navigate>Photos</a>.</p>
            @else
                <p class="lp-form-help" style="margin: 0;">No logo yet. Upload one under <a href="{{ route('tenant.branding.photos') }}" wire:navigate>Photos</a>; until then the {{ config('brand.name') }} mark is shown.</p>
            @endif
        </div>

        <div class="flex justify-end">
            <button type="submit" class="lp-btn lp-btn--primary">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </form>
</div>
