@php
    use App\Models\Room;
    $id = isset($attrs['id']) ? (int) $attrs['id'] : null;
    $room = $id !== null
        ? Room::query()->active()->find($id)
        : null;
@endphp

@if ($room === null)
    <div style="background:#fef2f2;color:#991b1b;padding:8px 12px;border-radius:6px;font-size:0.85em;">
        ⚠ [room id="…"] could not find an active room with that id.
    </div>
@else
    @php($hero = $room->heroImage())
    <article class="room-card" style="max-width:360px;margin:1rem 0;">
        <div class="room-photo">
            @if ($hero)
                <img src="{{ $hero->getUrl('medium') }}"
                     alt="{{ $hero->getCustomProperty('alt') ?: $room->name }}"
                     loading="lazy" style="width:100%;height:100%;object-fit:cover;">
            @endif
        </div>
        <div class="room-body">
            <h3>{{ $room->name }}</h3>
            <p class="room-meta">{{ $room->type }} · sleeps {{ $room->maxOccupancy() }}</p>
            <p class="room-price">From ₦{{ number_format($room->base_rate) }} <span class="per">/ night</span></p>
            <a href="/rooms/{{ $room->id }}" class="room-cta">View &amp; Book</a>
        </div>
    </article>
@endif
