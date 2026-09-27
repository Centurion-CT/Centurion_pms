{{--
    Tenant-side layout, shared between guest auth pages (login,
    forgot-password, reset-password) and the authenticated operator
    dashboard. For authenticated requests we render a sidebar shell;
    guests get just the slot (the login view brings its own wrapper).
--}}
<!DOCTYPE html>
{{-- Sidebar variant is rendered directly on <html> so Livewire's
     wire:navigate morph preserves it across nav. If we only set it
     via an inline script, the morph strips the attribute (the
     incoming HTML doesn't carry it) and the sidebar reverts to the
     default tile-grid CSS on every wire:navigate click. --}}
<html lang="en" data-sidebar-variant="{{ auth('web')->user()?->sidebarVariant() ?? 'tiles' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ \App\Models\Tenant::current()?->name }}, Keverna</title>
    @include('partials.favicons')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,500,600,500i|inter:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    {{-- Dashboard search cache config. Consumed by resources/js/search-cache.js.
         Feature-gated inside the module on localStorage.feature.search_cache,
         so this <script> is safe to emit for every operator without
         flipping the cache on globally. --}}
    @auth('web')
        @php
            $__searchCachePropertyId = app(\App\Services\Properties\PropertyContext::class)->current()?->id;
        @endphp
        <script>
            window.KEVERNA_SEARCH_CACHE_CONFIG = {
                tenantSlug: @js(\App\Models\Tenant::current()?->slug ?? ''),
                tenantId:   @js((int) (\App\Models\Tenant::current()?->id ?? 0)),
                operatorId: @js((int) auth('web')->id()),
                propertyId: @js($__searchCachePropertyId),
                endpoint:   @js(route('tenant.dashboard.search-cache')),
                reverb: {
                    key:    @js((string) config('broadcasting.connections.reverb.key', '')),
                    host:   @js((string) config('reverb.servers.reverb.hostname', config('broadcasting.connections.reverb.options.host', ''))),
                    port:   @js((int) config('broadcasting.connections.reverb.options.port', 8080)),
                    scheme: @js((string) config('broadcasting.connections.reverb.options.scheme', 'https')),
                },
            };
        </script>
    @endauth

    {{-- Read-only mode dimmer. When EnforceSubscriptionAccess flags the
         request, common write-action buttons (primary CTAs, danger
         actions, pill buttons, and generic form submits) are visually
         disabled - pointer-events off + faded - so operators see
         upfront that clicks won't do anything. The server-side guard
         in AppServiceProvider is what actually rejects writes; this
         is purely to keep the UI honest. Opt out with
         `data-billing-safe` on any button that must stay clickable
         even in read-only (renew CTA, logout, impersonation end,
         search/filter). --}}
    <style>
        body[data-billing-readonly]
            :is(.lp-btn--primary, .lp-btn--danger, .lp-pill, button[type="submit"]):not([data-billing-safe]) {
            opacity: 0.42;
            pointer-events: none;
            cursor: not-allowed;
            filter: grayscale(0.4);
        }
    </style>

    {{-- Sidebar variant is rendered directly on the <html> tag above
         so Livewire's wire:navigate morph preserves it. No script
         needed. See the comment above <html> for the full story. --}}
</head>
<body class="antialiased"
      data-current-route="{{ request()->route()?->getName() ?? '' }}"
      @if($billingReadOnly ?? false) data-billing-readonly="1" @endif>
    @include('partials.maintenance-banner')

    @if (session('impersonator_admin_id'))
        <div role="alert" data-testid="impersonation-banner"
             class="border-b border-amber-300 bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">
            You're impersonating this tenant as a Keverna admin.
            <form method="POST" action="{{ url('/end-impersonation') }}" class="inline">
                @csrf
                <button type="submit" data-testid="end-impersonation" data-billing-safe
                        class="ml-2 underline hover:no-underline">End impersonation</button>
            </form>
        </div>
    @endif

    @auth('web')
        @php
            // Each nav item: `route` = the exact named route to link to,
            // `prefix` = string used to detect "active" by route-name
            // prefix (e.g. tenant.rooms. catches index/edit/photos).
            // Each nav item now carries a `module` key, used both as
            // the role-based filter (sidebar items disappear when the
            // current operator's role can't access them) and the
            // route-middleware argument (server-side gate).
            // Nav colour palette. Each tile carries a `color` key that
            // resolves to a from → to gradient below. Colours are
            // clustered by function so operators build muscle memory:
            // Bookings-family blues, Housekeeping greens, Finance
            // emeralds, POS reds, Staff / Settings neutrals.
            $navPalette = [
                'slate'   => ['#64748B', '#334155'],
                'blue'    => ['#3B82F6', '#1D4ED8'],
                'cyan'    => ['#06B6D4', '#0E7490'],
                'indigo'  => ['#6366F1', '#3730A3'],
                'amber'   => ['#F59E0B', '#B45309'],
                'rose'    => ['#F43F5E', '#9F1239'],
                'purple'  => ['#A855F7', '#6B21A8'],
                'rust'    => ['#F97316', '#9A3412'],
                'teal'    => ['#14B8A6', '#0F766E'],
                'green'   => ['#22C55E', '#15803D'],
                'emerald' => ['#10B981', '#047857'],
                'pink'    => ['#EC4899', '#9D174D'],
                'violet'  => ['#8B5CF6', '#5B21B6'],
                'fuchsia' => ['#D946EF', '#86198F'],
                'sky'     => ['#0EA5E9', '#0369A1'],
                'yellow'  => ['#EAB308', '#A16207'],
                'orange'  => ['#FB923C', '#C2410C'],
                'red'     => ['#EF4444', '#991B1B'],
                'gray'    => ['#6B7280', '#374151'],
            ];

            $nav = [
                'Operate' => [
                    ['label' => 'Dashboard', 'module' => 'dashboard', 'route' => 'tenant.dashboard',     'prefix' => 'tenant.dashboard',    'color' => 'slate',
                     'icon'  => 'M3 11.5L10 5l7 6.5M5 10v8h10v-8'],
                    ['label' => 'Portfolio', 'module' => 'dashboard', 'route' => 'tenant.portfolio.index', 'prefix' => 'tenant.portfolio.', 'color' => 'olive',
                     'icon'  => 'M3 6h14v10H3zM3 6l2-2h4l2 2M7 10h6M7 13h4'],
                    ['label' => 'Reservations', 'module' => 'reservations', 'route' => 'tenant.reservations.index', 'prefix' => 'tenant.reservations.', 'color' => 'blue',
                     'icon'  => 'M4 6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v9a1 1 0 0 1-1.6.8L10 13l-4.4 2.8A1 1 0 0 1 4 15z'],
                    ['label' => 'Guest requests', 'module' => 'reservations', 'badge_module' => 'guest_requests', 'route' => 'tenant.guest-requests.index', 'prefix' => 'tenant.guest-requests.', 'color' => 'amber',
                     'icon'  => 'M4 4h12v10H8l-4 4V4zM8 8h4M8 11h6'],
                    ['label' => 'Approvals', 'module' => 'reservations', 'badge_module' => 'approvals', 'route' => 'tenant.approvals.index', 'prefix' => 'tenant.approvals.', 'color' => 'rose',
                     'icon'  => 'M4 5h12v10H4zM4 9h12M8 12h4'],
                    ['label' => 'In-house',  'module' => 'in_house', 'route' => 'tenant.in-house.index', 'prefix' => 'tenant.in-house.', 'color' => 'cyan',
                     'icon'  => 'M4 9l6-5 6 5v7a1 1 0 0 1-1 1h-3v-4H8v4H5a1 1 0 0 1-1-1z'],
                    ['label' => 'Bookings',  'module' => 'bookings', 'route' => 'tenant.bookings.index', 'prefix' => 'tenant.bookings.', 'color' => 'indigo',
                     'icon'  => 'M4 5h12v12H4zM4 9h12M8 3v4M12 3v4'],
                    ['label' => 'Invoices',  'module' => 'bookings', 'badge_module' => 'invoices',
                     'route' => 'tenant.invoices.index', 'prefix' => 'tenant.invoices.', 'color' => 'amber',
                     'icon'  => 'M5 3h10v14H5zM5 3v14M8 7h4M8 10h4M8 13h2M14 15l1.5 1.5L18 14'],
                    ['label' => 'Rates',     'module' => 'bookings',
                     'route' => 'tenant.rates.index', 'prefix' => 'tenant.rates.', 'color' => 'olive',
                     'icon'  => 'M3 4h14v12H3zM7 8h6M7 11h4M13 14l1 1 2-2'],
                    ['label' => 'Calendar',  'module' => 'calendar', 'route' => 'tenant.calendar',       'prefix' => 'tenant.calendar', 'color' => 'rose',
                     'icon'  => 'M4 5h12v12H4zM4 9h12M8 3v4M12 3v4M7 13h2M11 13h2'],
                    ['label' => 'Categories', 'module' => 'categories', 'route' => 'tenant.room-categories.index', 'prefix' => 'tenant.room-categories.', 'color' => 'purple',
                     'icon'  => 'M3 5h6v6H3zM11 5h6v6h-6zM3 13h6v4H3zM11 13h6v4h-6z'],
                    ['label' => 'Rooms',     'module' => 'rooms', 'route' => 'tenant.rooms.index',    'prefix' => 'tenant.rooms.', 'color' => 'rust',
                     'icon'  => 'M5 17V7l5-3 5 3v10M5 17h10M8 17v-4h4v4'],
                    ['label' => 'Reports',   'module' => 'reports', 'route' => 'tenant.reports.index',  'prefix' => 'tenant.reports.', 'color' => 'teal',
                     'icon'  => 'M4 17V8M9 17V4M14 17v-6M3 17h14'],
                    ['label' => 'Housekeeping', 'module' => 'housekeeping', 'route' => 'tenant.housekeeping.index', 'prefix' => 'tenant.housekeeping.index', 'color' => 'green',
                     'icon'  => 'M5 4h10v3H5zM5 7v9h10V7M8 11h4M8 13h4'],
                    ['label' => 'Inspections', 'module' => 'housekeeping', 'badge_module' => 'inspections',
                     'route' => 'tenant.housekeeping.inspections.index', 'prefix' => 'tenant.housekeeping.inspections.', 'color' => 'emerald',
                     'icon'  => 'M4 4h9l3 3v9H4zM4 4v12h12M8 9l2 2 4-4M7 13h6'],
                    // Housekeeping supply room, soaps, towels, bedsheets,
                    // amenities. Sits with Housekeeping so front desk and
                    // cleaners find it in the same visual neighbourhood.
                    ['label' => 'Supplies', 'module' => 'supplies', 'badge_module' => 'supplies',
                     'route' => 'tenant.supplies.index', 'prefix' => 'tenant.supplies.', 'color' => 'amber',
                     'icon'  => 'M4 7l6-3 6 3v9l-6 3-6-3zM4 7l6 3 6-3M10 10v9'],
                    ['label' => 'Complimentary', 'module' => 'complimentary', 'route' => 'tenant.complimentary.index', 'prefix' => 'tenant.complimentary.', 'color' => 'pink',
                     'icon'  => 'M4 8h12v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8zM4 8l1-3h10l1 3M8 3v2M10 3v2M12 3v2'],
                    ['label' => 'Membership', 'module' => 'membership', 'route' => 'tenant.membership.index', 'prefix' => 'tenant.membership.', 'color' => 'orange',
                     'icon'  => 'M4 6h12v3H4zM4 9v6h12V9M7 12h3M4 6l1-2h10l1 2'],
                ],
                // Co-working / workspace operators (PropertyType::Workspace).
                'Workspace' => [
                    ['label' => 'Workspaces', 'module' => 'workspaces', 'route' => 'tenant.workspaces.index', 'prefix' => 'tenant.workspaces.', 'color' => 'violet',
                     'icon'  => 'M3 5h14v10H3zM3 9h14M7 13h6M5 17h10'],
                    ['label' => 'Plans', 'module' => 'workspace_plans', 'route' => 'tenant.workspace-plans.index', 'prefix' => 'tenant.workspace-plans.', 'color' => 'fuchsia',
                     'icon'  => 'M5 4h10v12H5zM5 8h10M8 11h4M8 13h2'],
                    ['label' => 'Memberships', 'module' => 'memberships', 'route' => 'tenant.memberships.index', 'prefix' => 'tenant.memberships.', 'color' => 'rose',
                     'icon'  => 'M10 3l2.5 5L18 9l-4 4 1 5.5L10 16l-5 2.5L6 13 2 9l5.5-1z'],
                    ['label' => 'Desk bookings', 'module' => 'desk_bookings', 'route' => 'tenant.desk-bookings.index', 'prefix' => 'tenant.desk-bookings.', 'color' => 'sky',
                     'icon'  => 'M3 8h14v2H3zM4 10v6M16 10v6M6 16v-3M14 16v-3'],
                ],
                // Residential operators (PropertyType::Residential).
                'Residential' => [
                    ['label' => 'Units', 'module' => 'units', 'route' => 'tenant.units.index', 'prefix' => 'tenant.units.', 'color' => 'amber',
                     'icon'  => 'M4 17V8l6-4 6 4v9M4 17h12M8 17v-5h4v5'],
                    ['label' => 'Leases', 'module' => 'leases', 'route' => 'tenant.leases.index', 'prefix' => 'tenant.leases.', 'color' => 'yellow',
                     'icon'  => 'M5 3h7l3 3v11H5zM12 3v3h3M7 9h6M7 11h6M7 13h4'],
                    ['label' => 'Rent schedule', 'module' => 'rent_schedule', 'route' => 'tenant.rent-schedule.index', 'prefix' => 'tenant.rent-schedule.', 'color' => 'rust',
                     'icon'  => 'M3 4h14v3H3zM3 7v9h14V7M6 10h2M10 10h2M14 10h2M6 13h2M10 13h2'],
                ],
                'Relationships' => [
                    ['label' => 'Customers', 'module' => 'customers', 'route' => 'tenant.customers.index', 'prefix' => 'tenant.customers.', 'color' => 'teal',
                     'icon'  => 'M7 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM2 17c0-2.5 2-4.5 5-4.5s5 2 5 4.5M14 11a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM12 17c0-2 1.5-3.5 4-3.5'],
                    ['label' => 'Corporates', 'module' => 'corporates', 'route' => 'tenant.corporates.index', 'prefix' => 'tenant.corporates.', 'color' => 'cyan',
                     'icon'  => 'M4 17V7l4-3 4 3v10M12 17V11l4-2v8M4 17h12M7 11h2M7 14h2'],
                    ['label' => 'Credit lines', 'module' => 'credit_lines', 'route' => 'tenant.credit-lines.index', 'prefix' => 'tenant.credit-lines.', 'color' => 'emerald',
                     'icon'  => 'M3 6h14v9H3zM3 9h14M6 12h3M11 12h4'],
                ],
                'Marketing' => [
                    ['label' => 'Discounts', 'module' => 'discounts', 'route' => 'tenant.discounts.index', 'prefix' => 'tenant.discounts.', 'color' => 'red',
                     'icon'  => 'M13 4l-9 9 3 3 9-9V4zM12.5 6.5h.01'],
                    ['label' => 'Packages', 'module' => 'packages', 'route' => 'tenant.packages.index', 'prefix' => 'tenant.packages.', 'color' => 'purple',
                     'icon'  => 'M10 2l7 4v8l-7 4-7-4V6zM3 6l7 4 7-4M10 10v8'],
                ],
                'Content' => [
                    ['label' => 'Photos',   'module' => 'branding', 'route' => 'tenant.branding.photos', 'prefix' => 'tenant.branding.', 'color' => 'pink',
                     'icon'  => 'M3 5h14v10H3zM7 11l3-3 4 4 3-3'],
                    ['label' => 'Reviews',  'module' => 'reviews', 'route' => 'tenant.reviews.index',  'prefix' => 'tenant.reviews.', 'color' => 'yellow',
                     'icon'  => 'M10 3l2.4 4.9 5.4.8-3.9 3.8.9 5.4L10 15.4 5.2 17.9l.9-5.4L2.2 8.7l5.4-.8z'],
                    ['label' => 'Email templates', 'module' => 'email_templates', 'route' => 'tenant.email-templates.index', 'prefix' => 'tenant.email-templates.', 'color' => 'sky',
                     'icon'  => 'M3 5h14v10H3zM3 5l7 5 7-5'],
                ],
                'Business' => [
                    ['label' => 'Summary',      'module' => 'summary', 'route' => 'tenant.summary', 'prefix' => 'tenant.summary', 'color' => 'slate',
                     'icon'  => 'M3 17V4M3 4l6-2 6 2M3 4l6 3 6-3M9 7v10M15 4v10M3 17l6 2 6-2M3 17h12'],
                    ['label' => 'Finance',      'module' => 'finance', 'route' => 'tenant.finance.index', 'prefix' => 'tenant.finance.', 'color' => 'emerald',
                     'icon'  => 'M4 17V8M9 17V4M14 17v-6M3 17h14M6 6l3 3 3-4 4 5'],
                    ['label' => 'Accounting',   'module' => 'accounting', 'route' => 'tenant.accounting.hub', 'prefix' => 'tenant.accounting.', 'color' => 'rust',
                     'icon'  => 'M4 5h12v4H4zM4 12h7v5H4zM13 12v5M13 14h4'],
                    ['label' => 'Payments',     'module' => 'payments', 'route' => 'tenant.payments.index', 'prefix' => 'tenant.payments.', 'color' => 'green',
                     'icon'  => 'M2 7h16v8H2zM2 10h16M5 13h3'],
                    ['label' => 'Refunds',      'module' => 'refunds', 'route' => 'tenant.refunds.index', 'prefix' => 'tenant.refunds.', 'color' => 'rust',
                     'icon'  => 'M4 10a6 6 0 0 1 11-3M15 4v3h-3M16 10a6 6 0 0 1-11 3M5 16v-3h3'],
                    ['label' => 'Wallets',      'module' => 'finance', 'route' => 'tenant.finance.wallets', 'prefix' => 'tenant.finance.wallets', 'color' => 'teal',
                     'icon'  => 'M4 6h11a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1zM3 8h12M13 12h.01'],
                    ['label' => 'Integrations', 'module' => 'integrations', 'route' => 'tenant.integrations.index', 'prefix' => 'tenant.integrations.', 'color' => 'purple',
                     'icon'  => 'M7 3v3M13 14v3M3 7h3M14 13h3M8 8l4 4M12 8l-4 4M10 5.5V4M10 16v-1.5'],
                    ['label' => 'Store',        'module' => 'store', 'route' => 'tenant.store.index', 'prefix' => 'tenant.store.', 'color' => 'orange',
                     'icon'  => 'M3 8h14l-1 9H4zM3 8V5h14v3M7 12h6'],
                    // Reconciliation shares the 'store' module gate +
                    // the central-store middleware, so it hides in
                    // per-department mode just like the Store nav.
                    ['label' => 'Reconciliation', 'module' => 'store', 'route' => 'tenant.store.reconciliation', 'prefix' => 'tenant.store.reconciliation', 'color' => 'amber',
                     'icon'  => 'M3 5h14v10H3zM3 9h14M6 12h8M6 14h5'],
                    ['label' => 'Inventory',    'module' => 'inventory', 'route' => 'tenant.inventory.index', 'prefix' => 'tenant.inventory.', 'color' => 'rust',
                     'icon'  => 'M3 6l7-3 7 3v9l-7 3-7-3zM3 6l7 3 7-3M10 9v9'],
                    ['label' => 'POS',          'module' => 'pos', 'route' => 'tenant.pos.pick-outlet', 'prefix' => 'tenant.pos.', 'color' => 'red',
                     'icon'  => 'M4 4h12v3H4zM4 9h12v9H4zM7 12h2M11 12h2M7 15h6'],
                    ['label' => 'Subscription', 'module' => 'subscription', 'route' => 'tenant.subscription', 'prefix' => 'tenant.subscription', 'color' => 'violet',
                     'icon'  => 'M3 6h14v10H3zM3 9h14M6 13h3'],
                    ['label' => 'Domain',       'module' => 'domain', 'route' => 'tenant.domain',       'prefix' => 'tenant.domain', 'color' => 'sky',
                     'icon'  => 'M10 17a7 7 0 1 1 0-14 7 7 0 0 1 0 14zM3 10h14M10 3c2 2 3 5 3 7s-1 5-3 7M10 3c-2 2-3 5-3 7s1 5 3 7'],
                    ['label' => 'Staff',        'module' => 'staff', 'route' => 'tenant.staff.index', 'prefix' => 'tenant.staff.index', 'color' => 'cyan',
                     'icon'  => 'M7 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM2 17c0-2.5 2-4.5 5-4.5s5 2 5 4.5M14 11a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM12 17c0-2 1.5-3.5 4-3.5M14.5 5.5l1 1M15 3.5v2M17 4.5h-2'],
                    ['label' => 'Linked devices', 'module' => 'staff', 'route' => 'tenant.staff.devices.index', 'prefix' => 'tenant.staff.devices.', 'color' => 'gray',
                     'icon'  => 'M6 2h5a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM8 12h1M14 6l3 3-3 3M13 9h4'],
                    // Branches - list / manage properties under this org.
                    // Always visible under 'settings' module so a
                    // single-branch org has a discoverable path to add
                    // their second location (the sidebar switcher below
                    // only renders when count > 1, otherwise there's
                    // no way in).
                    ['label' => 'Branches', 'module' => 'settings', 'route' => 'tenant.branches.index', 'prefix' => 'tenant.branches.', 'color' => 'emerald',
                     'icon'  => 'M10 17s-6-5-6-10a6 6 0 1 1 12 0c0 5-6 10-6 10zM10 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4z'],
                    // Settings → Profile is universally available; Property is manager-only.
                    // We surface the entry under the broader 'settings_profile' module so it
                    // shows for every role, and the inner tabs gate themselves separately.
                    ['label' => 'Settings',     'module' => 'settings_profile', 'route' => 'tenant.settings.profile', 'prefix' => 'tenant.settings.', 'color' => 'gray',
                     'icon'  => 'M10 6.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zM16.5 10c0-.5-.05-1-.15-1.45l1.35-1.05-1.75-3.03-1.6.6c-.7-.55-1.5-.95-2.35-1.2L11.75 2h-3.5l-.25 1.87c-.85.25-1.65.65-2.35 1.2l-1.6-.6L2.3 7.5l1.35 1.05c-.1.45-.15.95-.15 1.45s.05 1 .15 1.45L2.3 12.5l1.75 3.03 1.6-.6c.7.55 1.5.95 2.35 1.2l.25 1.87h3.5l.25-1.87c.85-.25 1.65-.65 2.35-1.2l1.6.6 1.75-3.03-1.35-1.05c.1-.45.15-.95.15-1.45z'],
                ],
            ];

            // Sidebar filtering is the intersection of:
            //  1. modules the current operator's role can access, and
            //  2. modules the property's type exposes (a Workspace-type
            //     property hides Rooms even from Managers; a Hotel-type
            //     property hides Workspaces).
            // Items without a `module` key default to visible.
            $currentOperator = auth('web')->user();
            $currentRole = $currentOperator?->role ?? \App\Enums\OperatorRole::Manager;
            // Resolve the operator's currently-selected branch via PropertyContext
            // (falls back to the first property for pre-multi-property tenants,
            // so single-branch orgs see identical behavior).
            $currentProperty = app(\App\Services\Properties\PropertyContext::class)->current();
            $allBranches = app(\App\Services\Properties\PropertyContext::class)->all();
            $propertyType = $currentProperty?->type ?? \App\Enums\PropertyType::Hotel;
            // Stock-model setting: 'per_department' rooms don't have a
            // central store, so the Store nav (and its badge below)
            // are hidden regardless of role or property type.
            $stockModel = $currentProperty?->stock_model
                ?? \App\Enums\StockModel::StoreAndRequisition;
            $storeSuppressed = ! $stockModel->usesCentralStore();
            // Use the operator-level canAccessModule so module-unlock
            // permission grants (Permission enum) light up the nav
            // exactly the same way route middleware honours them.
            $canSeeModule = fn (string $module) => $currentOperator !== null
                ? $currentOperator->canAccessModule($module)
                : $currentRole->canAccessModule($module);
            // Single-unit properties (standalone apartments) hide the
            // hotel-shaped tools that don't apply — no room categories
            // to manage, no room chart, no housekeeping rota, no
            // supplies. Everything the operator needs is on the
            // Property settings + Reservations.
            // Use the operator's *current* branch (via PropertyContext),
            // not `->property` (which is the oldest / "main" branch),
            // so switching to a single_unit branch actually re-shapes
            // the sidebar. Falls back to the tenant's main property
            // for unauthenticated / guest views.
            $singleUnitProbe = app(\App\Services\Properties\PropertyContext::class)->current()
                ?? \App\Models\Tenant::current()?->property;
            $isSingleUnit = $singleUnitProbe?->isSingleUnit() ?? false;
            // A standalone apartment doesn't have a room chart, a
            // housekeeping rota, a store/POS/inventory operation, or
            // hospitality-side add-ons like complimentary tokens.
            // Hide them so the sidebar doesn't wave irrelevant tabs
            // at an operator managing a single unit.
            $singleUnitHidden = [
                'categories', 'rooms', 'housekeeping', 'inspections', 'supplies',
                'store', 'inventory', 'pos', 'complimentary',
            ];
            // "Portfolio" is only a meaningful landing when the tenant
            // owns more than one Property — otherwise it's a rehash of
            // Dashboard. Single-branch operators see Dashboard alone.
            $portfolioShown = (\App\Models\Tenant::current()?->properties()->count() ?? 0) > 1;
            $nav = collect($nav)
                ->map(fn (array $items) => array_values(array_filter(
                    $items,
                    fn (array $item) => (! isset($item['module']) || (
                        $canSeeModule($item['module'])
                        && $propertyType->exposesModule($item['module'])
                    ))
                    && ! ($storeSuppressed && ($item['module'] ?? '') === 'store')
                    && ! ($isSingleUnit && in_array($item['module'] ?? '', $singleUnitHidden, true))
                    && ! (! $portfolioShown && ($item['route'] ?? '') === 'tenant.portfolio.index'),
                )))
                ->filter(fn (array $items) => count($items) > 0)
                ->all();

            $isActive = function (array $item): bool {
                if (request()->routeIs($item['route'])) return true;
                if (str_ends_with($item['prefix'] ?? '', '.')) {
                    return str_starts_with(request()->route()?->getName() ?? '', $item['prefix']);
                }
                return false;
            };

            // Feed the sidebar's Alpine component the flat set of
            // top-level nav landings, one entry per section item,
            // keyed by URL pathname (normalised: lowercased, no
            // trailing slash). The client uses this to:
            //   (a) look up the section containing the current URL
            //       so accordion auto-opens where the user is,
            //   (b) map location.pathname to a canonical nav item
            //       for the "Recently visited" list, visiting deep
            //       pages like /bookings/KV-123 still records the
            //       "Bookings" landing because we longest-match on
            //       path prefix.
            // Sections are just the keys of the filtered $nav array
            //, order preserved for the accordion.
            $sidebarSections = array_keys($nav);
            $sidebarNavItems = [];
            $activeSection = null;
            foreach ($nav as $section => $items) {
                foreach ($items as $item) {
                    $href = route($item['route']);
                    $path = parse_url($href, PHP_URL_PATH) ?? '/';
                    $path = mb_strtolower(rtrim($path, '/') ?: '/');
                    $navColor = $item['color'] ?? 'slate';
                    $sidebarNavItems[$path] = [
                        'label' => $item['label'],
                        'section' => $section,
                        'href' => $href,
                        'path' => $path,
                        'route' => $item['route'],
                        'icon' => $item['icon'],
                        'color' => $navColor,
                        'colorFrom' => $navPalette[$navColor][0] ?? $navPalette['slate'][0],
                        'colorTo'   => $navPalette[$navColor][1] ?? $navPalette['slate'][1],
                    ];
                    if ($activeSection === null && $isActive($item)) {
                        $activeSection = $section;
                    }
                }
            }

            // Per-module count badges shown next to the sidebar label.
            // Two shapes: `count` (the number to render) + `kind`
            // (urgent | warn | info), drives the badge colour. One
            // cheap query each, only fired when the current role can
            // actually see the module so unrelated roles don't hit
            // tables they'd never query.
            //
            //   urgent = red   , needs immediate attention (pending stock request)
            //   warn   = amber , action expected today (arrivals due, unpaid bookings)
            //   info   = grey  , ambient count (in-house guests)
            $badges = [];
            $canQueryBookings = \Illuminate\Support\Facades\Schema::hasTable('bookings');

            // Sidebar badge counts scoped to the branch the operator is
            // working in - same PropertyContext as the pages below.
            // Null pid means single-branch OR no property resolvable,
            // in which case the closure is a no-op and counts stay as
            // they were pre-multi-property.
            $badgePid = $currentProperty?->getKey();
            $branchBooking = fn ($q) => $badgePid === null
                ? $q
                : $q->whereHas('room', fn ($r) => $r->where('property_id', $badgePid));
            $branchRoom = fn ($q) => $badgePid === null
                ? $q
                : $q->where('property_id', $badgePid);

            if (! $storeSuppressed
                && $canSeeModule('store')
                && $propertyType->exposesModule('store')
                && \Illuminate\Support\Facades\Schema::hasTable('stock_requests')) {
                $badges['store'] = [
                    'count' => (int) \App\Models\StockRequest::query()->pending()->count(),
                    'kind'  => 'urgent',
                ];
            }
            // Supplies badge = number of housekeeping-outlet products at
            // or below their low-stock threshold. Signals "attention
            // needed" directly in the sidebar so front desk / cleaners
            // don't have to open the page to know something is low.
            if ($canSeeModule('supplies')
                && \Illuminate\Support\Facades\Schema::hasTable('products')
                && \Illuminate\Support\Facades\Schema::hasTable('outlets')) {
                $housekeepingOutletIds = \App\Models\Outlet::query()
                    ->where('type', \App\Enums\OutletType::Housekeeping)
                    ->where('active', true)
                    ->pluck('id');
                $badges['supplies'] = [
                    'count' => $housekeepingOutletIds->isEmpty()
                        ? 0
                        : (int) \App\Models\Product::query()
                            ->whereIn('outlet_id', $housekeepingOutletIds)
                            ->where('active', true)
                            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
                            ->where('low_stock_threshold', '>', 0)
                            ->count(),
                    'kind' => 'warn',
                ];
            }
            if ($canQueryBookings && $canSeeModule('reservations') && $propertyType->exposesModule('reservations')) {
                // Arrivals expected today, the "will this operator
                // need to check anyone in today?" signal.
                $badges['reservations'] = [
                    'count' => (int) $branchBooking(\App\Models\Booking::query())
                        ->whereDate('checkin_date', \Illuminate\Support\Carbon::today()->toDateString())
                        ->whereIn('booking_status', [
                            \App\Enums\BookingStatus::Confirmed->value,
                            \App\Enums\BookingStatus::OnHold->value,
                        ])
                        ->count(),
                    'kind' => 'warn',
                ];
            }
            if ($canQueryBookings && $canSeeModule('in_house') && $propertyType->exposesModule('in_house')) {
                // Ambient count of guests physically in the property.
                // Rendered in the same amber tone as reservations /
                // inspections so it reads as a peer-level count, not
                // a muted secondary chip.
                $badges['in_house'] = [
                    'count' => (int) $branchBooking(\App\Models\Booking::query())
                        ->where('booking_status', \App\Enums\BookingStatus::CheckedIn->value)
                        ->count(),
                    'kind' => 'warn',
                ];
            }
            if ($canQueryBookings && $canSeeModule('bookings') && $propertyType->exposesModule('bookings')) {
                // Bookings that need admin attention: Pending (unpaid,
                // 15-min sweep candidate) + OnHold (deposit taken,
                // still awaiting full payment / confirmation).
                $badges['bookings'] = [
                    'count' => (int) $branchBooking(\App\Models\Booking::query())
                        ->whereIn('booking_status', [
                            \App\Enums\BookingStatus::Pending->value,
                            \App\Enums\BookingStatus::OnHold->value,
                        ])
                        ->count(),
                    'kind' => 'warn',
                ];
            }
            if ($canSeeModule('housekeeping')
                && $propertyType->exposesModule('housekeeping')
                && \Illuminate\Support\Facades\Schema::hasTable('room_inspections')) {
                // Inspections not yet Passed / Failed, anyone in the
                // signing chain (housekeeper, porter, supervisor) can
                // pull this open queue from a single glance.
                $badges['inspections'] = [
                    'count' => (int) \App\Models\RoomInspection::query()
                        ->whereNotIn('status', [
                            \App\Enums\RoomInspectionStatus::Passed->value,
                            \App\Enums\RoomInspectionStatus::Failed->value,
                        ])
                        ->count(),
                    'kind' => 'warn',
                ];
            }
            if ($canQueryBookings && $canSeeModule('refunds') && \Illuminate\Support\Facades\Schema::hasTable('payments')) {
                // Pending refunds - cancelled / no-show bookings where
                // cash was collected but never refunded. Urgent because
                // it's money we're holding that either needs to go back
                // or be booked as forfeit - either way the ledger
                // shouldn't stay ambiguous.
                $refundStatuses = [
                    \App\Enums\BookingStatus::Cancelled->value,
                    \App\Enums\BookingStatus::NoShow->value,
                ];
                $unresolvedIds = $branchBooking(\App\Models\Booking::query())
                    ->whereIn('booking_status', $refundStatuses)
                    ->pluck('id');
                $pendingCount = 0;
                if ($unresolvedIds->isNotEmpty()) {
                    $ledger = \App\Models\Payment::query()
                        ->whereIn('booking_id', $unresolvedIds)
                        ->whereIn('status', [
                            \App\Enums\PaymentStatus::Succeeded->value,
                            \App\Enums\PaymentStatus::Refunded->value,
                        ])
                        ->toBase()
                        ->selectRaw("booking_id,
                            SUM(CASE WHEN status = 'succeeded' THEN amount ELSE 0 END) as paid,
                            SUM(CASE WHEN status = 'refunded' THEN amount ELSE 0 END) as refunded")
                        ->groupBy('booking_id')
                        ->get();
                    foreach ($ledger as $r) {
                        if (((int) $r->paid) - ((int) $r->refunded) > 0) {
                            $pendingCount++;
                        }
                    }
                }
                $badges['refunds'] = ['count' => $pendingCount, 'kind' => 'urgent'];
            }

            // Guest requests - open requests from the in-room QR portal
            // (complaint / taxi / transfer / order / custom). Same
            // branch scoping as the guest_requests page itself so the
            // sidebar tile count matches what an operator sees when
            // they click through.
            if ($canSeeModule('reservations')
                && \Illuminate\Support\Facades\Schema::hasTable('guest_requests')) {
                $badges['guest_requests'] = [
                    'count' => (int) $branchBooking(\App\Models\GuestRequest::query())
                        ->where('status', 'open')
                        ->count(),
                    'kind'  => 'warn',
                ];
            }

            // Portal approvals - pending geo-fence-held actions. Red
            // because the guest is waiting on a live tap.
            if ($canSeeModule('reservations')
                && \Illuminate\Support\Facades\Schema::hasTable('guest_action_approvals')) {
                $badges['approvals'] = [
                    'count' => (int) $branchBooking(\App\Models\GuestActionApproval::query())
                        ->where('status', 'pending')
                        ->count(),
                    'kind'  => 'urgent',
                ];
            }
        @endphp

        <div class="lp-page lp-shell-dash" data-dash-shell>

            {{-- Drawer backdrop, only visible when .is-drawer-open is
                 toggled on the shell. Tapping it closes the drawer. --}}
            <div class="lp-drawer-backdrop" data-drawer-backdrop aria-hidden="true"></div>

            {{-- ─── Sidebar ─────────────────────────────────────────
                 @persist('tenant-sidebar') keeps the same DOM node
                 across wire:navigate transitions so the operator's
                 scroll position survives a click. The `.is-active`
                 class is then refreshed client-side after each nav
                 by the inline listener at the bottom of the layout. --}}
            @persist('tenant-sidebar')
            <aside class="lp-sidebar" data-testid="dash-sidebar"
                   data-tenant-sidebar
                   x-data="tenantSidebar({
                       sections: {{ \Illuminate\Support\Js::from($sidebarSections) }},
                       navItems: {{ \Illuminate\Support\Js::from($sidebarNavItems) }},
                   })">

                <div class="lp-sidebar-brand">
                    <span class="lp-sidebar-brand-mark" aria-hidden>
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M3 1.5v9M3 6L9 1.5M3 6l6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="square"/>
                        </svg>
                    </span>
                    <span class="lp-sidebar-brand-text">
                        <span class="name">{{ \App\Models\Tenant::current()?->name ?? 'Your property' }}</span>
                        {{-- Operator-mode LED: pulsing olive dot + "Live" label.
                             Counterpart to the admin surface's amber LED
                             (admin/auth/login.blade.php:169-190). Anchors
                             the sidebar as "operator mode" without stealing
                             attention from the property name above it. --}}
                        <span class="sub kv-op-led" data-testid="operator-mode-led">
                            <span class="kv-op-led-dot" aria-hidden="true"></span>
                            <span>Live &middot; your property</span>
                        </span>
                    </span>
                </div>

                {{-- Branch switcher - hides itself if the tenant has one
                     property (pre-multi orgs see nothing new). Selecting
                     a branch POSTs to /dashboard/branches/switch and
                     lands the operator back on the current page. --}}
                @if ($allBranches->count() > 1)
                    <div class="lp-sidebar-branches"
                         x-data="{ open: false }"
                         x-on:click.outside="open = false"
                         x-on:keydown.escape.window="open = false"
                         data-testid="dash-branch-switcher">
                        <button type="button"
                                class="lp-sidebar-branches-trigger"
                                x-on:click="open = !open"
                                x-bind:aria-expanded="open ? 'true' : 'false'"
                                aria-haspopup="listbox"
                                data-testid="dash-branch-switcher-trigger">
                            <span class="label">
                                <span class="eyebrow">Working in</span>
                                <span class="name">{{ $currentProperty?->name ?? 'Pick a branch' }}</span>
                            </span>
                            <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 4.5L6 8l3-3.5"/>
                            </svg>
                        </button>
                        <div x-show="open"
                             x-transition.opacity.duration.120ms
                             x-cloak
                             class="lp-sidebar-branches-menu"
                             role="listbox"
                             data-testid="dash-branch-switcher-menu">
                            @foreach ($allBranches as $branch)
                                <form method="POST" action="{{ route('tenant.branches.switch') }}" style="margin:0;">
                                    @csrf
                                    <input type="hidden" name="property_id" value="{{ $branch->getKey() }}">
                                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                                    <button type="submit"
                                            class="lp-sidebar-branches-item @if ($currentProperty && $currentProperty->getKey() === $branch->getKey()) is-current @endif"
                                            role="option"
                                            aria-selected="{{ $currentProperty && $currentProperty->getKey() === $branch->getKey() ? 'true' : 'false' }}"
                                            data-testid="dash-branch-switcher-item-{{ $branch->slug }}">
                                        <span class="name">{{ $branch->name }}</span>
                                        @if ($branch->city || $branch->state)
                                            <span class="sub">{{ trim(collect([$branch->city, $branch->state])->filter()->implode(', ')) }}</span>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                            <a href="{{ route('tenant.branches.index') }}"
                               class="lp-sidebar-branches-manage"
                               data-testid="dash-branch-switcher-manage">
                                + Manage branches
                            </a>
                        </div>
                    </div>
                @endif

                {{-- Search, filters the nav in place. When active it
                     expands every section so matches surface without
                     the operator having to guess which group holds
                     the label. Empty query returns the accordion. --}}
                <div class="lp-sidebar-search-wrap">
                    <svg class="lp-sidebar-search-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="7" cy="7" r="4.5"/><path d="M13.5 13.5L10.5 10.5"/>
                    </svg>
                    <input type="search"
                           x-model.debounce.100ms="query"
                           @keydown.escape="query = ''"
                           placeholder="Search…"
                           class="lp-sidebar-search"
                           data-testid="sidebar-search"
                           aria-label="Search navigation">
                    <button type="button" x-show="query.length > 0" @click="query = ''"
                            class="lp-sidebar-search-clear" aria-label="Clear search">×</button>
                </div>

                {{-- Recent, up to 6 previously-visited nav landings,
                     rendered as tiles so the visual language is the
                     same as the section grids below. Colour comes
                     from the item entry in $sidebarNavItems, which
                     is what the client stashed at click-time. --}}
                <template x-if="!query && visibleRecent().length > 0">
                    <div class="lp-sidebar-section" data-testid="sidebar-recent">
                        <div class="lp-nav-section-head">Recent</div>
                        <div class="lp-nav-grid">
                            <template x-for="r in visibleRecent()" :key="r.route">
                                <a :href="r.href" wire:navigate class="lp-nav-tile">
                                    <span class="lp-nav-tile-icon"
                                          :style="'--tile-from: ' + (r.colorFrom || '#64748B') + '; --tile-to: ' + (r.colorTo || '#334155')">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path :d="r.icon"/>
                                        </svg>
                                    </span>
                                    <span class="lp-nav-tile-label" x-text="r.label"></span>
                                </a>
                            </template>
                        </div>
                    </div>
                </template>

                @foreach ($nav as $section => $items)
                    <div class="lp-sidebar-section" x-show="sectionVisible('{{ $section }}')" x-cloak>
                        <div class="lp-nav-section-head"
                             data-testid="sidebar-section-{{ \Illuminate\Support\Str::slug($section) }}">
                            {{ $section }}
                        </div>
                        <div class="lp-nav-grid">
                            @foreach ($items as $item)
                                @php
                                    // `module` gates access; `badge_module`
                                    // (optional) is the badge-lookup key. They
                                    // usually match, but for shared-access rows
                                    // like Invoices, which uses `module` =>
                                    // `bookings` for permissions, we split the
                                    // two so the row doesn't inherit the
                                    // Bookings badge count.
                                    $badgeKey = $item['badge_module'] ?? $item['module'] ?? null;
                                    $badge = $badgeKey !== null ? ($badges[$badgeKey] ?? null) : null;
                                    $badgeCount = $badge['count'] ?? 0;
                                    $badgeKind = $badge['kind'] ?? 'urgent';
                                    $labelLower = mb_strtolower($item['label']);
                                    $colorKey = $item['color'] ?? 'slate';
                                    $tileFrom = $navPalette[$colorKey][0] ?? $navPalette['slate'][0];
                                    $tileTo   = $navPalette[$colorKey][1] ?? $navPalette['slate'][1];
                                @endphp
                                <a href="{{ route($item['route']) }}" wire:navigate
                                   class="lp-nav-tile {{ $isActive($item) ? 'is-active' : '' }}"
                                   data-nav-label="{{ $labelLower }}"
                                   x-show="itemVisible('{{ $labelLower }}')"
                                   data-testid="nav-{{ \Illuminate\Support\Str::slug($item['label']) }}"
                                   aria-label="{{ $item['label'] }}">
                                    <span class="lp-nav-tile-icon"
                                          style="--tile-from: {{ $tileFrom }}; --tile-to: {{ $tileTo }};">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="{{ $item['icon'] }}"/>
                                        </svg>
                                        {{-- Always emit the badge span; hidden via the
                                             `hidden` attribute when count=0. The persisted
                                             sidebar can't be re-rendered by wire:navigate,
                                             so the script below updates text + visibility
                                             on every navigation from the fresh $badges
                                             payload. Using `hidden` (not inline style) so
                                             the JS toggle plays nicely with persist —
                                             clearing the attribute always reveals the
                                             badge regardless of any stale inline style. --}}
                                        <span class="lp-nav-tile-badge lp-nav-tile-badge--{{ $badgeKind }}"
                                              data-testid="nav-badge-{{ $badgeKey ?? 'x' }}"
                                              data-badge-key="{{ $badgeKey ?? 'x' }}"
                                              aria-label="{{ $badgeCount }}"
                                              @if ($badgeCount === 0) hidden @endif>
                                            {{ $badgeCount > 99 ? '99+' : $badgeCount }}
                                        </span>
                                    </span>
                                    <span class="lp-nav-tile-label">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- No-results message while searching. --}}
                <div x-show="query.length > 0 && noMatches()" x-cloak
                     data-testid="sidebar-no-matches"
                     class="lp-sidebar-empty">
                    No matches for “<span x-text="query"></span>”.
                </div>

                <div class="lp-sidebar-foot">
                    {{-- Sidebar-variant toggle. Two concepts live in the
                         same DOM; CSS + html[data-sidebar-variant] flips
                         between them, so no re-render / no wire hop. --}}
                    <button type="button"
                            class="lp-sidebar-variant-toggle"
                            data-testid="sidebar-variant-toggle"
                            x-data="{
                                variant: document.documentElement.getAttribute('data-sidebar-variant') || 'tiles',
                                async flip() {
                                    const next = this.variant === 'list' ? 'tiles' : 'list';
                                    const prev = this.variant;
                                    // Optimistic flip: apply immediately, then persist.
                                    this.variant = next;
                                    document.documentElement.setAttribute('data-sidebar-variant', next);
                                    try {
                                        const token = document.querySelector('meta[name=\'csrf-token\']')?.content || '';
                                        const res = await fetch('{{ route('tenant.preferences.sidebar-variant') }}', {
                                            method: 'PUT',
                                            credentials: 'same-origin',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'Accept': 'application/json',
                                                'X-CSRF-TOKEN': token,
                                                'X-Requested-With': 'XMLHttpRequest',
                                            },
                                            body: JSON.stringify({ variant: next }),
                                        });
                                        if (! res.ok) throw new Error('HTTP ' + res.status);
                                        window.KevernaOperatorPrefs?.set('sidebar_variant', next);
                                    } catch (e) {
                                        this.variant = prev;
                                        document.documentElement.setAttribute('data-sidebar-variant', prev);
                                        this.$dispatch('notify', {
                                            title: 'Sidebar not saved',
                                            message: 'The layout flipped but we could not store your pick. Try again shortly.',
                                            kind: 'err',
                                        });
                                    }
                                }
                            }"
                            x-on:click="flip()"
                            aria-label="Switch sidebar layout">
                        {{-- Grid icon shown when currently in list mode (points to what you'd switch TO) --}}
                        <svg x-show="variant === 'list'" x-cloak width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden>
                            <rect x="2" y="2" width="3.5" height="3.5"/><rect x="6.5" y="2" width="3.5" height="3.5"/><rect x="2" y="6.5" width="3.5" height="3.5"/><rect x="6.5" y="6.5" width="3.5" height="3.5"/>
                        </svg>
                        {{-- List icon shown when currently in tiles mode --}}
                        <svg x-show="variant !== 'list'" x-cloak width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden>
                            <path d="M2 3h8M2 6h8M2 9h8"/>
                        </svg>
                        <span class="lp-sidebar-variant-toggle-label">
                            <span x-show="variant === 'list'" x-cloak>Switch to tiles</span>
                            <span x-show="variant !== 'list'" x-cloak>Switch to list</span>
                        </span>
                    </button>

                    {{-- Signed-in identity chip: shows who the operator is
                         and which role they're carrying so they know what
                         they can and can't do at a glance. Placed above
                         the foot links so it reads as an anchor rather
                         than an afterthought.  --}}
                    @if ($currentOperator !== null)
                        @php
                            $signedInInitial = mb_strtoupper(mb_substr((string) $currentOperator->name, 0, 1));
                            $signedInRoleLabel = $currentRole->label();
                        @endphp

                        <div data-testid="sidebar-signed-in"
                             style="display: flex; align-items: center; gap: 10px;
                                    padding: 10px 12px; margin-bottom: 6px;
                                    border-radius: 10px; background: rgba(255,255,255,0.05);">
                            <span aria-hidden
                                  style="display: inline-flex; align-items: center; justify-content: center;
                                         width: 30px; height: 30px; flex-shrink: 0; border-radius: 999px;
                                         background: var(--color-accent-deep); color: var(--color-card);
                                         font-size: 12.5px; font-weight: 600; letter-spacing: -0.01em;">
                                {{ $signedInInitial !== '' ? $signedInInitial : '?' }}
                            </span>
                            <span style="display: grid; gap: 1px; min-width: 0; flex: 1;">
                                <span style="color: var(--color-card); font-size: 12.5px; font-weight: 500;
                                             overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                      title="{{ $currentOperator->name }}">
                                    {{ $currentOperator->name }}
                                </span>
                                <span data-testid="sidebar-signed-in-role"
                                      class="lp-mono"
                                      style="color: var(--color-accent); font-size: 10px;
                                             letter-spacing: 0.08em; text-transform: uppercase;
                                             overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ $signedInRoleLabel }}
                                </span>
                            </span>
                        </div>
                    @endif

                    {{-- "View live page" opens the public landing page on
                         a new tab so the operator can sanity-check their
                         changes without losing their dashboard place. --}}
                    <a href="{{ route('tenant.landing') }}" target="_blank" rel="noopener"
                       class="lp-sidebar-link" data-testid="view-live">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4h5v5M8 12l8-8M15 11v5H4V5h5"/>
                        </svg>
                        View live page
                    </a>
                    <form method="POST" action="{{ route('tenant.logout') }}">
                        @csrf
                        <button type="submit" data-billing-safe class="lp-sidebar-link w-full text-left" style="cursor: pointer; background: transparent; border: 0; font-family: inherit; font-size: inherit;">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 3H5v14h6M9 10h9M15 7l3 3-3 3"/>
                            </svg>
                            Log out
                        </button>
                    </form>
                </div>
            </aside>
            @endpersist

            {{-- Fresh badge counts published outside the sidebar's
                 persist boundary. Wire:navigate re-renders the body
                 (excluding persisted regions) so this script re-runs
                 with the newly-computed $badges after each navigation
                 and updates the persisted badge DOM in place. --}}
            <script>
                (() => {
                    const fresh = @json($badges);
                    const apply = () => {
                        document.querySelectorAll('[data-badge-key]').forEach((el) => {
                            const key = el.getAttribute('data-badge-key');
                            const badge = fresh[key];
                            const count = badge ? Number(badge.count || 0) : 0;
                            const kind = badge?.kind || 'urgent';
                            if (count > 0) {
                                el.textContent = count > 99 ? '99+' : String(count);
                                el.setAttribute('aria-label', String(count));
                                el.classList.remove(
                                    'lp-nav-tile-badge--urgent',
                                    'lp-nav-tile-badge--warn',
                                    'lp-nav-tile-badge--info',
                                );
                                el.classList.add('lp-nav-tile-badge--' + kind);
                                // Clear both attribute + inline style — legacy
                                // renders from before the refactor may have
                                // baked `style="display: none;"` into the
                                // persisted sidebar; new renders use the
                                // `hidden` attribute. Handle both.
                                el.removeAttribute('hidden');
                                el.style.display = '';
                            } else {
                                el.setAttribute('hidden', '');
                            }
                        });
                    };
                    apply();
                    // Re-apply on every Livewire navigation in case the
                    // persisted sidebar's badges get out of sync (which
                    // is exactly the bug this handler was written for).
                    document.addEventListener('livewire:navigated', apply);
                })();
            </script>

            {{-- ─── Main content area ─────────────────────────────── --}}
            <div class="lp-main">
                {{-- ── Trial / renewal expiry banner ────────────────
                     Prompts renewal when the sub is within its "ending
                     soon" window (5 days for a trial, 14 for annual).
                     Distinct from the post-expiry read-only bar at the
                     bottom — this fires BEFORE expiry so the operator
                     has runway to route payment. --}}
                @php
                    $expirySub = \App\Models\Tenant::current()?->currentSubscription();
                    $expiryDaysLeft = $expirySub?->isEndingSoon()
                        ? $expirySub->daysUntilExpiry()
                        : null;
                    $expiryIsTrial = $expirySub?->plan === \App\Enums\SubscriptionPlan::Trial;
                    $expiryDate = $expirySub?->expires_at?->toFormattedDateString();
                @endphp
                @if ($expiryDaysLeft !== null)
                    <div role="status"
                         data-testid="expiry-warning-bar"
                         data-days-left="{{ $expiryDaysLeft }}"
                         style="background: #FEF3C7;
                                border-bottom: 1px solid #F5D48C;
                                padding: 10px 20px;
                                display: flex; align-items: center; gap: 14px;
                                flex-wrap: wrap; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 10px;
                                    min-width: 0; flex: 1 1 260px;">
                            <span aria-hidden
                                  style="display: inline-flex; align-items: center; justify-content: center;
                                         width: 22px; height: 22px; border-radius: 999px;
                                         background: #A8650A; color: #FEF3C7;
                                         font-size: 13px; font-weight: 700; flex-shrink: 0;">!</span>
                            <p style="margin: 0; font-size: 13px; line-height: 1.4; color: #78350F;">
                                @if ($expiryIsTrial)
                                    <strong style="font-weight: 600;">
                                        Your trial ends in
                                        {{ $expiryDaysLeft }} day{{ $expiryDaysLeft === 1 ? '' : 's' }}
                                        ({{ $expiryDate }}).
                                    </strong>
                                    Upgrade before then to keep everything running.
                                @else
                                    <strong style="font-weight: 600;">
                                        Your subscription renews in
                                        {{ $expiryDaysLeft }} day{{ $expiryDaysLeft === 1 ? '' : 's' }}
                                        ({{ $expiryDate }}).
                                    </strong>
                                    Confirm payment early to avoid an interruption on the renewal date.
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('tenant.subscription') }}"
                           wire:navigate
                           data-testid="expiry-warning-cta"
                           style="background: #A8650A; color: #FEF3C7;
                                  padding: 7px 14px; border-radius: 999px;
                                  font-size: 12px; font-weight: 600;
                                  text-decoration: none;
                                  display: inline-flex; align-items: center; gap: 6px;
                                  flex-shrink: 0;">
                            {{ $expiryIsTrial ? 'Upgrade' : 'Renew now' }}
                            <span aria-hidden>&rarr;</span>
                        </a>
                    </div>
                @endif

                <header class="lp-topbar" data-testid="dash-topbar">
                    <div class="flex items-center gap-3 min-w-0">
                        {{-- Mobile-only hamburger that toggles the
                             sidebar drawer. Hidden ≥901px via CSS. --}}
                        <button type="button" class="lp-drawer-trigger"
                                data-drawer-trigger aria-label="Open navigation"
                                data-testid="dash-drawer-trigger">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                                <path d="M2 4h12M2 8h12M2 12h12"/>
                            </svg>
                        </button>

                        {{-- Mobile sees the property name in the topbar
                             (sidebar is hidden < 900px). Desktop hides it
                             since the sidebar already shows it. --}}
                        <span class="lp-topbar-title lp-display text-[15px] font-medium truncate"
                              style="color: var(--color-ink); max-width: 360px;">
                            {{ \App\Models\Tenant::current()?->name }}
                        </span>
                    </div>
                    {{-- Global topbar search, sits between the property
                         name and the notification / operator group.
                         Available on every dashboard page. Matches
                         booking ref, guest name, email, or phone
                         within the current property scope. --}}
                    <div class="lp-topbar-search hidden md:block" data-testid="dash-topbar-search">
                        <livewire:dashboard.global-search />
                    </div>

                    <div class="flex items-center gap-3">
                        {{-- Notification bell - anyone who could be a
                             target of an alert sees it. That expands
                             beyond FrontDesk / Manager to cover Stock
                             keepers (store requests), Inventory clerks
                             (low stock), and Housekeeping roles
                             (supplies low). Chime + red dot on new
                             activity; wire:poll.30s inside the
                             component so no work here. --}}
                        @if ($canSeeModule('reservations')
                            || $canSeeModule('store')
                            || $canSeeModule('inventory')
                            || $canSeeModule('supplies'))
                            <div style="display: inline-flex; align-items: center;
                                        color: var(--color-ink);"
                                 data-testid="dash-topbar-bell">
                                <livewire:dashboard.notification-bell />
                            </div>
                        @endif

                        {{-- Email shows on tablet+ only, the phone
                             topbar has no horizontal room left after
                             the hamburger + property name, and the
                             info isn't actionable. --}}
                        <span class="lp-mono text-[10.5px] tracking-[0.16em] uppercase hidden md:inline"
                              style="color: var(--color-ink-mute);">
                            {{ auth()->user()?->email }}
                        </span>
                    </div>
                </header>

                <main @if($billingReadOnly ?? false) style="padding-bottom: 88px;" @endif>
                    {{ $slot }}

                    {{-- Contextual feedback prompt. Sits inline at the
                         end of the page's content (not floating), like
                         Claude Code's periodic asks — reveals after an
                         8s dwell or first scroll, dismissable via ×,
                         and honours the same per-module 30d cooldown. --}}
                    <div style="margin: 32px 0 8px;">
                        @livewire('dashboard.feedback.prompt')
                    </div>
                </main>
            </div>
        </div>

        {{-- Trial-ended / subscription-expired bar. Rendered when the
             EnforceSubscriptionAccess middleware flags the request as
             read-only. Fixed to the viewport bottom, sits above the
             notify modal in z-order but under any Alpine open drawer.
             The only affordance is a link to /subscription - the same
             page the write-guard also bounces failed actions to. --}}
        @if($billingReadOnly ?? false)
            <div role="status" data-testid="billing-readonly-bar"
                 style="position: fixed; left: 0; right: 0; bottom: 0; z-index: 40;
                        background: var(--color-ink, #1a1a1a); color: #fff;
                        border-top: 1px solid rgba(255,255,255,0.08);
                        box-shadow: 0 -6px 24px rgba(0,0,0,0.18);">
                <div style="max-width: 1200px; margin: 0 auto;
                            padding: 14px 20px;
                            display: flex; align-items: center; gap: 16px;
                            justify-content: space-between; flex-wrap: wrap;">
                    <div style="min-width: 0; flex: 1 1 260px;">
                        <p style="font-weight: 600; font-size: 14px; line-height: 1.35;">
                            Your trial has ended.
                        </p>
                        <p style="font-size: 12.5px; opacity: 0.78; margin-top: 2px;">
                            Everything is view-only until you renew. Bookings, room edits, and payments are paused.
                        </p>
                    </div>
                    <a href="{{ route('tenant.subscription') }}"
                       style="background: #fff; color: var(--color-ink, #1a1a1a);
                              padding: 10px 18px; border-radius: 999px;
                              font-weight: 600; font-size: 13.5px;
                              text-decoration: none; white-space: nowrap;">
                        Renew subscription
                    </a>
                </div>
            </div>
        @endif

        {{-- Global success / error modal. Any Livewire component
             on an authed page can trigger it with:
                 $this->dispatch('notify', title: '…', message: '…', kind: 'ok');
             See resources/views/partials/notify-modal.blade.php. --}}
        @include('partials.notify-modal')

        {{-- Livewire sync status pill + retry-on-fail toast.
             Sits fixed at top-centre; shows "Syncing…" after a
             short delay while a request is in flight, and flips to
             "Connection issue - Retry" when a request fails so the
             front desk can recover from a dropped network without
             losing the reservation form they're filling. --}}
        @include('partials.wire-sync-status')

        {{-- Alpine component: search + recent list. Sections used to be
             a single-open accordion; that made every cross-section jump
             cost two clicks. All sections now render open by default
             (they're a compact tile grid, not verbose link rows), so
             this component only owns search filtering + the "Recently
             visited" rail. Registered on `alpine:init` so the
             @persist'd sidebar's x-data hydrates on first render AND
             every subsequent wire:navigate. --}}
        <script>
            document.addEventListener('alpine:init', function () {
                Alpine.data('tenantSidebar', function (opts) {
                    return {
                        sections: opts.sections || [],
                        navItems: opts.navItems || {},   // { pathname: {label,section,href,route,icon,colorFrom,colorTo} }
                        query: '',
                        recent: [],
                        STORAGE_RECENT: 'kv-sidebar-recent',

                        init: function () {
                            // Recent: load the stored list, then push the
                            // current page so it's ready for the next nav.
                            try {
                                var raw = localStorage.getItem(this.STORAGE_RECENT);
                                this.recent = raw ? JSON.parse(raw) : [];
                            } catch (e) { this.recent = []; }
                            this.trackRecent();

                            // Re-run tracking on wire:navigate - the
                            // persisted sidebar node survives page
                            // transitions, so Alpine.init doesn't fire
                            // again and we need to hook the router event
                            // to keep Recent fresh.
                            var self = this;
                            document.addEventListener('livewire:navigated', function () {
                                self.trackRecent();
                            });
                        },

                        currentNavItem: function () {
                            var path = (window.location.pathname.replace(/\/$/, '') || '/').toLowerCase();
                            // Longest-match so deep pages (e.g. /bookings/KV-1)
                            // still resolve to the top-level nav landing.
                            var best = null;
                            for (var key in this.navItems) {
                                if (path === key || path.indexOf(key + '/') === 0) {
                                    if (best === null || key.length > best.length) best = key;
                                }
                            }
                            return best ? this.navItems[best] : null;
                        },

                        trackRecent: function () {
                            var item = this.currentNavItem();
                            if (!item) return;
                            var deduped = this.recent.filter(function (r) { return r.route !== item.route; });
                            this.recent = [item].concat(deduped).slice(0, 6);
                            try { localStorage.setItem(this.STORAGE_RECENT, JSON.stringify(this.recent)); } catch (e) {}
                        },

                        visibleRecent: function () {
                            var current = this.currentNavItem();
                            var currentRoute = current ? current.route : null;
                            return this.recent
                                .filter(function (r) { return r.route !== currentRoute; })
                                .slice(0, 3);
                        },

                        sectionVisible: function (section) {
                            if (this.query.length === 0) return true;
                            var q = this.query.toLowerCase().trim();
                            for (var key in this.navItems) {
                                var i = this.navItems[key];
                                if (i.section === section && i.label.toLowerCase().indexOf(q) !== -1) return true;
                            }
                            return false;
                        },

                        itemVisible: function (labelLower) {
                            if (this.query.length === 0) return true;
                            return labelLower.indexOf(this.query.toLowerCase().trim()) !== -1;
                        },

                        noMatches: function () {
                            if (this.query.length === 0) return false;
                            var q = this.query.toLowerCase().trim();
                            for (var key in this.navItems) {
                                if (this.navItems[key].label.toLowerCase().indexOf(q) !== -1) return false;
                            }
                            return true;
                        },
                    };
                });
            });
        </script>

        {{-- Sidebar persistence helpers, the @persist block above
             keeps the DOM node across wire:navigate transitions, so
             scrollTop survives a click. But the server-rendered
             `is-active` class gets baked in at first paint and never
             refreshes; this listener re-applies it from the URL on
             every navigation. --}}
        <script>
            (function () {
                function scrollActiveIntoView() {
                    var sidebar = document.querySelector('[data-tenant-sidebar]');
                    if (!sidebar) return;
                    var active = sidebar.querySelector('a.lp-nav-tile.is-active');
                    if (!active) return;
                    // `block: 'nearest'` = don't scroll if already in view;
                    // otherwise scroll just enough to reveal it. `behavior:
                    // 'auto'` = instant, no jarring animation on nav.
                    if (typeof active.scrollIntoView === 'function') {
                        active.scrollIntoView({ block: 'nearest', behavior: 'auto' });
                    }
                }

                function refreshSidebarActive() {
                    var sidebar = document.querySelector('[data-tenant-sidebar]');
                    if (!sidebar) return;
                    var current = (location.pathname.replace(/\/$/, '') || '/').toLowerCase();
                    sidebar.querySelectorAll('a.lp-nav-tile').forEach(function (a) {
                        // Only match links that target the dashboard host
                        // (skip the "View live page" cross-domain link).
                        var url;
                        try { url = new URL(a.href, location.origin); } catch (e) { return; }
                        if (url.host !== location.host) return;
                        var linkPath = (url.pathname.replace(/\/$/, '') || '/').toLowerCase();
                        var active;
                        if (linkPath === '/dashboard') {
                            active = current === '/dashboard';
                        } else if (linkPath !== '/') {
                            active = current === linkPath || current.indexOf(linkPath + '/') === 0;
                        } else {
                            active = false;
                        }
                        a.classList.toggle('is-active', active);
                    });
                    scrollActiveIntoView();
                }

                // Optimistic active state, on click, mark the target tile
                // active *immediately* so the operator gets instant visual
                // feedback instead of waiting for wire:navigate's round-trip.
                // refreshSidebarActive() reconciles on livewire:navigated in
                // case the target route redirected somewhere else.
                function markClickedActive(target) {
                    var sidebar = document.querySelector('[data-tenant-sidebar]');
                    if (!sidebar) return;
                    sidebar.querySelectorAll('a.lp-nav-tile.is-active').forEach(function (a) {
                        if (a !== target) a.classList.remove('is-active');
                    });
                    target.classList.add('is-active');
                }

                document.addEventListener('livewire:navigated', refreshSidebarActive);
                document.addEventListener('DOMContentLoaded', function () {
                    refreshSidebarActive();
                });

                // Mobile sidebar drawer, open/close handlers. The CSS
                // gates everything behind ≤900px so on desktop this code
                // runs but has no visual effect.
                function setDrawer(open) {
                    var shell = document.querySelector('[data-dash-shell]');
                    if (!shell) return;
                    shell.classList.toggle('is-drawer-open', open);
                    // Lock body scroll while the drawer is up so the page
                    // behind doesn't scroll under the operator's finger.
                    document.body.style.overflow = open ? 'hidden' : '';
                }
                document.addEventListener('click', function (e) {
                    if (e.target.closest('[data-drawer-trigger]')) {
                        setDrawer(true);
                        return;
                    }
                    if (e.target.closest('[data-drawer-backdrop]')) {
                        setDrawer(false);
                        return;
                    }
                    // Tapping a nav tile should close the drawer too, and
                    // mark it active straight away for instant visual
                    // feedback (wire:navigate's round-trip is otherwise
                    // silent on the sidebar until the new page paints).
                    var tile = e.target.closest('[data-tenant-sidebar] a.lp-nav-tile');
                    if (tile) {
                        markClickedActive(tile);
                        setDrawer(false);
                        return;
                    }
                    // Foot links (View live page, Logout form) close the
                    // drawer but don't touch the active-state.
                    var footLink = e.target.closest('[data-tenant-sidebar] a.lp-sidebar-link');
                    if (footLink && footLink.target !== '_blank') {
                        setDrawer(false);
                    }
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') setDrawer(false);
                });
                // Close on resize past the breakpoint so a rotation /
                // window resize back to desktop doesn't leave the
                // backdrop visually stuck.
                window.addEventListener('resize', function () {
                    if (window.innerWidth > 900) setDrawer(false);
                });
            })();
        </script>
    @endauth

    @guest('web')
        <main>{{ $slot }}</main>
        {{-- Floating WhatsApp CTA on guest auth surfaces only — the
             dashboard has its own notification bell + support link
             mid-nav, so a floating icon there would just be noise. --}}
        @include('partials.whatsapp-float', ['context' => 'auth'])
    @endguest

    @include('partials._nav-spinner')
    @include('partials._confirm-modal')

    {{-- Feedback prompt used to be a floating pill at bottom-right;
         it now renders inline at the end of the main content flow
         above so operators can dismiss without a mid-air overlay. --}}

    {{-- Global Cmd+K / Ctrl+K command palette. Renders inside auth so
         only signed-in operators get it (guests on the auth surfaces
         don't need a navigator). --}}
    @auth('web')
        @include('partials._command-palette')
    @endauth

    @livewireScripts
</body>
</html>
