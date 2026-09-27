@php
    $rooms = $rooms ?? collect();
    $rating = $rating ?? ['average' => 0, 'count' => 0];
@endphp

<section id="rooms" data-testid="rooms-section">
    <div class="container">
        @if (! empty($attrs['title']))
            <h2>{{ $attrs['title'] }}</h2>
        @else
            <span class="section-eyebrow">Pick your stay</span>
            <h2>Rooms</h2>
        @endif

        @if ($rooms->isEmpty())
            <p class="lead">Rooms coming soon.</p>
        @else
            <div class="room-grid">
                @foreach ($rooms as $room)
                    @php
                        $photos = $room->getMedia('gallery');
                        $hero = $room->heroImage();
                    @endphp
                    <article class="room-card">
                        <div class="room-photo">
                            @if ($hero)
                                <img src="{{ $hero->getUrl('medium') }}"
                                     alt="{{ $hero->getCustomProperty('alt') ?: $room->name }}"
                                     loading="lazy" style="width:100%;height:100%;object-fit:cover;">
                            @else
                                <div class="photo-empty" style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-size:3rem;">
                                    {{ substr($room->name, 0, 1) }}
                                </div>
                            @endif
                        </div>
                        <div class="room-body">
                            <h3>{{ $room->name }}</h3>
                            <p class="room-meta">{{ $room->type }} · sleeps {{ $room->maxOccupancy() }}</p>
                            @if ($rating['count'] > 0)
                                <p class="room-rating"><span class="stars">★</span> {{ $rating['average'] }}</p>
                            @endif
                            <p class="room-price">From ₦{{ number_format($room->base_rate) }} <span class="per">/ night</span></p>
                            <a href="/rooms/{{ $room->id }}" class="room-cta">View &amp; Book</a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
