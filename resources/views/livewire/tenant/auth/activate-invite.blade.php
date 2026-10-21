@php
    $tenant = \App\Models\Tenant::current();
@endphp

<div class="lp-page min-h-screen">
    <div class="lp-shell" style="padding-block: 0;">

        {{-- Slim top bar mirroring the tenant Login screen. --}}
        <header class="lp-card lp-card-cream" style="padding: 14px 18px;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span aria-hidden class="inline-flex h-7 w-7 items-center justify-center"
                          style="background: #FFFFFF; border: 1px solid var(--color-line); border-radius: 8px; padding: 3px;">
                        <img src="{{ asset(config('brand.logo')) }}" alt="" width="18" height="21">
                    </span>
                    <span class="lp-display text-[18px] tracking-tight truncate" style="color: var(--color-ink); font-weight: 600; max-width: 380px;">
                        {{ $tenant?->name ?? 'Your property' }}
                    </span>
                </div>
                <span class="text-[12.5px]" style="color: var(--color-ink-mute);">
                    Powered by <span style="color: var(--color-ink); font-weight: 500;">{{ config('brand.name') }}</span>
                </span>
            </div>
        </header>

        <section class="lp-card lp-card-cream overflow-hidden" data-testid="activate-invite-card">
            <div class="px-7 py-10 sm:px-12 sm:py-14 lg:px-16 lg:py-20 max-w-[560px] mx-auto">

                <span class="lp-script text-[clamp(18px,1.8vw,22px)]" style="color: var(--color-accent-deep);">
                    Welcome, {{ $inviteeName ? explode(' ', $inviteeName)[0] : 'there' }}.
                </span>

                <h1 class="lp-display mt-2 text-[clamp(30px,4vw,44px)] tracking-[-0.02em]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.06;">
                    Pick a password to activate<br>your account for {{ $tenantName ?: 'this property' }}.
                </h1>

                <p class="mt-5 max-w-[440px] text-[15px] leading-[1.65]" style="color: var(--color-ink-soft);">
                    You'll use this password to sign in from now on, either on
                    <span style="color: var(--color-ink);">{{ $tenant?->primaryHost() ?? 'this tenant' }}/login</span>.
                    Your account is not active until you set it.
                </p>

                @if ($inviteeEmail)
                    <p class="mt-4 lp-mono text-[12px] tracking-[0.06em]"
                       style="color: var(--color-ink-mute);"
                       data-testid="activate-invite-email">
                        Activating &middot; <span style="color: var(--color-ink);">{{ $inviteeEmail }}</span>
                    </p>
                @endif

                <form wire:submit="activate" data-testid="activate-invite-form" class="mt-10 grid gap-6">

                    <div>
                        <label for="activate-password" class="lp-form-label">New password</label>
                        <div class="lp-password-field" data-password-field>
                            <input id="activate-password"
                                   type="password"
                                   autocomplete="new-password"
                                   required
                                   wire:model="password"
                                   class="lp-input @error('password') is-invalid @enderror"
                                   data-testid="activate-password">
                            <button type="button" class="lp-password-toggle"
                                    data-password-toggle
                                    aria-label="Show password">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1.5 10s3-6 8.5-6 8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z"/>
                                    <circle cx="10" cy="10" r="2.4"/>
                                    <path class="lp-password-slash" d="M3 3l14 14"/>
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p data-error="password" class="lp-form-error">{{ $message }}</p>
                        @enderror
                        <p class="text-[12px] mt-1.5" style="color: var(--color-ink-mute);">
                            8 characters minimum &middot; at least one letter and one number.
                        </p>
                    </div>

                    <div>
                        <label for="activate-password-confirmation" class="lp-form-label">Confirm password</label>
                        <input id="activate-password-confirmation"
                               type="password"
                               autocomplete="new-password"
                               required
                               wire:model="password_confirmation"
                               class="lp-input @error('password_confirmation') is-invalid @enderror"
                               data-testid="activate-password-confirmation">
                        @error('password_confirmation')
                            <p data-error="password_confirmation" class="lp-form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="mt-2">
                        <button type="submit"
                                class="lp-pill"
                                wire:loading.attr="disabled"
                                wire:target="activate"
                                data-testid="activate-submit">
                            <span wire:loading.remove wire:target="activate">Activate my account</span>
                            <span wire:loading wire:target="activate">Activating…</span>
                            <span class="lp-pill-dot">
                                <span wire:loading.remove wire:target="activate">
                                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                                </span>
                                <span wire:loading wire:target="activate" class="kv-spinner" aria-hidden="true"></span>
                            </span>
                        </button>
                    </div>

                </form>
            </div>
        </section>

    </div>
</div>
