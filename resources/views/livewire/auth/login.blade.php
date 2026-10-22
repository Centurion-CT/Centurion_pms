@php
    $tenant = \App\Models\Tenant::current();
    $property = $tenant?->property;
    $tenantDisplayName = $tenant?->name ?? 'Your property';

    // Right-hand photo: the hotel's own gallery photo, else a room photo,
    // else the bundled default (public/brand/login-default.jpg — Unsplash
    // photo-1611892440504, free to use under the Unsplash License).
    $photo = $property?->getMedia('gallery')->first()
        ?? $property?->rooms()->get()->flatMap(fn ($r) => $r->getMedia('gallery'))->first();
    $photoUrl = $photo?->getUrl() ?? asset('brand/login-default.jpg');
    $photoCaption = $photo?->getCustomProperty('caption');

    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good morning.' : ($hour < 17 ? 'Good afternoon.' : 'Good evening.');
@endphp

{{-- ─── Staff sign-in (Centurion "Harbour") ───────────────────────────
     Navy form slab on the left, a photo on the right (the property's own, else a bundled default).
     Bindings (wire:model, authenticate, error states, password reveal,
     Enter-key safety net) are unchanged from the previous design. --}}
<div class="cx-login">
<style>
    .cx-login {
        --navy: #16324B; --navy-deep: #0F2438; --navy-line: #2A4A68;
        --blue: #336799; --blue-200: #A9C5E1; --blue-300: #8DB6DE;
        --ink: #1A2430; --white: #FFFFFF; --rose: #F2A7A7;
        min-height: 100vh;
        display: grid;
        grid-template-columns: 1fr;
        background: var(--navy-deep);
        color: var(--white);
        font-family: 'Hanken Grotesk', system-ui, sans-serif;
    }
    @media (min-width: 960px) { .cx-login { grid-template-columns: 520px 1fr; } }

    .cx-slab { background: var(--navy); display: flex; flex-direction: column; padding: 28px 24px; min-height: 100vh; }
    @media (min-width: 640px) { .cx-slab { padding: 48px 56px; } }

    .cx-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; }
    .cx-brand-mark { width: 44px; height: 44px; border-radius: 12px; background: var(--white); display: grid; place-items: center; flex-shrink: 0; }
    .cx-brand-mark img { width: 30px; height: 30px; object-fit: contain; }
    .cx-brand-name { font-family: 'Bricolage Grotesque', system-ui, sans-serif; font-weight: 600; font-size: 18px; line-height: 1.2; }
    .cx-brand-sub { font-size: 13px; color: var(--blue-300); }

    .cx-body { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 48px 0; max-width: 410px; }
    .cx-title { font-family: 'Bricolage Grotesque', system-ui, sans-serif; font-weight: 600; font-size: clamp(34px, 4vw, 44px); line-height: 1.04; letter-spacing: -0.01em; margin: 0; }
    .cx-lede { font-size: 16px; color: var(--blue-200); margin: 14px 0 0; line-height: 1.5; }

    .cx-form { margin-top: 32px; display: grid; gap: 18px; }
    .cx-label-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
    .cx-label { font-size: 14px; color: var(--blue-200); }
    .cx-label-row a { font-size: 14px; color: var(--white); font-weight: 600; text-decoration: underline; text-underline-offset: 4px; text-decoration-color: var(--blue-300); }
    .cx-input-wrap { position: relative; }
    .cx-input {
        width: 100%; height: 48px; border-radius: 8px;
        background: var(--navy-deep); border: 1px solid var(--navy-line);
        color: var(--white); font: inherit; font-size: 15px; padding: 0 16px;
        outline: none; transition: border-color 150ms ease, box-shadow 150ms ease;
    }
    .cx-input::placeholder { color: rgba(169, 197, 225, 0.6); }
    .cx-input:focus { border-color: var(--blue-300); box-shadow: 0 0 0 3px rgba(141, 182, 222, 0.25); }
    .cx-input.is-invalid { border-color: var(--rose); }
    [data-password-field] .cx-input { padding-right: 48px; }
    .cx-toggle { position: absolute; right: 6px; top: 6px; width: 36px; height: 36px; border: 0; background: transparent; color: var(--blue-300); border-radius: 6px; display: grid; place-items: center; cursor: pointer; }
    .cx-toggle:focus-visible { outline: 2px solid var(--blue-300); }
    .cx-toggle .kv-slash { display: none; }
    [data-password-field].is-revealed .cx-toggle .kv-slash { display: inline; }
    .cx-error { margin: 6px 0 0; font-size: 13px; color: #FBDADA; }

    .cx-check { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--blue-200); cursor: pointer; }
    .cx-check input { width: 18px; height: 18px; accent-color: var(--blue); }

    .cx-submit {
        margin-top: 6px; height: 48px; border: 0; border-radius: 8px;
        background: var(--white); color: var(--navy); font: inherit; font-size: 16px; font-weight: 600;
        cursor: pointer; transition: background 150ms ease;
    }
    .cx-submit:hover { background: #EAF1F8; }
    .cx-submit:focus-visible { outline: 3px solid var(--blue-300); outline-offset: 2px; }
    .cx-submit[disabled] { opacity: 0.7; cursor: progress; }

    .cx-foot { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; color: var(--blue-300); }
    .cx-foot a { color: inherit; text-decoration: none; }
    .cx-foot a:hover { color: var(--white); }
    .cx-foot nav { display: flex; gap: 16px; }

    .cx-photo { display: none; position: relative; background: var(--navy-deep) center / cover no-repeat; }
    @media (min-width: 960px) { .cx-photo { display: block; } }
    .cx-photo-caption { position: absolute; right: 40px; bottom: 40px; max-width: 300px; background: rgba(15, 36, 56, 0.85); border-radius: 8px; padding: 12px 16px; font-size: 14px; }
    .cx-photo-caption strong { display: block; font-weight: 600; }
    .cx-photo-caption span { color: var(--blue-200); }

    @media (prefers-reduced-motion: reduce) { .cx-input, .cx-submit { transition: none; } }
</style>

    <section class="cx-slab">
        <a href="{{ url('/') }}" class="cx-brand">
            <span class="cx-brand-mark"><img src="{{ asset(config('brand.logo')) }}" alt="{{ config('brand.name') }}"></span>
            <span>
                <span class="cx-brand-name" style="display:block;">{{ $tenantDisplayName }}</span>
                <span class="cx-brand-sub">Staff sign-in</span>
            </span>
        </a>

        <div class="cx-body">
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
        </div>

        <footer class="cx-foot">
            <span>© {{ date('Y') }} {{ config('brand.name') }}</span>
            <nav>
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
            </nav>
        </footer>
    </section>

    <aside class="cx-photo" style="background-image: url('{{ $photoUrl }}');" aria-hidden="true">
        @if ($photoCaption)
            <div class="cx-photo-caption"><strong>{{ $photoCaption }}</strong><span>{{ $tenantDisplayName }}</span></div>
        @endif
    </aside>
</div>
