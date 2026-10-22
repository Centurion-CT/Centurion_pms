{{-- Shared public top bar. Pass `onLanding => true` from the landing
     page for in-page anchors and the docked search pill. --}}
@php
    $cxTenant = $tenant ?? \App\Models\Tenant::current();
    $cxProperty = $property ?? $cxTenant?->property;
    $cxOnLanding = $onLanding ?? false;
    $cxHotel = $cxTenant?->name ?: ($cxProperty?->name ?? config('brand.name'));
    $cxLogo = $cxProperty?->getFirstMedia('logo')?->getUrl();
    $cxKind = $cxProperty?->type?->kind() ?? 'stay';
    [$cxBrowseRoute, $cxBrowseLabel, $cxInventoryLabel] = match ($cxKind) {
        'workspace'   => ['tenant.workspaces.browse', 'See plans',    'Workspaces'],
        'residential' => ['tenant.units.browse',      'Browse homes', 'Homes'],
        default       => ['tenant.rooms.browse',      'Book a stay',  'Rooms'],
    };
    $cxHome = $cxOnLanding ? '' : route('tenant.landing');
    $cxHasAmenities = is_array($cxProperty?->amenities) && array_filter($cxProperty->amenities) !== [];
@endphp
<nav class="cx-top" data-testid="{{ $cxOnLanding ? 'topbar' : 'site-nav' }}">
    <div class="cx-wrap cx-top-inner">
        <a href="{{ $cxOnLanding ? '#top' : route('tenant.landing') }}" class="cx-brand" aria-label="{{ $cxHotel }}">
            <span class="cx-brand-mark"><img src="{{ $cxLogo ?: asset(config('brand.logo')) }}" alt="{{ $cxHotel }}" @if ($cxLogo) data-testid="logo" @endif></span>
            <span class="cx-brand-name">{{ $cxHotel }}</span>
        </a>
        @if ($cxOnLanding && $cxKind === 'stay')
            <button type="button" class="cx-docked" data-search-docked data-modal-open
                    aria-haspopup="dialog" aria-controls="search-modal"
                    data-testid="topbar-search-docked" aria-label="Change your search">
                <span class="cx-docked-text"><span data-summary-dates>Any dates</span><span class="cx-docked-guests">, <span data-summary-guests>2 guests</span></span></span>
                <span class="cx-docked-btn" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="9" cy="9" r="6"/><path d="M17 17l-3.5-3.5"/></svg>
                </span>
            </button>
        @endif
        <div class="cx-nav">
            @if ($cxOnLanding)
                @include('tenant.partials.branch-switcher', ['branches' => $branches ?? collect(), 'current' => $cxProperty])
            @endif
            <a href="{{ route($cxBrowseRoute) }}" @if (request()->routeIs($cxBrowseRoute)) aria-current="page" @endif>{{ $cxInventoryLabel }}</a>
            @if ($cxHasAmenities)<a href="{{ $cxHome }}#included">What's included</a>@endif
            <a href="{{ $cxHome }}#good-to-know">Good to know</a>
            @if ($cxProperty?->isSectionVisible('contact_block'))<a href="{{ $cxHome }}#contact">Contact</a>@endif
        </div>
        <a href="{{ $cxOnLanding && $cxKind === 'stay' ? '#book' : route($cxBrowseRoute) }}" class="cx-top-cta">{{ $cxBrowseLabel }}</a>
    </div>
</nav>
