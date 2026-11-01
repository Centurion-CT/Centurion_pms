{{-- Search: where (several buildings), check in, check out, guests. A plain
     GET form to the results page, so it works without JavaScript. --}}
@php($site = app(\App\Site\BookingSite::class))
@php($branches = $site->branches())
@php($picked = collect($where ?? [])->intersect($branches->pluck('slug'))->values())
@php($whereLabel = $picked->isEmpty() || $picked->count() === $branches->count() ? ($branches->count() === 1 ? $branches->first()?->name : 'All '.$branches->count().' locations') : ($picked->count() === 1 ? $branches->firstWhere('slug', $picked->first())?->name : $picked->count().' locations'))
<form class="search {{ ($compact ?? false) ? 'search-compact' : '' }}" role="search" action="{{ route('site.results') }}" method="get">
    @if ($branches->count() > 1)
        <details class="seg where where-pick" x-data @click.outside="$el.removeAttribute('open')">
            <summary><span class="seg-label">Where</span><span class="seg-value">{{ $whereLabel }}</span></summary>
            <div class="popover" role="group" aria-label="Locations">
                @foreach ($branches as $branch)
                    <label class="option">
                        <input type="checkbox" name="where[]" value="{{ $branch->slug }}" class="visually-hidden" @checked($picked->contains($branch->slug))>
                        <span class="option-box" aria-hidden="true"></span>
                        <span><b class="h3" style="font-size:var(--t-md)">{{ $branch->name }}</b>@if ($branch->city)<br><span class="meta">{{ $branch->city }}</span>@endif</span>
                    </label>
                @endforeach
            </div>
        </details>
    @endif
    <label class="seg"><span class="seg-label">Check in</span><input type="date" name="in" value="{{ $stay->checkin->toDateString() }}" min="{{ now()->toDateString() }}" required></label>
    <label class="seg"><span class="seg-label">Check out</span><input type="date" name="out" value="{{ $stay->checkout->toDateString() }}" min="{{ now()->addDay()->toDateString() }}" required></label>
    <label class="seg"><span class="seg-label">Guests</span>
        <select name="adults">
            @foreach (range(1, \App\Site\Stay::MAX_ADULTS) as $n)<option value="{{ $n }}" @selected($stay->adults === $n)>{{ $n }} {{ \Illuminate\Support\Str::plural('adult', $n) }}</option>@endforeach
        </select>
    </label>
    <button class="btn btn-primary {{ ($compact ?? false) ? '' : 'btn-lg' }}" type="submit">@unless ($compact ?? false)<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>@endunless Search</button>
</form>
