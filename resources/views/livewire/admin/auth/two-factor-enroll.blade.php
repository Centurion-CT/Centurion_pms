<div class="lp-dash">
    <div>
        <span class="lp-eyebrow-strip">Security</span>
        <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
            Two-factor authentication
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 640px;">
            Required for dangerous actions (deleting tenants, exporting data).
            Scan the QR with your authenticator app + type the first code to
            confirm. Save the recovery codes somewhere safe.
        </p>
    </div>

    @if ($admin->hasTwoFactor() && empty($showRecoveryCodes))
        <section class="lp-dash-card" style="margin-top: 16px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Enrolled</span>
                <span class="text-[11.5px]" style="color: var(--color-accent-deep); font-weight: 600;">
                    Active since {{ $admin->two_factor_confirmed_at?->format('D d M Y') }}
                </span>
            </div>
            <p class="text-[14px]" style="color: var(--color-ink-soft); max-width: 560px;">
                Your admin account is protected by TOTP. Every login requires a
                fresh code from your app. If you lose your device, use a
                recovery code from the sheet you saved during enrolment.
            </p>
            <button type="button" wire:click="resetEnrollment" class="lp-btn lp-btn--sm lp-btn--danger" style="margin-top: 20px;"
                    data-testid="admin-2fa-reset"
                    onclick="return confirm('Wipe your current TOTP + recovery codes and start enrolment over?');">
                Reset 2FA
            </button>
        </section>
    @elseif (! empty($showRecoveryCodes))
        <section class="lp-dash-card" style="margin-top: 16px; border: 1px solid var(--color-accent-deep);">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title" style="color: var(--color-accent-deep);">Saved. Now save these recovery codes.</span>
            </div>
            <p class="text-[13.5px]" style="color: var(--color-ink-soft); max-width: 640px;">
                Each code works once and only once. Store them somewhere separate
                from your authenticator app. If you lose both, a super-admin has
                to reset your 2FA from the Admins page.
            </p>
            <div style="margin-top: 16px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 24px; max-width: 400px;"
                 data-testid="admin-2fa-recovery-codes">
                @foreach ($showRecoveryCodes as $c)
                    <span class="lp-mono" style="padding: 6px 10px; background: var(--color-card-deep); border-radius: 6px; font-size: 13px;">
                        {{ $c }}
                    </span>
                @endforeach
            </div>
            <a href="{{ route('admin.dashboard') }}" class="lp-pill" style="margin-top: 24px;">
                I've saved them - continue
            </a>
        </section>
    @else
        <section class="lp-dash-card" style="margin-top: 16px;">
            <div class="grid gap-6 sm:grid-cols-[220px_1fr] items-start">
                @if ($qrUrl)
                    <div>
                        <div style="padding: 12px; background: #fff; border-radius: 12px; display: inline-block;">
                            <img src="{{ $qrUrl }}" alt="Scan with your authenticator app"
                                 width="220" height="220" data-testid="admin-2fa-qr">
                        </div>
                        <p class="text-[11.5px] lp-mono" style="color: var(--color-ink-mute); margin-top: 10px; letter-spacing: 0.06em; text-transform: uppercase;">
                            Manual key
                        </p>
                        <p class="lp-mono" style="font-size: 14px; color: var(--color-ink); letter-spacing: 0.08em;"
                           data-testid="admin-2fa-secret">
                            {{ implode(' ', str_split($secret, 4)) }}
                        </p>
                    </div>
                @endif

                <form wire:submit.prevent="confirm">
                    <label for="reg-2fa-code" class="lp-form-label">Confirm with a 6-digit code</label>
                    <input id="reg-2fa-code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           wire:model="code" required autocomplete="one-time-code"
                           class="lp-input lp-mono" style="letter-spacing: 0.2em; font-size: 18px; width: 180px;"
                           data-testid="admin-2fa-code" placeholder="000 000">
                    @error('code') <p class="lp-form-error">{{ $message }}</p> @enderror
                    <p class="lp-form-help" style="margin-top: 8px;">
                        The code changes every 30 seconds. If the first one doesn't
                        work, wait for the next one - it means your device clock is
                        a bit off.
                    </p>
                    <button type="submit" class="lp-pill" style="margin-top: 20px;" data-testid="admin-2fa-confirm">
                        Confirm 2FA
                        <span class="lp-pill-dot">
                            <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                        </span>
                    </button>
                </form>
            </div>
        </section>
    @endif
</div>
