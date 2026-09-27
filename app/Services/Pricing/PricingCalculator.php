<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\DepositType;
use App\Enums\RoomBookingType;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Computes a full PricingBreakdown for a hypothetical booking
 * (PRD §6.4.2 / §4.1).
 *
 * Pricing modes:
 *  - Long-stay: when the room's booking_type allows long-stay AND
 *    monthly_rate is set AND nights >= 28, we bill by month
 *    (ceil(nights/30) × monthly_rate). Auto weekly/monthly discounts do
 *    NOT stack on top — monthly pricing already absorbs the discount.
 *  - Short-let: per-night sum, with seasonal RoomPrice overrides applied
 *    on a date-by-date basis. nights >= 28 applies the room's
 *    monthly_discount_percent; nights >= 7 applies weekly_discount_percent.
 *
 * Fee math is integer NGN throughout. `intdiv` + manual rounding avoid
 * surprises that a naïve cast from float would introduce on amounts that
 * push 8 digits.
 */
class PricingCalculator
{
    public function calculate(
        Room $room,
        Carbon $checkin,
        Carbon $checkout,
        int $packagesTotal = 0,
        bool $breakfastIncluded = false,
        ?\Illuminate\Http\Request $marketplaceRequest = null,
    ): PricingBreakdown {
        $checkin = $checkin->copy()->startOfDay();
        $checkout = $checkout->copy()->startOfDay();

        $nights = (int) $checkin->diffInDays($checkout, absolute: true);
        if ($nights < 1) {
            throw new InvalidArgumentException('checkout must be at least one day after checkin');
        }

        $longStayMode = $this->shouldUseLongStayPricing($room, $nights);

        // Effective base rate — swap to the category's breakfast tier
        // when the guest opted in AND the tier exists. Silently no-op
        // when the category doesn't offer breakfast, so old callers
        // that never pass the flag see identical behaviour.
        $baseRate = $room->category?->effectiveNightlyRate($breakfastIncluded)
            ?? (int) $room->base_rate;

        if ($longStayMode) {
            [$nightlyRates, $subtotal] = $this->longStaySubtotal($room, $checkin, $nights);
            $discountPercent = 0;
        } else {
            [$nightlyRates, $subtotal] = $this->nightlySubtotal($room, $checkin, $nights, $baseRate, $marketplaceRequest);
            $discountPercent = $this->autoDiscountPercent($room, $nights);
        }

        $discountAmount = $this->percentOf($subtotal, $discountPercent);
        // Packages are added on top of the room subtotal (after the
        // auto night-length discount, before manual discount + fees) so
        // the per-night discount only ever applies to the room cost,
        // not to the add-ons.
        $packagesTotal = max(0, $packagesTotal);
        $netSubtotal = $subtotal - $discountAmount + $packagesTotal;

        $depositAmount = $this->deposit($room, $netSubtotal);
        $paystackFee = $this->paystackFee($netSubtotal);
        $kevernaFee = $this->kevernaFee($netSubtotal);
        $totalDue = $netSubtotal + $paystackFee + $kevernaFee;

        return new PricingBreakdown(
            nights: $nights,
            nightlyRates: $nightlyRates,
            subtotal: $subtotal,
            discountPercent: $discountPercent,
            discountAmount: $discountAmount,
            netSubtotal: $netSubtotal,
            depositAmount: $depositAmount,
            paystackFee: $paystackFee,
            kevernaFee: $kevernaFee,
            totalDue: $totalDue,
            operatorPayout: $netSubtotal,
            longStayPricing: $longStayMode,
            packagesTotal: $packagesTotal,
        );
    }

    private function shouldUseLongStayPricing(Room $room, int $nights): bool
    {
        if ($nights < 28) {
            return false;
        }
        if ($room->monthly_rate === null) {
            return false;
        }

        return $room->booking_type === RoomBookingType::LongStay;
    }

