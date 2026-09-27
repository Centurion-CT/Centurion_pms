<div>
    @php
        // Same tiny arrow used elsewhere in the pill CTAs.
        $arrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
    @endphp

    @if ($submitted)
        <div style="margin: 32px auto 0; max-width: 560px; text-align: center;
                    padding: 22px 26px;
                    background: rgba(138, 174, 58, 0.12);
                    border: 1px solid rgba(138, 174, 58, 0.28);
                    border-radius: 14px;
                    color: var(--mkt-ink);"
             data-testid="newsletter-thanks">
            <p style="font-family: 'Fraunces', Georgia, serif; font-size: 20px; font-weight: 600;
                      letter-spacing: -0.015em; margin: 0 0 6px;">
                You're on the list.
            </p>
            <p style="font-size: 13.5px; color: var(--mkt-mute); margin: 0;">
                A welcome note is on its way to <strong style="color: var(--mkt-ink);">{{ $email }}</strong>.
            </p>
        </div>
    @else
        <form wire:submit.prevent="subscribe" class="mkt-newsletter-form"
              style="margin-left: auto; margin-right: auto;"
              data-testid="newsletter-form" novalidate>
            <label style="position: absolute; left: -9999px; opacity: 0; height: 0;" aria-hidden="true">
                Company website
                <input type="text" wire:model="company_website" tabindex="-1" autocomplete="off">
            </label>

            <input type="email" wire:model="email" placeholder="you@yourproperty.com"
                   required aria-label="Email address" autocomplete="email">
            <button type="submit" class="mkt-pill"
                    wire:loading.attr="disabled" wire:target="subscribe">
                <span wire:loading.remove wire:target="subscribe">Subscribe</span>
                <span wire:loading wire:target="subscribe" style="display: none;">Sending…</span>
                <span class="dot">{!! $arrow !!}</span>
            </button>
        </form>
        @error('email')
            <p style="margin: 10px auto 0; max-width: 480px; text-align: center;
                      font-size: 12.5px; color: #B85C42;"
               data-testid="newsletter-error">
                {{ $message }}
            </p>
        @enderror
    @endif
</div>
