<div class="mx-auto max-w-xl px-4 py-12">
    <div class="kv-card space-y-4">
        <h1 class="kv-heading">Pay outstanding balance</h1>

        @if ($booking->balance_due <= 0)
            <p class="kv-status">No balance is outstanding on this booking.</p>
            <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
               class="text-blue-600 hover:text-blue-700">← Back to booking</a>
        @else
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 border-y border-slate-200 py-4 text-sm">
                <div>
                    <dt class="kv-muted">Room</dt>
                    <dd class="font-medium text-slate-900">{{ $booking->room->name }}</dd>
                </div>
                <div>
                    <dt class="kv-muted">Check-in</dt>
                    <dd class="font-medium text-slate-900">{{ $booking->checkin_date->toFormattedDateString() }}</dd>
                </div>
                <div>
                    <dt class="kv-muted">Deposit paid</dt>
                    <dd class="font-medium text-slate-900">₦{{ number_format($booking->deposit_amount) }}</dd>
                </div>
                <div>
                    <dt class="kv-muted">Outstanding balance</dt>
                    <dd class="text-lg font-semibold text-amber-700">
                        ₦{{ number_format($booking->balance_due) }}
                    </dd>
                </div>
            </dl>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
                   class="kv-btn-secondary">Back</a>
                <button type="button" wire:click="pay" wire:loading.attr="disabled" class="kv-btn-primary">
                    Pay ₦{{ number_format($booking->balance_due) }} with Paystack
                </button>
            </div>
        @endif
    </div>
</div>
