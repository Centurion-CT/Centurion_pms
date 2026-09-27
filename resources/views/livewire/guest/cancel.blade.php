<div class="mx-auto max-w-xl px-4 py-12">
    <div class="kv-card space-y-4">
        <h1 class="kv-heading">Cancel booking, {{ $booking->booking_ref }}</h1>

        @if ($cancelled)
            <div role="status" class="kv-status space-y-2">
                <p>Your booking has been cancelled.</p>
                @if ($resultDepositForfeit)
                    <p>
                        Because check-in was less than 7 days away, the deposit of
                        ₦{{ number_format($booking->deposit_amount) }} is forfeit per the cancellation policy.
                    </p>
                @else
                    <p>The host will be in touch about any refund within a few business days.</p>
                @endif
            </div>
            <p>
                <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}"
                   wire:navigate class="text-blue-600 hover:text-blue-700">← Back to booking</a>
            </p>
        @elseif ($isAlreadyCancelled)
            <p class="kv-alert">
                This booking is already <strong>{{ $booking->booking_status->value }}</strong> and cannot be cancelled.
            </p>
            <p>
                <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}"
                   wire:navigate class="text-blue-600 hover:text-blue-700">← Back to booking</a>
            </p>
        @else
            <p class="text-slate-700">
                You're about to cancel your stay at <strong>{{ $booking->room->name }}</strong> on
                {{ $booking->checkin_date->toFormattedDateString() }}.
            </p>

            @if ($depositForfeitPreview)
                <p role="alert" class="kv-alert">
                    Check-in is less than 7 days away, so the deposit of
                    ₦{{ number_format($booking->deposit_amount) }} will be <strong>forfeit</strong>.
                </p>
            @else
                <p class="kv-status">
                    You are outside the 7-day cancellation window, no penalty will be charged.
                </p>
            @endif

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
                   class="kv-btn-secondary">Keep my booking</a>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled"
                        class="kv-btn-danger">Confirm cancellation</button>
            </div>
        @endif
    </div>
</div>
