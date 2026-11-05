@php
    $business = \App\Models\Tenant::current();
    $businessName = $business?->name ?? config('brand.name');
    $businessContact = trim((string) $business?->phone) ?: trim((string) $business?->email);
@endphp

{{-- Corporate portal sign-in, in the staff sign-in shell: the business
     leads, and its corporate customers are told plainly who this is for. --}}
<div data-testid="corporate-login">
    <x-auth-shell>
        <p class="cx-eyebrow">Corporate account</p>
        <h1 class="cx-title">Sign in to {{ $businessName }}</h1>
        <p class="cx-lede">Manage your team, top up your wallet and see your company's bookings.</p>

        @error('email')
            <p data-error="email" class="cx-alert" role="alert">{{ $message }}</p>
        @enderror

        <form wire:submit="authenticate" class="cx-form" x-data
              x-on:sign-in-refused.window="$nextTick(() => $el.querySelector('#c-pw')?.focus())">
            <div>
                <div class="cx-label-row"><label for="c-email" class="cx-label">Work email</label></div>
                <input id="c-email" type="email" wire:model="email" required autofocus autocomplete="email"
                       data-testid="corp-email" class="cx-input" />
            </div>

            <div data-password-field>
                <div class="cx-label-row"><label for="c-pw" class="cx-label">Password</label></div>
                <div class="cx-input-wrap">
                    <input id="c-pw" type="password" wire:model="password" required autocomplete="current-password"
                           data-testid="corp-password"
                           class="cx-input @error('password') is-invalid @enderror" />
                    <button type="button" class="cx-toggle" data-password-toggle aria-label="Show password" aria-pressed="false"><span class="cx-show">Show</span><span class="cx-hide">Hide</span></button>
                </div>
                @error('password')
                    <p data-error="password" class="cx-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <label class="cx-check">
                <input type="checkbox" wire:model="remember" />
                Keep me signed in on this device
            </label>

            <button type="submit" data-testid="corp-login-submit" class="cx-submit"
                    wire:loading.attr="disabled" wire:target="authenticate">
                <span wire:loading.remove wire:target="authenticate">Sign in</span>
                <span wire:loading wire:target="authenticate">Signing in…</span>
            </button>
        </form>

        <p class="cx-after">For company admins. Team members book with their company email.</p>
        @if ($businessContact !== '')
            <p class="cx-after" style="margin-top: 8px;">Can't sign in? Contact {{ $businessName }} on {{ $businessContact }}.</p>
        @endif
    </x-auth-shell>
</div>
