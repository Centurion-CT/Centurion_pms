{{-- Shared public footer. --}}
@php
    $cxTenant = $tenant ?? \App\Models\Tenant::current();
    $cxProperty = $property ?? $cxTenant?->property;
    $cxHotel = $cxTenant?->name ?: ($cxProperty?->name ?? config('brand.name'));
    $cxLogo = $cxProperty?->getFirstMedia('logo')?->getUrl();
    $cxWhere = implode(', ', array_unique(array_filter([$cxProperty?->city, $cxProperty?->state])));
@endphp
<footer class="cx-foot" data-testid="footer">
    <div class="cx-wrap cx-foot-top">
        <a href="{{ route('tenant.landing') }}" class="cx-brand">
            <span class="cx-brand-mark"><img src="{{ $cxLogo ?: asset(config('brand.logo')) }}" alt=""></span>
            <span class="cx-brand-name">{{ $cxHotel }}</span>
        </a>
        @if ($cxWhere !== '')
            <span class="cx-foot-where">{{ $cxWhere }}</span>
        @endif
        @if ($cxTenant?->email)
            <a href="mailto:{{ $cxTenant->email }}" class="cx-foot-cta">Email the front desk</a>
        @endif
    </div>
    <div class="cx-foot-bottom">
        <div class="cx-wrap">
            <span>© {{ date('Y') }} {{ $cxHotel }}</span>
            <nav>
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
                <a href="{{ route('legal.cookies') }}">Cookies</a>
                <span>Powered by @if (config('brand.url'))<a href="{{ config('brand.url') }}" target="_blank" rel="noopener">{{ config('brand.name') }}</a>@else{{ config('brand.name') }}@endif</span>
            </nav>
        </div>
    </div>
</footer>
