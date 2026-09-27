<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a customer sits in the relationship arc.
 *
 * Transitions are driven by CustomerLinker:
 *   - Prospect → Active        on first confirmed booking
 *   - Active   → Dormant       180 days after last_booking_at
 *   - Dormant  → Churned       365 days after last_booking_at
 *   - Any      → Active        on any new confirmed booking
 *
 * Operators can override the bucket manually from the CRM UI; the
 * automatic recompute respects any operator-pinned lifecycle that
 * was set within the last 30 days.
 */
enum CustomerLifecycle: string
{
    case Prospect = 'prospect';   // CRM record exists, no completed stay yet
    case Active   = 'active';     // Has stayed within the last 180 days
    case Dormant  = 'dormant';    // 180–365 days since last stay
    case Churned  = 'churned';    // > 365 days, unlikely to return without outreach

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospect',
            self::Active   => 'Active',
            self::Dormant  => 'Dormant',
            self::Churned  => 'Churned',
        };
    }
}
