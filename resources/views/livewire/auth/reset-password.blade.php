@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    $tenant = \App\Models\Tenant::current();
@endphp

<div class="lp-page min-h-screen">
    <div class="lp-shell" style="padding-block: 0;">

        <header class="lp-card lp-card-cream" style="padding: 14px 18px;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span aria-hidden class="inline-flex h-7 w-7 items-center justify-center"
                          style="background: var(--color-ink); color: var(--color-card); border-radius: 8px;">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        </svg>
                    </span>
                    <span class="lp-display text-[18px] tracking-tight truncate"
                          style="color: var(--color-ink); font-weight: 600; max-width: 380px;">
                        {{ $tenant?->name ?? 'Your property' }}
                    </span>
                </div>
                <a href="{{ route('tenant.login') }}"
                   class="text-[12.5px]"
                   style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                    ← Back to sign in
                </a>
            </div>
        </header>

        <section class="lp-card lp-card-cream overflow-hidden">
            <div class="mx-auto max-w-[520px] px-7 py-10 sm:px-12 sm:py-14 lg:py-20">

                <span class="lp-script text-[clamp(18px,1.8vw,22px)]"
                      style="color: var(--color-accent-deep);">
                    Almost there,
                </span>

                <h1 class="lp-display mt-2 text-[clamp(28px,3.6vw,42px)] tracking-[-0.02em]"
                    style="color: var(--color-ink); font-weight: 600; line-height: 1.06;">
                    Choose a new password.
                </h1>

                <p class="mt-5 text-[14.5px] leading-[1.6]" style="color: var(--color-ink-soft);">
                    Pick something at least 8 characters long. You&rsquo;ll be signed in automatically once it&rsquo;s updated.
                </p>

                <form wire:submit="resetPassword" data-testid="reset-form"
                      class="mt-8 grid gap-6">

                    <div>
                        <label for="rp-email" class="lp-form-label">Email</label>
                        <input id="rp-email" type="email" wire:model="email" required
                               autocomplete="email"
                               class="lp-input @error('email') is-invalid @enderror"
                               placeholder="you@yourproperty.com" />
                        @error('email')
                            <p data-error="email" class="lp-form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="rp-password" class="lp-form-label">New password</label>
                        <div class="lp-password-field" data-password-field>
                            <input id="rp-password" type="password" wire:model="password" required
                                   autocomplete="new-password"
                                   class="lp-input @error('password') is-invalid @enderror"
                                   placeholder="At least 8 characters" />
                            <button type="button" class="lp-password-toggle"
                                    data-password-toggle aria-label="Show password" aria-pressed="false">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/>
                                    <circle cx="10" cy="10" r="2.5"/>
                                    <path class="lp-password-slash" d="M3 3l14 14"/>
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p data-error="password" class="lp-form-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="rp-password-confirm" class="lp-form-label">Confirm new password</label>
                        <div class="lp-password-field" data-password-field>
                            <input id="rp-password-confirm" type="password" wire:model="password_confirmation" required
                                   autocomplete="new-password"
                                   class="lp-input"
                                   placeholder="Repeat it" />
                            <button type="button" class="lp-password-toggle"
                                    data-password-toggle aria-label="Show password" aria-pressed="false">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6z"/>
                                    <circle cx="10" cy="10" r="2.5"/>
                                    <path class="lp-password-slash" d="M3 3l14 14"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="mt-2">
                        <button type="submit" class="lp-pill"
                                wire:loading.attr="disabled" wire:target="resetPassword">
                            <span wire:loading.remove wire:target="resetPassword">Update password</span>
                            <span wire:loading wire:target="resetPassword">Updating…</span>
                            <span class="lp-pill-dot">
                                <span wire:loading.remove wire:target="resetPassword">{!! $pillArrow !!}</span>
                                <span wire:loading wire:target="resetPassword" class="kv-spinner" aria-hidden="true"></span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <footer class="lp-card lp-card-cream" style="padding: 16px 20px;">
            <div class="flex flex-col gap-2 text-[12px] sm:flex-row sm:items-center sm:justify-between"
                 style="color: var(--color-ink-mute);">
                <span>© {{ date('Y') }} Keverna</span>
                <span><a href="https://keverna.com/privacy" style="color: inherit;">Privacy</a> · <a href="https://keverna.com/terms" style="color: inherit;">Terms</a></span>
            </div>
        </footer>

    </div>
</div>
