@php($photos = $property->getMedia('gallery'))

@if ($photos->isNotEmpty())
    <section>
        <div class="container">
            @if (! empty($attrs['title']))
                <h2>{{ $attrs['title'] }}</h2>
            @endif
            <div class="room-grid" style="margin-top:1rem;">
                @foreach ($photos as $p)
                    <div class="room-photo" style="aspect-ratio:4/3;border-radius:12px;overflow:hidden;">
                        <img src="{{ $p->getUrl('medium') }}"
                             alt="{{ $p->getCustomProperty('alt') ?: $property->name }}"
                             loading="lazy"
                             style="width:100%;height:100%;object-fit:cover;">
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
