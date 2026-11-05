{{-- Search: where (several buildings), check in, check out, guests. A plain
     GET form to the results page, so it works without JavaScript. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($branches = $site->branches())
@php($picked = collect($where ?? [])->intersect($branches->pluck('slug'))->values())
@php($whereLabel = $picked->isEmpty() || $picked->count() === $branches->count() ? 'All '.$branches->count().' locations' : ($picked->count() === 1 ? $branches->firstWhere('slug', $picked->first())?->name : $picked->count().' locations'))
<form class="search" role="search" action="{{ route('site.results') }}" method="get">
    @if ($branches->count() > 1)
        <details class="seg where-pick">
            <summary><span class="seg-label">Where</span><span class="seg-value">{{ $whereLabel }}</span></summary>
            <div class="popover" role="group" aria-label="Locations">
                @foreach ($branches as $branch)
                    <label class="option">
                        <input type="checkbox" name="where[]" value="{{ $branch->slug }}" @checked($picked->contains($branch->slug))>
                        <span><b>{{ $branch->name }}</b>@if ($branch->city)<br><span class="meta">{{ $branch->city }}</span>@endif</span>
                    </label>
                @endforeach
            </div>
        </details>
    @endif
    @include('site.partials.dates', ['stay' => $stay])
    <label class="seg"><span class="seg-label">Guests</span>
        <select name="adults">
            @foreach (range(1, \App\Site\Stay::MAX_ADULTS) as $n)<option value="{{ $n }}" @selected($stay->adults === $n)>{{ $n }} {{ \Illuminate\Support\Str::plural('adult', $n) }}</option>@endforeach
        </select>
    </label>
    <button class="btn btn-primary btn-lg" type="submit">See what's free</button>
</form>