    /**
     * @return array{0: list<NightlyRate>, 1: int}
     */
    private function nightlySubtotal(
        Room $room,
        Carbon $checkin,
        int $nights,
        ?int $baseRate = null,
        ?\Illuminate\Http\Request $marketplaceRequest = null,
    ): array {
        $overrides = $this->loadOverrides($room, $checkin, $nights);
        $effectiveBase = $baseRate ?? (int) $room->base_rate;

        // Marketplace RateRule resolver — only consulted for /stays
        // online bookings (a Request was threaded in). Front-desk
        // and OTA flows pass null so their pricing stays untouched.
        $resolver = $marketplaceRequest !== null && $room->category !== null
            ? app(\App\Services\Marketplace\RateResolver::class)
            : null;

        $rates = [];
        $subtotal = 0;

        for ($i = 0; $i < $nights; $i++) {
            $date = $checkin->copy()->addDays($i);
            $override = $this->overrideFor($overrides, $date);

            if ($override !== null) {
                $rates[] = new NightlyRate($date, $override->rate, 'override', $override->label);
                $subtotal += $override->rate;
                continue;
            }

            // RateRule fallback: per-night lookup against the current
            // request context. Rule beats base when it exists.
            if ($resolver !== null) {
                $ruleInfo = $resolver->resolve($room->category, $marketplaceRequest, $date);
                if ($ruleInfo['rule'] !== null) {
                    $rates[] = new NightlyRate($date, (int) $ruleInfo['rate'], 'rate_rule', $ruleInfo['rule']->label);
                    $subtotal += (int) $ruleInfo['rate'];
                    continue;
                }
            }

            $rates[] = new NightlyRate($date, $effectiveBase, 'base');
            $subtotal += $effectiveBase;
        }

        return [$rates, $subtotal];
    }

    /**
     * @return array{0: list<NightlyRate>, 1: int}
     */
    private function longStaySubtotal(Room $room, Carbon $checkin, int $nights): array
    {
        /** @var int $monthlyRate non-null is guaranteed by caller */
        $monthlyRate = (int) $room->monthly_rate;
        $months = (int) ceil($nights / 30);
        $subtotal = $monthlyRate * $months;

        // For UI breakdown we still emit one NightlyRate per night so the
        // template can render a per-day calendar. Spreading the monthly cost
        // evenly across the period gives an honest "effective nightly rate".
        $effective = intdiv($subtotal, $nights);
        $rates = [];
        for ($i = 0; $i < $nights; $i++) {
            $rates[] = new NightlyRate($checkin->copy()->addDays($i), $effective, 'base');
        }

        return [$rates, $subtotal];
    }

    /**
     * @return Collection<int, RoomPrice>
     */
    private function loadOverrides(Room $room, Carbon $checkin, int $nights): Collection
    {
        $checkoutExclusive = $checkin->copy()->addDays($nights - 1);

        return RoomPrice::query()
            ->withoutGlobalScope(TenantScope::class)   // calculator runs in central + tenant contexts
            ->where('room_id', $room->id)
            ->overlapping($checkin, $checkoutExclusive)
            ->orderByDesc('id')  // most-recently-created override wins on conflict
            ->get();
    }

    /**
     * @param  Collection<int, RoomPrice>  $overrides
     */
    private function overrideFor(Collection $overrides, Carbon $date): ?RoomPrice
    {
        foreach ($overrides as $o) {
            if ($date->betweenIncluded($o->start_date, $o->end_date)) {
                return $o;
            }
        }

        return null;
    }

    private function autoDiscountPercent(Room $room, int $nights): int
    {
        if ($nights >= 28) {
            return (int) $room->monthly_discount_percent;
        }
        if ($nights >= 7) {
            return (int) $room->weekly_discount_percent;
        }

        return 0;
    }

    private function deposit(Room $room, int $netSubtotal): int
    {
        return match ($room->deposit_type) {
            DepositType::None => 0,
            DepositType::Fixed => (int) $room->deposit_value,
            DepositType::Percent => $this->percentOf($netSubtotal, (int) $room->deposit_value),
        };
    }

    /**
     * Paystack card fee per PRD §4.1: 1.5% × subtotal + ₦100, capped at ₦2,000.
     */
    private function paystackFee(int $subtotal): int
    {
        $percent = (float) config('keverna.pricing.paystack_fee_percent');
        $flat = (int) config('keverna.pricing.paystack_fee_flat_ngn');
        $cap = (int) config('keverna.pricing.paystack_fee_cap_ngn');

        $fee = (int) round($subtotal * $percent / 100) + $flat;

        return min($fee, $cap);
    }

    /**
     * Keverna settlement fee per PRD §4.1: 0.5% × subtotal, capped at ₦2,000.
     */
    private function kevernaFee(int $subtotal): int
    {
        $percent = (float) config('keverna.pricing.keverna_fee_percent');
        $cap = (int) config('keverna.pricing.keverna_fee_cap_ngn');

        $fee = (int) round($subtotal * $percent / 100);

        return min($fee, $cap);
    }

    private function percentOf(int $amount, int $percent): int
    {
        if ($percent <= 0 || $amount <= 0) {
            return 0;
        }

        return (int) round($amount * $percent / 100);
    }
}
