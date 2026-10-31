{{-- Listing card: photo + availability tag, name, building + sleeps, strip, price. --}}
@php($q = $listing->quote)
@php($tag = $listing->freeForStay === 0 ? ['tag', 'Taken for your dates'] : ($listing->freeForStay === 1 ? ['tag tag-last', 'Last one'] : ['tag tag-free', $listing->freeForStay.' free']))
<a class="listing {{ ($active ?? false) ? 'is-active' : '' }}" href="{{ $href }}" data-branch="{{ $listing->branch->slug }}">
    <div class="listing-photo">
        @if ($photo = $listing->photo())<img src="{{ $photo }}" alt="" loading="lazy">@endif
        <span class="{{ $tag[0] }} tag-onphoto">{{ $tag[1] }}</span>
    </div>
    <div>
        <div class="listing-head"><h3 class="h3">{{ $listing->type->name }}</h3></div>
        <p class="meta">{{ $listing->branch->name }} · sleeps {{ $listing->sleeps() }}</p>
    </div>
    <div>@include('site.partials.strip', ['strip' => $listing->strip, 'nights' => $stay->nights()])</div>
    @if ($q)
        <div class="listing-foot">
            <span class="price">@if ($q->wasPerNight())<s>₦{{ number_format($q->wasPerNight()) }}</s>@endif₦{{ number_format($q->perNight()) }} <span class="unit">a night</span></span>
            <span class="meta">₦{{ number_format($q->total) }} for {{ $q->nights }} {{ \Illuminate\Support\Str::plural('night', $q->nights) }}</span>
        </div>
    @endif
</a>
