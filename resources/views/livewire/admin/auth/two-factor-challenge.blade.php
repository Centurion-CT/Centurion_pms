<div class="lp-dash">
    <div style="max-width: 460px; margin: 40px auto;">
        <span class="lp-eyebrow-strip">Verify</span>
        <h1 class="lp-display mt-3 text-[clamp(24px,3vw,32px)]"
            style="color: var(--color-ink); font-weight: 600; line-height: 1.05;">
            Two-factor challenge
        </h1>
        <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
            Enter the 6-digit code from your authenticator app to continue.
        </p>

        <form wire:submit.prevent="verify" class="lp-dash-card" style="margin-top: 20px;">
            <label for="ch-2fa-code" class="lp-form-label">Authenticator code</label>
            <input id="ch-2fa-code" type="text" inputmode="numeric"
                   wire:model="code" required autocomplete="one-time-code"
                   class="lp-input lp-mono" style="letter-spacing: 0.2em; font-size: 18px;"
                   autofocus data-testid="admin-2fa-challenge-code"
                   placeholder="000 000 or XXXX-XXXX">
            <p class="lp-form-help" style="margin-top: 8px;">
                Lost your authenticator? Enter one of your recovery codes
                instead - format <span class="lp-mono">XXXX-XXXX</span>.
            </p>
            @error('code') <p class="lp-form-error">{{ $message }}</p> @enderror

            <button type="submit" class="lp-pill" style="margin-top: 20px; width: 100%;"
                    data-testid="admin-2fa-challenge-verify">
                <span wire:loading.remove wire:target="verify">Verify + continue</span>
                <span wire:loading wire:target="verify">Checking…</span>
                <span class="lp-pill-dot">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>
                </span>
            </button>
        </form>

        <form method="POST" action="{{ route('admin.logout') }}" style="margin-top: 12px; text-align: center;">
            @csrf
            <button type="submit" class="text-[12.5px]" style="background: none; border: 0; color: var(--color-ink-mute); cursor: pointer; text-decoration: underline; text-underline-offset: 3px;">
                Sign out instead
            </button>
        </form>
    </div>
</div>
