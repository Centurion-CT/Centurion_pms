{{-- Staff reset password (from the emailed link), in the sign-in shell. --}}
<x-auth-shell>
    <h1 class="cx-title">Choose a new password</h1>
    <p class="cx-lede">Use at least 8 characters. You'll be signed in as soon as it's saved.</p>

    <form wire:submit="resetPassword" data-testid="reset-form" class="cx-form">
        <div>
            <div class="cx-label-row"><label for="rp-email" class="cx-label">Work email</label></div>
            <input id="rp-email" type="email" wire:model="email" required autocomplete="email"
                   class="cx-input @error('email') is-invalid @enderror" />
            @error('email')
                <p data-error="email" class="cx-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div data-password-field>
            <div class="cx-label-row"><label for="rp-password" class="cx-label">New password</label></div>
            <div class="cx-input-wrap">
                <input id="rp-password" type="password" wire:model="password" required autocomplete="new-password"
                       class="cx-input @error('password') is-invalid @enderror" />
                <button type="button" class="cx-toggle" data-password-toggle aria-label="Show password" aria-pressed="false"><span class="cx-show">Show</span><span class="cx-hide">Hide</span></button>
            </div>
            @error('password')
                <p data-error="password" class="cx-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div data-password-field>
            <div class="cx-label-row"><label for="rp-password-confirm" class="cx-label">Confirm new password</label></div>
            <div class="cx-input-wrap">
                <input id="rp-password-confirm" type="password" wire:model="password_confirmation" required autocomplete="new-password"
                       class="cx-input" />
                <button type="button" class="cx-toggle" data-password-toggle aria-label="Show password" aria-pressed="false"><span class="cx-show">Show</span><span class="cx-hide">Hide</span></button>
            </div>
        </div>

        <button type="submit" class="cx-submit" wire:loading.attr="disabled" wire:target="resetPassword">
            <span wire:loading.remove wire:target="resetPassword">Save new password</span>
            <span wire:loading wire:target="resetPassword">Saving…</span>
        </button>
    </form>

    <p class="cx-after"><a class="cx-link" href="{{ route('tenant.login') }}">Back to sign in</a></p>
</x-auth-shell>
