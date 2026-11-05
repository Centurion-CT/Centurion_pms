{{-- What guests said about an apartment: $reviews from BookingSite::reviews. --}}
<section class="section" id="reviews">
    <h2 class="subhead">What guests said</h2>
    <p class="review-score"><span aria-hidden="true">★</span> <b>{{ number_format($reviews['average'], 1) }}</b> out of 5, from {{ $reviews['count'] }} {{ \Illuminate\Support\Str::plural('review', $reviews['count']) }}</p>
    <div class="review-list">
        @foreach ($reviews['items'] as $review)
            @php($when = $review->published_at ?? $review->created_at)
            <article class="review-item">
                <p class="review-head">
                    <span class="review-stars" role="img" aria-label="{{ $review->rating }} out of 5">{{ str_repeat('★', $review->rating) }}<i>{{ str_repeat('★', 5 - $review->rating) }}</i></span>
                    <b>{{ \Illuminate\Support\Str::before(trim($review->guest_name), ' ') }}</b>
                    @if ($review->isVerifiedStay())<span class="free">Stayed here</span>@endif
                    @if ($when)<span class="meta">{{ $when->format('M Y') }}</span>@endif
                </p>
                <p class="review-body">{{ $review->body }}</p>
                @if ($review->hasResponse())
                    <p class="review-reply"><b>Reply from {{ $business->name }}</b>{{ $review->response_body }}</p>
                @endif
            </article>
        @endforeach
    </div>
    @if ($reviews['count'] > count($reviews['items']))
        <p class="meta">Showing the latest {{ count($reviews['items']) }} of {{ $reviews['count'] }}.</p>
    @endif
</section>
