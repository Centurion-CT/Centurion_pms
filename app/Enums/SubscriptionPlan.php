<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Subscription plans.
 *
 * v1 launch shipped `Trial` + a single flat `Annual` (₦300k/yr). The
 * FregateLab Q1-Q2 memo shifted new registrations to a tiered model
 * (Starter / Growth / Pro / Enterprise). The legacy `Annual` case is
 * kept for grandfathered subscribers already on the flat plan; new
 * registrations only ever land on one of the four tiers.
 *
 * Tier boundaries come from the Keverna-Pricing memo:
 *   Starter    1–5 units    ₦180,000/yr
 *   Growth     6–20 units   ₦420,000/yr
 *   Pro        21–50 units  ₦900,000/yr
 *   Enterprise 50+ units    from ₦1,800,000/yr (custom)
 */
enum SubscriptionPlan: string
{
    case Trial = 'trial';

    /** Legacy flat plan — kept for grandfathered subscribers only. */
    case Annual = 'annual_300k';

    case Starter = 'starter';
    case Growth = 'growth';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    public function amountNgn(): int
    {
        return match ($this) {
            self::Trial => 0,
            self::Annual => (int) config('keverna.pricing.subscription_annual_ngn'),
            self::Starter => 180_000,
            self::Growth => 420_000,
            self::Pro => 900_000,
            // Enterprise is a floor — real price is negotiated per-account.
            // The floor here is what the billing system will attempt to
            // charge if no override is set; sales is expected to attach
            // a custom amount to the tenant's Subscription before renewal.
            self::Enterprise => 1_800_000,
        };
    }

    public function periodDays(): int
    {
        return match ($this) {
            self::Trial => (int) config('keverna.pricing.trial_days'),
            default => 365,
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Annual => 'Annual (legacy)',
            self::Starter => 'Starter',
            self::Growth => 'Growth',
            self::Pro => 'Pro',
            self::Enterprise => 'Enterprise',
        };
    }

    /**
     * Inclusive unit range covered by the tier. Enterprise has no upper
     * bound (null) — sales handles the boundary case-by-case.
     *
     * @return array{int, ?int}
     */
    public function unitRange(): array
    {
        return match ($this) {
            self::Starter => [1, 5],
            self::Growth => [6, 20],
            self::Pro => [21, 50],
            self::Enterprise => [51, null],
            // Trial + legacy Annual don't have unit ranges — they exist
            // outside the tier system. Return [1, null] as a benign
            // default so callers don't have to null-check.
            default => [1, null],
        };
    }

    /**
     * Deterministic tier mapping used by the self-serve register flow.
     * A unit_count of 51+ resolves to Enterprise, which the caller
     * should redirect to sales rather than sign up automatically.
     */
    public static function fromUnitCount(int $unitCount): self
    {
        return match (true) {
            $unitCount <= 5 => self::Starter,
            $unitCount <= 20 => self::Growth,
            $unitCount <= 50 => self::Pro,
            default => self::Enterprise,
        };
    }

    /** True for the four new self-serve tiers; false for Trial + legacy Annual. */
    public function isTier(): bool
    {
        return match ($this) {
            self::Starter, self::Growth, self::Pro, self::Enterprise => true,
            default => false,
        };
    }

    /** Whether a tier is self-serve at registration (Enterprise routes to sales). */
    public function isSelfServe(): bool
    {
        return match ($this) {
            self::Starter, self::Growth, self::Pro => true,
            default => false,
        };
    }
}
