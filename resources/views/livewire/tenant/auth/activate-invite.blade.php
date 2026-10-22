@php
    $firstName = $inviteeName ? explode(' ', $inviteeName)[0] : null;
@endphp

{{-- Staff invite activation, in the sign-in shell. --}}
<x-auth-shell subtitle="Activate your account">
    <div data-testid="activate-invite-card">
        <h1 class="cx-title">Welcome{{ $firstName ? ', '.$firstName : '' }}.</h1>
        <p class="cx-lede">Choose a password to activate your staff account at {{ $tenantName ?: 'this property' }}. You'll use it to sign in from now on.</p>

        @if ($inviteeEmail)
            <p class="cx-meta" data-testid="activate-invite-email">Activating <strong>{{ $inviteeEmail }}</strong></p>
        @endif

        <form wire:submit="activate" data-testid="activate-invite-form" class="cx-form">
            <div data-password-field>
                <div class="cx-label-row"><label for="activate-password" class="cx-label">New password</label></div>
                <div class="cx-input-wrap">
                    <input id="activate-password" type="password" autocomplete="new-password" required
                           wire:model="password" data-testid="activate-password"
                           class="cx-input @error('password') is-invalid @enderror">
                    <button type="button" class="cx-toggle" data-password-toggle aria-label="Show password" aria-pressed="false"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/><circle cx="10" cy="10" r="2.5"/><path class="kv-slash" d="M3 3l14 14"/></svg></button>
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
