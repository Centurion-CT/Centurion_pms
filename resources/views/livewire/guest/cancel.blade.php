<div class="cx-gp">
    @include('partials.public.guest-page-styles')
    @php $back = '/guest/booking/'.$booking->booking_ref.'?token='.$token; @endphp
    <div class="cx-gp-card">
        <a href="{{ $back }}" wire:navigate class="cx-gp-back">← Back to your booking</a>
        <p class="cx-gp-kicker">Booking {{ $booking->booking_ref }}</p>
        <h1 class="cx-gp-title">Cancel booking</h1>

        @if ($cancelled)
            <div role="status" class="cx-gp-note cx-gp-note--ok" style="display: grid; gap: 6px;">
                <span>Your booking has been cancelled.</span>
                @if ($resultDepositForfeit)
                    <span>Because check-in was less than 7 days away, the deposit of ₦{{ number_format($booking->deposit_amount) }} is forfeit under the cancellation policy.</span>
                @else
                    <span>The front desk will be in touch about any refund within a few business days.</span>
                @endif
            </div>
        @elseif ($isAlreadyCancelled)
            <p class="cx-gp-note cx-gp-note--warn">This booking is already <strong>{{ str_replace('_', ' ', $booking->booking_status->value) }}</strong> and can't be cancelled.</p>
        @else
            <p class="cx-gp-text">You're about to cancel your stay in <strong>{{ $booking->room->name }}</strong> from {{ $booking->checkin_date->format('D, j M Y') }}.</p>

            @if ($depositForfeitPreview)
                <p role="alert" class="cx-gp-note cx-gp-note--bad">Check-in is less than 7 days away, so the deposit of ₦{{ number_format($booking->deposit_amount) }} will be <strong>forfeit</strong>.</p>
            @else
                <p class="cx-gp-note cx-gp-note--ok">You're outside the 7-day cancellation window, so there's no penalty.</p>
            @endif

            <div class="cx-gp-actions">
                <a href="{{ $back }}" wire:navigate class="cx-btn cx-btn--ghost">Keep my booking</a>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="cx-btn cx-btn--danger">Cancel booking</button>
            </div>
        @endif
    </div>
</div>
