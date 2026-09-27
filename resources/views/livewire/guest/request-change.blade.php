<div class="mx-auto max-w-xl px-4 py-12">
    <div class="kv-card space-y-4">
        <h1 class="kv-heading">Request date change</h1>

        @if ($submitted)
            <p role="status" class="kv-status">
                Your request has been sent to {{ $booking->tenant->name }}.
                They'll review it and reply by email.
            </p>
            <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
               class="text-blue-600 hover:text-blue-700">← Back to booking</a>
        @else
            @if ($pendingRequest !== null)
                <p class="kv-status">
                    You have a pending request to move to
                    <strong>{{ $pendingRequest->requested_checkin->toFormattedDateString() }} -
                    {{ $pendingRequest->requested_checkout->toFormattedDateString() }}</strong>.
                    The host hasn't responded yet.
                </p>
                <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
                   class="text-blue-600 hover:text-blue-700">← Back to booking</a>
            @else
                <p class="kv-muted">
                    Current dates:
                    <strong class="text-slate-800">{{ $booking->checkin_date->toFormattedDateString() }} -
                    {{ $booking->checkout_date->toFormattedDateString() }}</strong>
                </p>

                <form wire:submit="submit" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ck-in" class="kv-label">New check-in</label>
                            <input id="ck-in" type="date" wire:model="requested_checkin"
                                   required class="kv-input">
                            @error('requested_checkin') <p role="alert" class="kv-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="ck-out" class="kv-label">New check-out</label>
                            <input id="ck-out" type="date" wire:model="requested_checkout"
                                   required class="kv-input">
                            @error('requested_checkout') <p role="alert" class="kv-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="note" class="kv-label">Note for the host (optional)</label>
                        <textarea id="note" wire:model="guest_note" maxlength="500"
                                  rows="3" class="kv-input"></textarea>
                        @error('guest_note') <p role="alert" class="kv-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
                           class="kv-btn-secondary">Cancel</a>
                        <button type="submit" wire:loading.attr="disabled" class="kv-btn-primary">
                            Send request
                        </button>
                    </div>
                </form>
            @endif
        @endif
    </div>
</div>
