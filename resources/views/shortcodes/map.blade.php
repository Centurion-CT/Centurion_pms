@if ($property->address)
    <section>
        <div class="container">
            @if (! empty($attrs['title']))
                <h2>{{ $attrs['title'] }}</h2>
            @endif
            <div class="map-wrap">
                <iframe data-testid="map" loading="lazy"
                        src="https://www.google.com/maps?q={{ urlencode($property->address.', '.($property->city ?? '').', '.($property->state ?? '')) }}&output=embed"></iframe>
            </div>
        </div>
    </section>
@endif
