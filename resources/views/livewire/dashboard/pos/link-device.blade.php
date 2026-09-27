<div class="lp-dash" data-testid="pos-link-device" wire:poll.2s="pollClaim">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Linked devices</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Link a phone
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 60ch;">
                Scan this code with the camera app on any phone. That phone becomes a POS
                terminal signed in as <strong>{{ auth('web')->user()?->name }}</strong>.
                Admins can revoke a linked phone at any time from Staff &rsaquo; Linked devices.
            </p>
        </div>
    </div>

    @if ($claimed)
        <section class="lp-dash-card" data-testid="link-device-claimed"
                 style="max-width: 520px; text-align: center; padding: 32px 28px;">
            <div style="display: inline-flex; width: 56px; height: 56px; border-radius: 50%;
                        align-items: center; justify-content: center;
                        background: color-mix(in srgb, var(--color-accent-deep) 15%, transparent);
                        color: var(--color-accent-deep); margin-bottom: 16px;">
                <svg width="26" height="26" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 10.5l3.5 3.5L15 7"/>
                </svg>
            </div>
            <h2 class="" style="font-family: var(--font-body); letter-spacing: -0.01em; font-size: 22px; font-weight: 600; color: var(--color-ink); margin-bottom: 6px;">
                Phone linked
            </h2>
            <p style="color: var(--color-ink-soft); font-size: 14px;">
                {{ $claimedDeviceLabel }} is now signed in as you.
                It'll show up in Staff &rsaquo; Linked devices for the admin.
            </p>
            <div class="mt-6 flex justify-center gap-2">
                <button type="button" wire:click="regenerate" data-testid="link-device-again" class="lp-btn">
                    Link another phone
                </button>
                <a href="{{ route('tenant.pos.pick-outlet') }}" wire:navigate class="lp-btn lp-btn--primary">
                    Back to POS
                </a>
            </div>
        </section>
    @else
        <section class="lp-dash-card" style="max-width: 520px;" data-testid="link-device-qr-card">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Scan this code</span>
                @if ($expiresIn > 0)
                    <span class="lp-dash-card-meta" data-testid="link-device-expiry">
                        expires in {{ $expiresIn }}s
                    </span>
                @else
                    <span class="lp-dash-card-meta" style="color: #B85C42;">expired</span>
                @endif
            </div>

            @if ($expiresIn > 0)
                <div style="display: flex; justify-content: center; padding: 8px 0 14px;">
                    <div data-testid="link-device-qr"
                         style="background: #fff; padding: 8px; border-radius: 10px;
                                border: 1px solid color-mix(in srgb, var(--color-ink) 8%, transparent);">
                        {!! $qrSvg !!}
                    </div>
                </div>
                <p class="text-[13px]" style="color: var(--color-ink-mute); text-align: center; margin: 6px 0 0;">
                    Waiting for a phone to scan&hellip;
                    <span wire:loading wire:target="pollClaim" style="opacity: 0.6;">checking&hellip;</span>
                </p>
            @else
                <p class="text-[14px]" style="color: var(--color-ink-soft); text-align: center; padding: 30px 8px 20px;"
                   data-testid="link-device-expired">
                    This code has expired. Codes are short-lived on purpose.
                    Generate a fresh one below.
                </p>
                <div class="flex justify-center">
                    <button type="button" wire:click="regenerate" data-testid="link-device-regenerate" class="lp-btn lp-btn--primary">
                        Generate a new code
                    </button>
                </div>
            @endif
        </section>

        <section class="lp-dash-card" style="max-width: 520px;">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">How it works</span>
            </div>
            <ol style="margin: 8px 0 0; padding-left: 20px; color: var(--color-ink-soft); font-size: 14px; line-height: 1.7;">
                <li>Open the camera on your phone and point it at the code.</li>
                <li>Tap the link that pops up. Your phone opens the POS.</li>
                <li>Take payments, scan barcodes, everything the desktop does.</li>
                <li>If you lose the phone, ask a manager to revoke it under Staff &rsaquo; Linked devices.</li>
            </ol>
        </section>
    @endif
</div>
