@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">
    <div class="mx-auto" style="max-width: 480px;">
        <div class="text-center">
            <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                Step 2 of 3
            </span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.06;">
                Verify your email
            </h1>
            <p data-testid="recipient" class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                We sent a 6-digit code to
                <strong style="color: var(--color-ink); font-weight: 600;">{{ $operator->email }}</strong>.
            </p>
        </div>

        <div class="lp-dash-card mt-8 grid gap-5" style="padding: 28px 24px;">
            @if (session('status'))
                <p data-testid="status"
                   class="rounded-lg px-4 py-3 text-[13.5px]"
                   style="background: var(--color-accent-soft); color: var(--color-ink);">
                    {{ session('status') }}
                </p>
            @endif

            <form wire:submit="submit" data-testid="email-otp-form" class="grid gap-5">
                <div>
                    <label for="email-otp" class="lp-form-label">Verification code</label>
                    <input id="email-otp" type="text" inputmode="numeric" autocomplete="one-time-code"
                           pattern="\d{6}" maxlength="6"
                           wire:model="code" required data-testid="otp-input"
                           class="lp-input @error('code') is-invalid @enderror"
                           style="text-align: center; letter-spacing: 0.4em; font-size: 22px; font-weight: 500;" />
                    @error('code') <p data-error="code" class="lp-form-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="lp-pill">
                        <span wire:loading.remove wire:target="submit">Verify email</span>
                        <span wire:loading wire:target="submit">Verifying…</span>
                        <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                    </button>
                </div>
            </form>

            {{-- Live countdown island. `wire:key` embeds the server-side
                 cooldown, so after a successful resend() call the new value
                 (60) forces Alpine to re-mount and re-seed the local counter.
                 Between server round-trips the counter ticks down locally -
                 no polling required. Server-side OtpCooldownException still
                 backs this up if the button is somehow clicked while hot. --}}
            <div wire:key="resend-cooldown-{{ $resendCooldown }}"
                 x-data="{
                     seconds: @js($resendCooldown),
                     init() {
                         if (this.seconds <= 0) return;
                         this._interval = setInterval(() => {
                             if (this.seconds > 0) this.seconds--;
                             else clearInterval(this._interval);
                         }, 1000);
                     },
                     destroy() { clearInterval(this._interval); }
                 }">
                <button type="button" wire:click="resend"
                        x-bind:disabled="seconds > 0"
                        wire:loading.attr="disabled" wire:target="resend"
                        data-testid="resend"
                        class="w-full text-center text-[13px] disabled:opacity-60 disabled:cursor-not-allowed"
                        style="color: var(--color-ink-soft); text-decoration: underline; text-underline-offset: 4px; background: transparent; border: 0; cursor: pointer;">
                    <span x-show="seconds > 0">Resend in <span x-text="seconds"></span>s</span>
                    <span x-show="seconds === 0" x-cloak>
                        <span wire:loading.remove wire:target="resend">Resend code</span>
                        <span wire:loading wire:target="resend">Sending…</span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
