@php
    $tenantDisplayName = \App\Models\Tenant::current()?->name ?? 'Your property';
    $sent = $status !== '';
@endphp
{{-- Staff forgot password, in the same shell as the sign-in page. "Sent"
     reads the same for any email, so this can't reveal who works here. --}}
<x-auth-shell>
    @if ($sent)
        <div role="status">
            <h1 class="cx-title">Check your email</h1>
            <p class="cx-lede">If <strong>{{ $email }}</strong> belongs to someone at {{ $tenantDisplayName }}, we've sent a link to choose a new password.</p>
            <p class="cx-meta">The link works for {{ config('auth.passwords.operators.expire') }} minutes. Nothing after a few minutes? Check spam, or ask your manager.</p>
        </div>
        <div class="cx-actions">
            <a href="{{ route('tenant.login') }}" class="cx-submit">Back to sign in</a>
            <button type="button" wire:click="$set('status', '')" class="cx-secondary">Try another email</button>
        </div>
    @else
        <h1 class="cx-title">Reset your password</h1>
        <p class="cx-lede">Enter your work email and we'll send you a link to choose a new one.</p>
        <form wire:submit="sendResetLink" data-testid="forgot-form" class="cx-form">
            <div>
                <div class="cx-label-row"><label for="forgot-email" class="cx-label">Work email</label></div>
                <input id="forgot-email" type="email" wire:model="email" required autocomplete="email" autofocus
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
        <p class="cx-after"><a class="cx-link" href="{{ route('tenant.login') }}">Back to sign in</a></p>
    @endif
</x-auth-shell>
