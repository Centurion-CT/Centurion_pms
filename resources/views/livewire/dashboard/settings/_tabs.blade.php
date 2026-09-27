@php
    // Profile is universally available; Property + Staff are
    // manager-only, they map to the broader 'settings' module which
    // only Manager carries.
    $myRole = auth('web')->user()?->role ?? \App\Enums\OperatorRole::Manager;
    $tabs = [
        ['key' => 'profile',  'label' => 'Profile',  'route' => 'tenant.settings.profile',  'module' => 'settings_profile'],
        ['key' => 'property', 'label' => 'Property', 'route' => 'tenant.settings.property', 'module' => 'settings'],
    ];
    $tabs = array_values(array_filter($tabs, fn ($t) => $myRole->canAccessModule($t['module'])));
@endphp
<div class="lp-chips" data-testid="settings-tabs">
    @foreach ($tabs as $t)
        <a href="{{ route($t['route']) }}" wire:navigate
           class="lp-chip @if (($active ?? '') === $t['key']) is-active @endif"
           data-testid="settings-tab-{{ $t['key'] }}">
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
