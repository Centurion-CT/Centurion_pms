@php
    $tenantDisplayName = \App\Models\Tenant::current()?->name ?? 'Your property';
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
@endphp

{{-- Staff sign-in. The panel and styles live in <x-auth-shell>. A failed
     sign-in can't say which field was wrong, so its message sits above
     the form, and the password takes focus for another try. --}}
<x-auth-shell>
    <p class="cx-eyebrow">{{ $greeting }}</p>
    <h1 class="cx-title">Sign in to {{ $tenantDisplayName }}</h1>

    @error('email')
        <p data-error="email" class="cx-alert" role="alert">{{ $message }}</p>
    @enderror

    {{-- Enter-key handler: some password managers fill inputs without
         firing input events, so sync the live values into Livewire,
         then submit. Alpine only runs a handler as statements when it
         starts with `if`, so no leading comment inside the attribute. --}}
    <form wire:submit="authenticate" data-testid="login-form" class="cx-form"
          x-data
          x-on:sign-in-refused.window="$nextTick(() => $el.querySelector('#login-password')?.focus())"
          x-on:keydown.enter="if ($event.target.tagName === 'INPUT' && $event.target.type !== 'checkbox') {
                  $event.preventDefault();
                  $wire.set('email', $el.querySelector('#login-email')?.value ?? '', false);
                  $wire.set('password', $el.querySelector('#login-password')?.value ?? '', false);
                  $el.requestSubmit();
              }
          ">

        <div>
            <div class="cx-label-row"><label for="login-email" class="cx-label">Work email</label></div>
            <input id="login-email" type="email" wire:model="email" required autocomplete="email"
                   data-testid="email" class="cx-input" />
        </div>

        <div data-password-field>
            <div class="cx-label-row">
                <label for="login-password" class="cx-label">Password</label>
                <a href="{{ route('tenant.password.request') }}">Forgot password?</a>
            </div>
            <div class="cx-input-wrap">
                <input id="login-password" type="password" wire:model="password" required
                       autocomplete="current-password" data-testid="password"
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

        <button type="submit" class="cx-submit" wire:loading.attr="disabled" wire:target="authenticate">
            <span wire:loading.remove wire:target="authenticate">Sign in</span>
            <span wire:loading wire:target="authenticate">Signing in…</span>
        </button>
    </form>

    @if (\Illuminate\Support\Facades\Route::has('platform.login'))
        <p class="cx-after">Wrong business? <a class="cx-link" href="{{ route('platform.login') }}">Find yours</a></p>
    @endif
</x-auth-shell>
