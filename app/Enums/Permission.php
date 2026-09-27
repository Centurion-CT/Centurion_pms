<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grantable permissions layered ON TOP of {@see OperatorRole}.
 *
 * Roles are the primary access mechanism (see canAccessModule on the
 * enum); permissions add extra capabilities to individual operators
 * without changing their role. Grants are additive — nothing here
 * ever *removes* what a role permits. Storage: `operators.permissions`
 * JSON column (list of `value` strings).
 *
 * Two kinds of grants:
 *   - Module unlocks. `module()` returns a module key. Granting the
 *     permission adds that module to the operator's canAccessModule
 *     set (and, by extension, the sidebar nav + role middleware).
 *   - Capability grants. `module()` returns null; the code that owns
 *     the capability checks `$operator->hasPermission(...)` at the
 *     point of decision (e.g. price editing, refund approval).
 */
enum Permission: string
{
    case FinanceView       = 'finance.view';
    case StaffManage       = 'staff.manage';
    case SettingsManage    = 'settings.manage';
    case DiscountsManage   = 'discounts.manage';
    case ReportsAccess     = 'reports.access';
    case PricesManage      = 'prices.manage';

    public function label(): string
    {
        return match ($this) {
            self::FinanceView     => 'View finance',
            self::StaffManage     => 'Manage staff',
            self::SettingsManage  => 'Manage settings',
            self::DiscountsManage => 'Manage discounts',
            self::ReportsAccess   => 'Access reports',
            self::PricesManage    => 'Manage prices',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FinanceView     => 'See the Finance dashboard, revenue, cashflow, and payouts.',
            self::StaffManage     => 'See the Staff page, invite new operators, change roles.',
            self::SettingsManage  => 'Edit property, branding, and workspace settings.',
            self::DiscountsManage => 'Create and edit discount codes + auto-apply rules.',
            self::ReportsAccess   => 'See reports across the tenant.',
            self::PricesManage    => 'Edit room rates, product prices, and outlet tax rates.',
        };
    }

    /**
     * The module key this permission unlocks in canAccessModule, or
     * null for capability grants (checked directly via hasPermission).
     */
    public function module(): ?string
    {
        return match ($this) {
            self::FinanceView     => 'finance',
            self::StaffManage     => 'staff',
            self::SettingsManage  => 'settings',
            self::DiscountsManage => 'discounts',
            self::ReportsAccess   => 'reports',
            self::PricesManage    => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
