@if (! empty($property->amenities))
    <section>
        <div class="container">
            @if (! empty($attrs['title']))
                <h2>{{ $attrs['title'] }}</h2>
            @endif
            <ul class="amenities">
                @foreach ($property->amenities as $a)
                    <li>{{ is_string($a) ? $a : ($a['label'] ?? '') }}</li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
