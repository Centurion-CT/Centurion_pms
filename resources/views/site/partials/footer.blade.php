@php($site = app(\App\Site\BookingSite::class))
@php($business = $site->business())
<footer class="site-footer">
    <div class="container">
        <div>
            <p class="foot-name">{{ $business->name }}</p>
            <p>
                @if ($business->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone) }}">{{ $business->phone }}</a>@endif
                @if ($business->phone && $business->email) · @endif
                @if ($business->email)<a href="mailto:{{ $business->email }}">{{ $business->email }}</a>@endif
            </p>
        </div>
        <nav aria-label="Footer">
            <a href="{{ route('site.manage') }}">Manage a booking</a>
            <a href="{{ route('legal.privacy') }}">Privacy</a>
            <a href="{{ route('legal.terms') }}">Terms</a>
            <a href="{{ route('legal.cookies') }}">Cookies</a>
            <span>© {{ date('Y') }} {{ $business->name }}</span>
            <span>Runs on Centurion</span>
        </nav>
    </div>
</footer>
