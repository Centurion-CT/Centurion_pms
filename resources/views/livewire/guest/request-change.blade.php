<div class="cx-gp">
    @include('partials.public.guest-page-styles')
    @php $back = '/guest/booking/'.$booking->booking_ref.'?token='.$token; @endphp
    <div class="cx-gp-card">
        <a href="{{ $back }}" wire:navigate class="cx-gp-back">← Back to your booking</a>
        <h1 class="cx-gp-title">Change your dates</h1>

        @if ($submitted)
            <p role="status" class="cx-gp-note cx-gp-note--ok">Your request has been sent to {{ $booking->tenant->name }}. They'll review it and reply by email.</p>
        @elseif ($pendingRequest !== null)
            <p class="cx-gp-note cx-gp-note--warn">You've already asked to move to <strong>{{ $pendingRequest->requested_checkin->format('j M') }} to {{ $pendingRequest->requested_checkout->format('j M Y') }}</strong>. The front desk hasn't replied yet.</p>
        @else
            <p class="cx-gp-text">Your current dates: <strong>{{ $booking->checkin_date->format('D j M') }} to {{ $booking->checkout_date->format('D j M Y') }}</strong>. The front desk confirms any change by email.</p>

            <form wire:submit="submit" style="display: grid; gap: 16px;">
                <div class="cx-gp-grid2">
                    <div>
                        <label for="ck-in" class="cx-gp-label">New check in</label>
                        <input id="ck-in" type="date" wire:model="requested_checkin" required class="cx-gp-input">
                        @error('requested_checkin') <p role="alert" class="cx-gp-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ck-out" class="cx-gp-label">New check out</label>
                        <input id="ck-out" type="date" wire:model="requested_checkout" required class="cx-gp-input">
                        @error('requested_checkout') <p role="alert" class="cx-gp-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="note" class="cx-gp-label">Note for the front desk (optional)</label>
                    <textarea id="note" wire:model="guest_note" maxlength="500" rows="3" class="cx-gp-input"></textarea>
                    @error('guest_note') <p role="alert" class="cx-gp-error">{{ $message }}</p> @enderror
                </div>
                <div class="cx-gp-actions">
                    <a href="{{ $back }}" wire:navigate class="cx-btn cx-btn--ghost">Cancel</a>
                    <button type="submit" wire:loading.attr="disabled" class="cx-btn">Send request</button>
                </div>
            </form>
        @endif
    </div>
</div>
