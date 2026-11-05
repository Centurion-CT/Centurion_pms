@php
    $firstName = $inviteeName ? explode(' ', $inviteeName)[0] : null;
@endphp

{{-- Staff invite activation, in the sign-in shell. --}}
<x-auth-shell>
    <div data-testid="activate-invite-card">
        <h1 class="cx-title">Welcome{{ $firstName ? ', '.$firstName : '' }}</h1>
        <p class="cx-lede">Choose a password to finish setting up your account at {{ $tenantName ?: 'this property' }}.</p>

        @if ($inviteeEmail)
            <p class="cx-meta" data-testid="activate-invite-email">You'll sign in as <strong>{{ $inviteeEmail }}</strong></p>
        @endif

        <form wire:submit="activate" data-testid="activate-invite-form" class="cx-form">
            <div data-password-field>
                <div class="cx-label-row"><label for="activate-password" class="cx-label">New password</label></div>
                <div class="cx-input-wrap">
                    <input id="activate-password" type="password" autocomplete="new-password" required
                           wire:model="password" data-testid="activate-password"
                           class="cx-input @error('password') is-invalid @enderror">
                    <button type="button" class="cx-toggle" data-password-toggle aria-label="Show password" aria-pressed="false"><span class="cx-show">Show</span><span class="cx-hide">Hide</span></button>
                </div>
                @error('password')
                    <p data-error="password" class="cx-error" role="alert">{{ $message }}</p>
                @else
                    <p class="cx-hint">At least 8 characters, with a letter and a number.</p>
                @enderror
            </div>

            <div>
                <div class="cx-label-row"><label for="activate-password-confirmation" class="cx-label">Confirm password</label></div>
                <input id="activate-password-confirmation" type="password" autocomplete="new-password" required
                       wire:model="password_confirmation" data-testid="activate-password-confirmation"
                       class="cx-input @error('password_confirmation') is-invalid @enderror">
                @error('password_confirmation')
                    <p data-error="password_confirmation" class="cx-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="cx-submit" wire:loading.attr="disabled" wire:target="activate" data-testid="activate-submit">
                <span wire:loading.remove wire:target="activate">Activate my account</span>
                <span wire:loading wire:target="activate">Activating…</span>
            </button>
        </form>
    </div>
</x-auth-shell>
