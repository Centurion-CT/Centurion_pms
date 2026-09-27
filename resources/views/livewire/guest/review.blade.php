<div class="mx-auto max-w-xl px-4 py-12">
    <div class="kv-card space-y-4">
        <div>
            <p class="kv-muted">Booking {{ $booking->booking_ref }}</p>
            <h1 class="kv-heading">Leave a review</h1>
        </div>

        @if ($submitted)
            <p role="status" class="kv-status">
                Thank you, your review is live. We appreciate the feedback.
            </p>
            <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
               class="text-blue-600 hover:text-blue-700">← Back to booking</a>

        @elseif ($existingReview !== null)
            <p class="kv-status">
                You already submitted a review for this stay
                ({{ str_repeat('★', $existingReview->rating) }}).
                Thank you!
            </p>
            <p class="kv-muted italic">"{{ $existingReview->body }}"</p>
            <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
               class="text-blue-600 hover:text-blue-700">← Back to booking</a>

        @elseif (! $eligible)
            <p class="kv-alert">
                Reviews unlock once your stay is complete. You'll be able to submit one
                after the host marks you checked out on {{ $booking->checkout_date->toFormattedDateString() }}.
            </p>
            <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
               class="text-blue-600 hover:text-blue-700">← Back to booking</a>

        @else
            <p class="text-slate-700">
                How was your stay at <strong>{{ $booking->room->name }}</strong>?
                Your review will appear publicly on the property's landing page.
            </p>

            <form wire:submit="submit" class="space-y-4">
                <div>
                    <label class="kv-label">Rating</label>
                    <select wire:model="rating" data-testid="review-rating" class="kv-select">
                        @for ($i = 5; $i >= 1; $i--)
                            <option value="{{ $i }}">{{ str_repeat('★', $i) }}{{ str_repeat('☆', 5 - $i) }} ({{ $i }})</option>
                        @endfor
                    </select>
                    @error('rating') <p role="alert" class="kv-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="kv-label">Review</label>
                    <textarea wire:model="body" rows="5" required maxlength="2000"
                              minlength="10"
                              data-testid="review-body" class="kv-input"
                              placeholder="What did you like? What could be better? At least 10 characters."></textarea>
                    @error('body') <p role="alert" class="kv-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <a href="/guest/booking/{{ $booking->booking_ref }}?token={{ $token }}" wire:navigate
                       class="kv-btn-secondary">Cancel</a>
                    <button type="submit" wire:loading.attr="disabled"
                            data-testid="review-submit" class="kv-btn-primary">
                        Post review
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
