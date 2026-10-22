<div class="cx-gp">
    @include('partials.public.guest-page-styles')
    @php $back = '/guest/booking/'.$booking->booking_ref.'?token='.$token; @endphp
    <div class="cx-gp-card">
        <a href="{{ $back }}" wire:navigate class="cx-gp-back">← Back to your booking</a>
        <div>
            <p class="cx-gp-kicker">Booking {{ $booking->booking_ref }}</p>
            <h1 class="cx-gp-title">Leave a review</h1>
        </div>

        @if ($submitted)
            <p role="status" class="cx-gp-note cx-gp-note--ok">Thank you, your review is live. We appreciate the feedback.</p>
        @elseif ($existingReview !== null)
            <p class="cx-gp-note cx-gp-note--ok">You already submitted a review for this stay ({{ str_repeat('★', $existingReview->rating) }}). Thank you!</p>
            <p class="cx-gp-text" style="font-style: italic;">"{{ $existingReview->body }}"</p>
        @elseif (! $eligible)
            <p class="cx-gp-note cx-gp-note--warn">Reviews unlock once your stay is complete. You'll be able to leave one after you check out on {{ $booking->checkout_date->format('D, j M Y') }}.</p>
        @else
            <p class="cx-gp-text">How was your stay in <strong>{{ $booking->room->name }}</strong>? Your review appears publicly on the property's website.</p>

            <form wire:submit="submit" style="display: grid; gap: 16px;">
                <div>
                    <label for="review-rating" class="cx-gp-label">Rating</label>
                    <select id="review-rating" wire:model="rating" data-testid="review-rating" class="cx-gp-input">
                        @for ($i = 5; $i >= 1; $i--)
                            <option value="{{ $i }}">{{ str_repeat('★', $i) }}{{ str_repeat('☆', 5 - $i) }} ({{ $i }})</option>
                        @endfor
                    </select>
                    @error('rating') <p role="alert" class="cx-gp-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="review-body" class="cx-gp-label">Your review</label>
                    <textarea id="review-body" wire:model="body" rows="5" required maxlength="2000" minlength="10"
                              data-testid="review-body" class="cx-gp-input"
                              placeholder="What did you like? What could be better? At least 10 characters."></textarea>
                    @error('body') <p role="alert" class="cx-gp-error">{{ $message }}</p> @enderror
                </div>
                <div class="cx-gp-actions">
                    <a href="{{ $back }}" wire:navigate class="cx-btn cx-btn--ghost">Cancel</a>
                    <button type="submit" wire:loading.attr="disabled" data-testid="review-submit" class="cx-btn">Post review</button>
                </div>
            </form>
        @endif
    </div>
</div>
