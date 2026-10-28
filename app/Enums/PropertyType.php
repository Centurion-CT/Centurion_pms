<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Property types per PRD §6.1.1 / §1.3 (target market segments).
 *
 * Maps the four landing-page operator categories onto the data model.
 * The {@see kind()} method collapses these into the three booking
 * primitives the platform supports — short-stay rooms, workspace
 * memberships, and residential leases — which drives module gating
 * in the sidebar + onboarding.
 */
enum PropertyType: string
{
    case ServicedApartment = 'serviced';
    case Hotel             = 'hotel';
    case ShortLet          = 'short_let';
    case LongStay          = 'long_stay';
    case Workspace         = 'workspace';
    case Residential       = 'residential';

    public function label(): string
    {
        return match ($this) {
            self::ServicedApartment => 'Serviced Apartment',
            self::Hotel             => 'Boutique Hotel',
            self::ShortLet          => 'Short-Let / Airbnb-style',
            self::LongStay          => 'Long-Stay Rental',
            self::Workspace         => 'Co-working & Workspace',
            self::Residential       => 'Residential Leases',
        };
    }

    /**
     * Booking primitive this property type uses. Drives which inventory
     * modules show up in the sidebar.
     *
     * - "stay": rooms / categories / nightly bookings (the original PMS)
     * - "workspace": desks / meeting rooms / monthly memberships
     * - "residential": units / annual leases / scheduled rent invoices
     */
    public function kind(): string
    {
        return match ($this) {
            self::ServicedApartment, self::Hotel, self::ShortLet, self::LongStay => 'stay',
            self::Workspace => 'workspace',
            self::Residential => 'residential',
        };
    }

    /**
     * Module keys the property *type* exposes. Independent of role —
     * the sidebar filter intersects this with the operator's role
     * modules so e.g. a Workspace-type property never shows the Rooms
     * tab even for managers.
     *
     * The "always" bucket covers modules every type uses (dashboard,
     * customers, branding, billing, etc.). The kind-specific bucket
     * layers on top.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        $always = [
            'dashboard', 'reports',
            'customers', 'corporates', 'discounts',
            'branding', 'pages', 'reviews', 'email_templates',
            'summary',
            'store',
            // Housekeeping supply room. Every property type has soaps and
            // towels to manage (hotels have amenity kits, workspaces
            // stock loo rolls + coffee), so this stays universal.
            'supplies',
            // Ancillary services (gym / spa / snooker / pool / any recurring
            // subscription the operator sells alongside the primary offer)
            // apply to every property type, hotels + apartments + workspaces
            // + residential blocks all have front-desk staff who might run one.
            'membership',
            'finance', 'payments', 'refunds', 'integrations', 'inventory', 'pos', 'subscription', 'domain',
            'staff', 'settings', 'settings_profile', 'notifications',
            'accounting',
            'overview',
        ];

        $kind = match ($this->kind()) {
            'stay' => [
                'reservations', 'in_house', 'bookings', 'calendar',
                'categories', 'rooms', 'housekeeping', 'complimentary',
                'credit_lines',
            ],
            'workspace' => [
                'workspaces', 'workspace_plans', 'memberships', 'desk_bookings',
            ],
            'residential' => [
                'units', 'leases', 'rent_schedule',
            ],
        };

        return array_values(array_merge($always, $kind));
    }

    public function exposesModule(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }
}
