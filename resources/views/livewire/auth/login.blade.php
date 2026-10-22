@php
    $tenantDisplayName = \App\Models\Tenant::current()?->name ?? 'Your property';
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good morning.' : ($hour < 17 ? 'Good afternoon.' : 'Good evening.');
@endphp

{{-- Staff sign-in. Bindings (wire:model, authenticate, error states,
     password reveal, Enter-key safety net) are unchanged from the
     previous design; the slab, photo and styles live in <x-auth-shell>. --}}
<x-auth-shell subtitle="Staff sign-in">
    <h1 class="cx-title">{{ $greeting }}<br>The front desk is ready.</h1>
    <p class="cx-lede">Sign in with your staff email to start your shift at {{ $tenantDisplayName }}.</p>

    {{-- Enter-key handler: some password managers fill inputs without
         firing input events, so sync the live values into Livewire,
         then submit. Alpine only runs a handler as statements when it
         starts with `if`, so no leading comment inside the attribute. --}}
    <form wire:submit="authenticate" data-testid="login-form" class="cx-form"
          x-data
          x-init="
              Livewire.hook('morph.updated', () => {
                  const field = $el.querySelector('.is-invalid');
                  if (field) { field.scrollIntoView({ block: 'center', behavior: 'smooth' }); field.focus(); }
              });
          "
          x-on:keydown.enter="if ($event.target.tagName === 'INPUT' && $event.target.type !== 'checkbox') {
                  $event.preventDefault();
                  $wire.set('email', $el.querySelector('#login-email')?.value ?? '', false);
                  $wire.set('password', $el.querySelector('#login-password')?.value ?? '', false);
                  $el.requestSubmit();
              }
          ">

        <div>
            <div class="cx-label-row"><label for="login-email" class="cx-label">Email</label></div>
            <input id="login-email" type="email" wire:model="email" required autocomplete="email"
                   data-testid="email" placeholder="Your work email"
                   class="cx-input @error('email') is-invalid @enderror" />
            @error('email')
                <p data-error="email" class="cx-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div data-password-field>
            <div class="cx-label-row">
                <label for="login-password" class="cx-label">Password</label>
                <a href="{{ route('tenant.password.request') }}">Forgot it?</a>
            </div>
            <div class="cx-input-wrap">
                <input id="login-password" type="password" wire:model="password" required
                       autocomplete="current-password" data-testid="password"
                       class="cx-input @error('password') is-invalid @enderror" />
                <button type="button" class="cx-toggle" data-password-toggle aria-label="Show password" aria-pressed="false">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/>
                        <circle cx="10" cy="10" r="2.5"/>
                        <path class="kv-slash" d="M3 3l14 14"/>
                    </svg>
                </button>
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
            <span wire:loading.remove wire:target="authenticate">Sign in to the front desk</span>
            <span wire:loading wire:target="authenticate">Signing in…</span>
        </button>
    </form>
</x-auth-shell>
