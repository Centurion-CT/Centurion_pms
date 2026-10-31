@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
<footer class="site-footer">
    <div class="container">
        <div class="cols">
            <div>
                <a class="logo" href="{{ route('tenant.landing') }}">@if ($logo = $site->logo())<img src="{{ $logo }}" alt="">@endif{{ $business->name }}</a>
                @if ($site->branches()->count() > 1)
                    <p style="margin:0;max-width:32ch">{{ $site->branches()->count() }} buildings{{ $site->city() ? ' in '.$site->city() : '' }}, one front desk.</p>
                @endif
            </div>
            <div><h4>Locations</h4>
                @foreach ($site->branches() as $branch)
                    <a href="{{ route('site.results', ['where' => [$branch->slug]]) }}">{{ $branch->name }}</a>
                @endforeach
            </div>
            <div><h4>Stay</h4><a href="{{ route('site.results') }}">Apartments</a><a href="{{ route('site.manage') }}">Manage a booking</a></div>
            <div><h4>Front desk</h4>
                @if ($business->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">{{ $business->phone }}</a>@endif
                @if ($business->email)<a href="mailto:{{ $business->email }}">{{ $business->email }}</a>@endif
            </div>
        </div>
        <div class="base"><span>© {{ date('Y') }} {{ $business->name }}</span><span><a href="{{ route('legal.privacy') }}" style="display:inline">Privacy</a> · <a href="{{ route('legal.terms') }}" style="display:inline">Terms</a></span></div>
    </div>
</footer>
