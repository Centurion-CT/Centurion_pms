@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    $hasPhone = (string) ($operator->phone ?? '') !== '';
@endphp

<div class="lp-dash">
    <div class="mx-auto" style="max-width: 480px;">
        <div class="text-center">
            <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase" style="color: var(--color-ink-mute);">
                Step 3 of 3
            </span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.06;">
                Verify your phone
            </h1>
            @if ($hasPhone)
                <p data-testid="recipient" class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                    We sent a 6-digit WhatsApp code to
                    <strong style="color: var(--color-ink); font-weight: 600;">{{ $operator->phone }}</strong>.
                </p>
            @else
                <p class="mt-2 text-[14px]" style="color: var(--color-ink-soft);">
                    Add the WhatsApp number we should text your verification code to.
                </p>
            @endif
        </div>

        <div class="lp-dash-card mt-8 grid gap-5" style="padding: 28px 24px;">
            @if (session('status'))
                <p data-testid="status"
                   class="rounded-lg px-4 py-3 text-[13.5px]"
                   style="background: var(--color-accent-soft); color: var(--color-ink);">
                    {{ session('status') }}
                </p>
            @endif

            @if ($sendError)
                <p role="alert" data-testid="send-error"
                   class="rounded-lg px-4 py-3 text-[13.5px]"
                   style="background: rgba(140,30,30,0.08); color: #8c1e1e; border: 1px solid rgba(140,30,30,0.18);">
                    {{ $sendError }}
                </p>
            @endif

            {{-- Phone entry form. Shown when the operator has no phone
                 on file, replaces the OTP form until they save one.
                 Also available as an "Update number" affordance below
                 the OTP form so a bad number can be corrected inline. --}}
            @unless ($hasPhone)
                <form wire:submit="savePhone" data-testid="phone-entry-form" class="grid gap-4">
                    <div>
                        <label for="phone-input" class="lp-form-label">WhatsApp number</label>
                        <input id="phone-input" type="tel" wire:model="phone"
                               required data-testid="phone-input"
                               placeholder="+234 800 000 0000"
                               class="lp-input @error('phone') is-invalid @enderror" />
                        @error('phone') <p data-error="phone" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="lp-pill"
                                wire:loading.attr="disabled" wire:target="savePhone">
                            <span wire:loading.remove wire:target="savePhone">Send code</span>
                            <span wire:loading wire:target="savePhone">Saving…</span>
                            <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </button>
                    </div>
                </form>
            @else
                <form wire:submit="submit" data-testid="phone-otp-form" class="grid gap-5">
                    <div>
                        <label for="phone-otp" class="lp-form-label">Verification code</label>
                        <input id="phone-otp" type="text" inputmode="numeric" autocomplete="one-time-code"
                               pattern="\d{6}" maxlength="6"
                               wire:model="code" required data-testid="otp-input"
                               class="lp-input @error('code') is-invalid @enderror"
                               style="text-align: center; letter-spacing: 0.4em; font-size: 22px; font-weight: 500;" />
                        @error('code') <p data-error="code" class="lp-form-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="lp-pill">
                            <span wire:loading.remove wire:target="submit">Verify phone</span>
                            <span wire:loading wire:target="submit">Verifying…</span>
                            <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                        </button>
                    </div>
                </form>
            @endunless

            {{-- Escape hatch when Sendpipe / WhatsApp is unreachable.
                 Records an explicit skip on the operator so the onboarding
                 middleware treats step 3 as resolved without pretending the
                 number is verified. They can verify from their profile later. --}}
            <button type="button" wire:click="skip"
                    wire:loading.attr="disabled" wire:target="skip"
                    data-testid="skip"
                    class="w-full text-center text-[13px]"
                    style="color: var(--color-ink-mute); background: transparent; border: 0; cursor: pointer;">
                <span wire:loading.remove wire:target="skip">Skip for now, verify later</span>
                <span wire:loading wire:target="skip">Skipping…</span>
            </button>

            {{-- Same live-countdown pattern as email-verification.blade.php.
                 Only rendered once we have a phone on file, otherwise
                 there's nothing to resend and the button would confuse. --}}
            @if ($hasPhone)
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
            @endif
        </div>
    </div>
</div>
