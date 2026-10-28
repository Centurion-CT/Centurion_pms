<?php

declare(strict_types=1);

namespace App\Enums;

use App\Http\Middleware\EnsureRouteForRole;

/**
 * Roles a property operator can hold inside one tenant.
 *
 * Authorisation model
 * -------------------
 * Each role maps to a fixed list of "modules" (sidebar entries +
 * URL prefixes) via {@see modules()}. Sidebar rendering filters by
 * this list and the {@see EnsureRouteForRole}
 * middleware enforces the same boundary server-side so direct URL
 * access can't bypass the UI.
 *
 * Module key conventions
 * ----------------------
 * Each module key corresponds to a sidebar item and a route prefix
 * group. New routes pick up authz simply by being prefixed under
 * one of these module names; new modules need both an entry in
 * {@see modules()} and a `module` field on the sidebar nav item.
 */
enum OperatorRole: string
{
    case Manager = 'manager';
    case ReservationOfficer = 'reservation_officer';
    case Frontdesk = 'frontdesk';
    case HouseCleaner = 'house_cleaner';
    case Porter = 'porter';
    case Supervisor = 'supervisor';
    case PosStaff = 'pos_staff';
    case InventoryClerk = 'inventory_clerk';
    case StockKeeper = 'stock_keeper';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::ReservationOfficer => 'Reservation officer',
            self::Frontdesk => 'Front desk',
            self::HouseCleaner => 'House cleaner',
            self::Porter => 'Porter',
            self::Supervisor => 'Housekeeping supervisor',
            self::PosStaff => 'POS staff',
            self::InventoryClerk => 'Inventory clerk',
            self::StockKeeper => 'Stock keeper',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manager => 'Owner-level access — every module, including settings, staff, payments and subscription.',
            self::ReservationOfficer => 'Books and manages reservations end-to-end. No inventory, branding, finance or staff admin.',
            self::Frontdesk => 'Day-of operations — check-ins, check-outs, housekeeping coordination.',
            self::HouseCleaner => 'Housekeeping queue only.',
            self::Porter => 'Verifies room readiness after housekeeping. Second signer in the check-in gate chain.',
            self::Supervisor => 'Final sign-off on room readiness. Third signer — check-in unlocks after their approval.',
            self::PosStaff => 'Runs POS terminals for outlets (bar, kitchen, laundry, etc.) they\'re assigned to. No inventory management.',
            self::InventoryClerk => 'Maintains products and receives stock — but can\'t change prices, costs, tax rates, or create new products / outlets.',
            self::StockKeeper => 'Runs the central store — receives supplier deliveries and issues stock to outlets on requisition. No price control, no POS, no finance.',
        };
    }

    /**
     * Module keys this role can see + open. Used by the sidebar
     * filter and the middleware. "settings_profile" is special — it
     * stays available to every operator because they need to be able
     * to change their own password regardless of role.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        return match ($this) {
            self::Manager => [
                'dashboard', 'reservations', 'in_house', 'bookings', 'calendar', 'reports',
                'categories', 'rooms', 'housekeeping', 'complimentary', 'credit_lines',
                // Ancillary services (gym / spa / snooker / pool subscriptions).
                'membership',
                // Workspaces (Co-working segment).
                'workspaces', 'workspace_plans', 'memberships', 'desk_bookings',
                // Leases (Residential segment).
                'units', 'leases', 'rent_schedule',
                'customers',
                // Read-only view of which Keverna Corporates use this property.
                'corporates',
                'discounts', 'packages',
                'branding', 'pages', 'reviews', 'email_templates',
                'summary',
                // Central store + variance report (two-tier stock model).
                'store', 'reconciliation',
                // Housekeeping supply room — soaps, towels, bedsheets. Scoped
                // read + request-from-store surface for front desk / cleaners
                // that stops short of full inventory admin.
                'supplies',
                'finance', 'payments', 'refunds', 'integrations', 'inventory', 'pos', 'subscription', 'domain', 'staff', 'settings', 'settings_profile',
                'notifications',
                // Accounting module (expenses, future revenue/P&L).
                'accounting',
                // All branches page: money + activity across every branch.
                'overview',
            ],
            self::ReservationOfficer => [
                'dashboard', 'reservations', 'in_house', 'bookings', 'calendar', 'reports',
                'complimentary', 'credit_lines', 'refunds',
                // Reservation officers handle workspace/lease customer-facing
                // ops too — books desks, signs leases, takes rent.
                'memberships', 'desk_bookings',
                'leases', 'rent_schedule',
                'membership',
                'customers', 'corporates',
                'discounts', 'packages',
                'notifications',
                'settings_profile',
            ],
            self::Frontdesk => [
                'dashboard', 'reservations', 'in_house', 'calendar',
                'desk_bookings', 'memberships',
                'membership',
                'customers',
                'housekeeping', 'complimentary', 'credit_lines',
                'refunds',
                // Read the housekeeping supply state — "amenities are low,
                // don't oversell". Can view + request from store; can't
                // edit products or receive stock.
                'supplies',
                'notifications',
                'settings_profile',
            ],
            self::HouseCleaner => [
                'housekeeping',
                // See what's on hand before a turnover + raise a request
                // to the store keeper when the trolley is empty.
                'supplies',
                'notifications',
                'settings_profile',
            ],
            self::Porter => [
                'housekeeping',
                'supplies',
                'notifications',
                'settings_profile',
            ],
            self::Supervisor => [
                'dashboard',
                'housekeeping',
                'in_house',
                'supplies',
                'notifications',
                'settings_profile',
            ],
            self::PosStaff => [
                'pos',            // POS terminal access — outlet assignment further scopes what they see
                'complimentary',  // Kitchen POS staff need the breakfast pull for the morning
                'notifications',
                'settings_profile',
            ],
            self::InventoryClerk => [
                'dashboard',
                'inventory',      // Read + edit products, receive stock — price fields are gated further via canManagePrices()
                'supplies',       // Housekeeping outlets are inventory outlets, so the clerk always sees them
                'notifications',
                'settings_profile',
            ],
            self::StockKeeper => [
                'dashboard',
                'store',          // Central store — receive, catalog, fulfil requisitions, adjust store, store history
                'inventory',      // Read-only into outlets so the keeper knows who ordered what
                'supplies',       // Same reason as inventory — housekeeping requests land in the keeper's inbox
                'notifications',
                'settings_profile',
            ],
        };
    }

    public function canAccessModule(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /**
     * Field-level gate for money-related inventory fields — sell price,
     * cost / COGS, outlet tax rate — and for creating new products /
     * outlets (which necessarily involves setting an initial price).
     *
     * Enforced two ways in the UI: Blade renders price/cost/tax fields
     * as read-only when this returns false, and the corresponding
     * Livewire save() methods strip the fields from the update payload
     * before persisting. The `role.prices` middleware wraps the two
     * "create" routes as belt-and-braces against deep-linking.
     */
    public function canManagePrices(): bool
    {
        return $this === self::Manager;
    }

    /**
     * Where to send a role after login (and after any access-denied
     * redirect). Managers go to the home dashboard; narrower roles
     * head straight to their primary work surface.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Manager => 'tenant.dashboard',
            self::ReservationOfficer => 'tenant.reservations.index',
            self::Frontdesk => 'tenant.reservations.index',
            self::HouseCleaner => 'tenant.housekeeping.index',
            self::Porter => 'tenant.housekeeping.index',
            self::Supervisor => 'tenant.housekeeping.index',
            self::PosStaff => 'tenant.pos.pick-outlet',
            self::InventoryClerk => 'tenant.inventory.index',
            self::StockKeeper => 'tenant.store.index',
        };
    }
}
