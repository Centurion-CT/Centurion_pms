<div class="cx-gp">
    @include('partials.public.guest-page-styles')
    @php $back = '/guest/booking/'.$booking->booking_ref.'?token='.$token; @endphp
    <div class="cx-gp-card">
        <a href="{{ $back }}" wire:navigate class="cx-gp-back">← Back to your booking</a>
        <h1 class="cx-gp-title">Pay your balance</h1>

        @if ($booking->balance_due <= 0)
            <p class="cx-gp-note cx-gp-note--ok">There's nothing left to pay on this booking.</p>
        @else
            <dl class="cx-gp-dl">
                <div><dt>Room</dt><dd>{{ $booking->room->name }}</dd></div>
                <div><dt>Check in</dt><dd>{{ $booking->checkin_date->format('D, j M Y') }}</dd></div>
                <div><dt>Deposit paid</dt><dd>₦{{ number_format($booking->deposit_amount) }}</dd></div>
                <div><dt>Balance due</dt><dd style="color: var(--rose); font-size: 18px;">₦{{ number_format($booking->balance_due) }}</dd></div>
            </dl>
            <div class="cx-gp-actions">
                <a href="{{ $back }}" wire:navigate class="cx-btn cx-btn--ghost">Back</a>
                <button type="button" wire:click="pay" wire:loading.attr="disabled" class="cx-btn">
                    Pay ₦{{ number_format($booking->balance_due) }} with Paystack
                </button>
            </div>
            <p class="cx-gp-text" style="font-size: 13px; text-align: right;">Card, bank transfer or USSD.</p>
        @endif
    </div>
</div>
