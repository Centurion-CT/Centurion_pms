{{-- The signed-in person, top right on every staff page: quick actions,
     their profile, the booking site and log out. --}}
@php
    $menuOperator = auth('web')->user();
    $menuInitials = mb_strtoupper(collect(preg_split('/\s+/', trim((string) $menuOperator?->name)))
        ->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join(''));
    $menuRole = $menuOperator?->role?->label();
    $menuShows = fn (string $module): bool => $canSeeModule($module) && $propertyType->exposesModule($module);
    $menuQuick = array_values(array_filter([
        $menuShows('calendar') ? ['New booking', route('tenant.calendar')] : null,
        $menuShows('reservations') ? ['Check in a guest', route('tenant.reservations.index')] : null,
        $menuShows('in_house') ? ['Check out a guest', route('tenant.in-house.index')] : null,
        $menuShows('bookings') ? ['Take a payment', route('tenant.bookings.index')] : null,
    ]));
    $menuLive = \App\Models\Tenant::current()?->property;
@endphp
<div class="lp-user" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" data-testid="dash-user-menu">
    <button type="button" class="lp-user-trigger" x-on:click="open = !open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-haspopup="menu" data-testid="dash-user-trigger">
        <span class="lp-user-avatar" aria-hidden="true">{{ $menuInitials !== '' ? $menuInitials : '?' }}</span>
        <span class="lp-user-who">
            <span class="name">{{ $menuOperator?->name }}</span>
            <span class="role" data-testid="sidebar-signed-in-role">{{ $menuRole }}</span>
        </span>
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4.5 6 8l3-3.5"/></svg>
    </button>
    <div class="lp-user-menu" role="menu" x-show="open" x-transition.opacity.duration.120ms x-cloak data-testid="dash-user-dropdown">
        <div class="lp-user-head">
            <p class="name">{{ $menuOperator?->name }}</p>
            <p class="email">{{ $menuOperator?->email }}</p>
        </div>
        @if ($menuQuick !== [])
            <p class="lp-user-label">Quick actions</p>
            @foreach ($menuQuick as [$label, $href])
                <a href="{{ $href }}" wire:navigate role="menuitem">{{ $label }}</a>
            @endforeach
            <hr>
        @endif
        @if ($canSeeModule('settings_profile'))
            <a href="{{ route('tenant.settings.profile') }}" wire:navigate role="menuitem">My profile</a>
        @endif
        <a href="{{ $menuLive ? route('tenant.branch.show', $menuLive) : route('tenant.landing') }}" target="_blank" rel="noopener" role="menuitem" data-testid="view-live">View booking site</a>
        <hr>
        <form method="POST" action="{{ route('tenant.logout') }}">
            @csrf
            <button type="submit" class="lp-user-logout" role="menuitem" data-billing-safe data-testid="dash-logout">Log out</button>
        </form>
    </div>
</div>
