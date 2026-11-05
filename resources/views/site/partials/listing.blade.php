{{-- One apartment type. $row lays it out as a wide row with its button:
     the results page, and a business's only apartment on the home page. --}}
@php($q = $listing->quote)
<a class="listing {{ ($row ?? false) ? 'listing--row' : '' }}" href="{{ route('site.apartment', ['branch' => $listing->branch->slug, 'type' => $listing->type->slug] + $stay->query()) }}" data-branch="{{ $listing->branch->slug }}">
    @if ($photo = $listing->photo())
        <img class="listing-photo" src="{{ $photo }}" alt="" loading="lazy">
    @else
        <span class="listing-photo listing-photo--bars">@include('site.partials.bars')</span>
    @endif
    <span class="listing-body">
        <span class="listing-info">
            <span class="listing-head">
                <span class="listing-name">{{ $listing->type->name }}</span>
                @include('site.partials.free', ['free' => $listing->freeForStay])
            </span>
            <span class="meta">{{ $listing->branch->name }} · sleeps {{ $listing->sleeps() }}</span>
        </span>
        <span class="listing-buy">
            @if ($q)
                <span class="listing-price"><b>@if ($q->wasPerNight())<s>₦{{ number_format($q->wasPerNight()) }}</s> @endif₦{{ number_format($q->perNight()) }}</b> a night</span>
                <span class="meta">₦{{ number_format($q->total) }} for {{ $q->nights }} {{ \Illuminate\Support\Str::plural('night', $q->nights) }}, fees included</span>
            @endif
            @if ($row ?? false)<span class="btn btn-primary">View apartment</span>@endif
        </span>
    </span>
</a>
