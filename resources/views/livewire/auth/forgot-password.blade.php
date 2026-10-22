@php
    $tenantDisplayName = \App\Models\Tenant::current()?->name ?? 'Your property';
    $sent = $status !== '';
@endphp

{{-- Staff forgot password, in the same shell as the sign-in page.
     Two states: ask for the email, then "check your inbox". --}}
<x-auth-shell subtitle="Password reset">
    @if ($sent)
        <h1 class="cx-title">Check your inbox.</h1>
        <div class="cx-sent" role="status">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
            <p style="margin: 0;">We've sent a link to <strong>{{ $email }}</strong>. Open it to choose a new password for {{ $tenantDisplayName }}. It can take a minute to arrive.</p>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 20px;">
            <a href="{{ route('tenant.login') }}" class="cx-back">Back to sign in</a>
            <button type="button" wire:click="$set('status', '')" class="cx-back">Send it again</button>
        </div>
    @else
        <h1 class="cx-title">Forgot your password?</h1>
        <p class="cx-lede">Enter your staff email and we'll send you a link to choose a new one.</p>

        <form wire:submit="sendResetLink" data-testid="forgot-form" class="cx-form">
            <div>
                <div class="cx-label-row"><label for="forgot-email" class="cx-label">Email</label></div>
                <input id="forgot-email" type="email" wire:model="email" required autocomplete="email" autofocus
                       placeholder="Your work email"
                       class="cx-input @error('email') is-invalid @enderror" />
                @error('email')
                    <p data-error="email" class="cx-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="cx-submit" wire:loading.attr="disabled" wire:target="sendResetLink">
                <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
                <span wire:loading wire:target="sendResetLink">Sending…</span>
            </button>
        </form>

        <div><a href="{{ route('tenant.login') }}" class="cx-back">Back to sign in</a></div>
    @endif
</x-auth-shell>
