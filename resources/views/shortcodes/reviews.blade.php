@php
    $reviews = $reviews ?? collect();
    $rating = $rating ?? ['average' => 0, 'count' => 0];
@endphp

@if ($reviews->isNotEmpty())
    <section id="reviews">
        <div class="container">
            @if (! empty($attrs['title']))
                <h2>{{ $attrs['title'] }}</h2>
            @else
                <span class="section-eyebrow">Voices from past guests</span>
                <h2>What guests say</h2>
            @endif
            @if ($rating['count'] > 0)
                <p class="lead">{{ $rating['average'] }} ★ average over {{ $rating['count'] }} verified review{{ $rating['count'] === 1 ? '' : 's' }}.</p>
            @endif

            <div class="reviews-track">
                @foreach ($reviews as $r)
                    <blockquote class="review">
                        <div class="review-stars">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</div>
                        <p>"{{ $r->body }}"</p>
                        <cite>- {{ $r->guest_name }}</cite>
                    </blockquote>
                @endforeach
            </div>
        </div>
    </section>
@endif
