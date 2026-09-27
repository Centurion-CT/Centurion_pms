@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div data-testid="corporate-login"
     class="lp-page min-h-screen flex items-center justify-center"
     style="background: var(--color-ink-deep); padding-block: 32px;">

    <div class="w-full" style="max-width: 460px;">
        <section class="lp-card overflow-hidden"
                 style="background: var(--color-ink-deep-2); color: var(--color-card); border: 1px solid rgba(244,239,230,0.08);">

            <div class="flex items-center justify-between border-b px-7 py-5"
                 style="border-color: rgba(244,239,230,0.08);">
                <div class="flex items-center gap-2.5">
                    <span aria-hidden class="inline-flex h-6 w-6 items-center justify-center"
                          style="background: var(--color-card); color: var(--color-ink); border-radius: 6px;">
                        <svg width="11" height="11" viewBox="0 0 12 12" fill="none">
                            <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        </svg>
                    </span>
                    <span class="lp-display text-[16px] tracking-tight" style="color: var(--color-card); font-weight: 600;">
                        Keverna
                    </span>
                </div>
                <span class="flex items-center gap-1.5 text-[10.5px] uppercase tracking-[0.18em]"
                      style="color: rgba(244,239,230,0.55);">
                    <span class="corp-context-dot" aria-hidden></span>
                    Corporate portal
                </span>
            </div>

            <div class="px-7 py-10 sm:px-10 sm:py-12">
                {{-- Grotesk sans register (not Fraunces) so this reads
                     as B2B admin, not marketing. Report 06 flagged the
                     serif hero as too editorial for a Rippling/Brex-
                     grade corporate-portal login. --}}
                <h1 class="text-[clamp(20px,2.2vw,24px)]"
                    style="color: var(--color-card); font-weight: 600; line-height: 1.15; letter-spacing: -0.014em;">
                    Sign in to your corporate workspace
                </h1>
                <p class="mt-2 text-[13.5px]" style="color: rgba(244,239,230,0.60);">
                    Manage your team, top up your wallet, see bookings across the platform.
                </p>

                <form wire:submit="authenticate" class="mt-8 grid gap-6">
                    <div>
                        <label for="c-email" class="lp-form-label lp-form-label--on-dark">Work email</label>
                        <input id="c-email" type="email" wire:model="email" required autofocus
                               data-testid="corp-email"
                               class="lp-input lp-input--on-dark @error('email') is-invalid @enderror"
                               placeholder="you@acme.com" />
                        @error('email') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="c-pw" class="lp-form-label lp-form-label--on-dark">Password</label>
                        <div class="lp-password-field on-dark" data-password-field>
                            <input id="c-pw" type="password" wire:model="password" required
                                   data-testid="corp-password"
                                   class="lp-input lp-input--on-dark @error('password') is-invalid @enderror"
                                   placeholder="Enter your password" />
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
                        @error('password') <p role="alert" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-center gap-2.5 text-[13px] cursor-pointer"
                           style="color: rgba(244,239,230,0.7);">
                        <input type="checkbox" wire:model="remember"
                               class="h-4 w-4" style="accent-color: var(--color-accent);" />
                        Remember this device
                    </label>

                    <div class="mt-2">
                        <button type="submit" data-testid="corp-login-submit" class="lp-pill"
                                wire:loading.attr="disabled" wire:target="authenticate">
                            <span wire:loading.remove wire:target="authenticate">Sign in</span>
                            <span wire:loading wire:target="authenticate">Signing in…</span>
                            <span class="lp-pill-dot">
                                <span wire:loading.remove wire:target="authenticate">{!! $pillArrow !!}</span>
                                <span wire:loading wire:target="authenticate" class="kv-spinner" aria-hidden="true"></span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="border-t px-7 py-4 lp-mono text-[10.5px] tracking-[0.14em] uppercase"
                 style="border-color: rgba(244,239,230,0.08); color: rgba(244,239,230,0.45);">
                Corporate-admin access only · Members book via their company email
            </div>
        </section>

        {{-- Utility footer. Status pill, API version, support email.
             Mimics Rippling / Ramp / Brex admin footers where the mode
             is "this is the console, here's how you know it's up." No
             marketing copy, no logos. --}}
        <div class="corp-util-footer">
            <a href="https://status.keverna.com" target="_blank" rel="noopener"
               class="corp-util-status" data-testid="corp-status-pill">
                <span class="corp-util-status-dot" aria-hidden="true"></span>
                All systems operational
            </a>
            <span class="corp-util-spacer" aria-hidden="true"></span>
            <span class="corp-util-item">API v1</span>
            <a href="mailto:corporate@keverna.com" class="corp-util-item">
                corporate@keverna.com
            </a>
        </div>

        <style>
            .corp-util-footer {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 10px 16px;
                margin-top: 20px;
                padding: 10px 4px;
                font-family: ui-monospace, 'JetBrains Mono', SFMono-Regular, Menlo, monospace;
                font-size: 10.5px;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: rgba(244,239,230,0.55);
            }
            .corp-util-status {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 4px 10px;
                border-radius: 999px;
                background: rgba(76, 149, 108, 0.14);
                color: #7EC69A;
                letter-spacing: 0.14em;
                text-decoration: none;
                transition: background 100ms ease;
            }
            .corp-util-status:hover { background: rgba(76, 149, 108, 0.22); }
            .corp-util-status-dot {
                width: 6px; height: 6px; border-radius: 999px;
                background: #7EC69A;
                box-shadow: 0 0 6px rgba(126, 198, 154, 0.6);
            }
            .corp-util-spacer { flex: 1; min-width: 8px; }
            .corp-util-item {
                color: rgba(244,239,230,0.55);
                text-decoration: none;
            }
            .corp-util-item + .corp-util-item::before {
                content: '·';
                margin-right: 12px;
                opacity: 0.4;
            }
            a.corp-util-item:hover { color: rgba(244,239,230,0.85); }
        </style>
    </div>
</div>
