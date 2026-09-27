<?php

declare(strict_types=1);

namespace App\Services\Corporates;

use App\Enums\CorporateRateScope;
use App\Models\Corporate;
use App\Models\CorporateRate;
use App\Models\Room;
use App\Models\Unit;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Resolves the most-specific currently-active corporate rate that
 * applies to a given booking target.
 *
 * Specificity order (highest first):
 *   1. Workspace / Unit / RoomCategory  (the bookable itself)
 *   2. Property (the host property)
 *   3. Global (any property)
 *
 * If two rates compete at the same scope, the *better* one for the
 * guest wins — measured by the discount produced against a sample
 * unit price. That keeps Keverna's contractual obligation simple:
 * the most generous rate the corporate is entitled to.
 */
class CorporatePricingResolver
{
    /**
     * Find the best rate for a stay-type booking on a Room.
     *
     * $nights lets the resolver honour length-of-stay-gated rates
     * (rate.min_nights). CorporateBookingService passes the booking's
     * nights through so a "10% off if the stay is 3 nights or more"
     * tier only kicks in for eligible stays. Default 1 keeps callers
     * that don't yet know the stay length working (no LoS filter).
     *
     * @return array{rate: CorporateRate, unit_price: int, off_per_unit: int}|null
     *   `unit_price` is the post-rate per-night price; `off_per_unit`
     *   is the saving per night vs the public price.
     */
    public function forRoom(Corporate $corporate, Room $room, int $nights = 1): ?array
    {
        $publicPrice = (int) $room->base_rate;
        if ($publicPrice <= 0) {
            return null;
        }

        $candidates = $this->candidates($corporate, [
            ['scope' => CorporateRateScope::RoomCategory->value, 'scope_id' => $room->room_category_id],
            ['scope' => CorporateRateScope::Property->value,     'scope_id' => $room->property_id],
            ['scope' => CorporateRateScope::Global->value,       'scope_id' => null],
        ]);

        return $this->pickBest($candidates, $publicPrice, $nights);
    }

    /**
     * Find the best rate for a Workspace booking (hourly/day pass).
     *
     * @return array{rate: CorporateRate, unit_price: int, off_per_unit: int}|null
     */
    public function forWorkspace(Corporate $corporate, Workspace $workspace, int $publicPrice): ?array
    {
        if ($publicPrice <= 0) {
            return null;
        }

        $candidates = $this->candidates($corporate, [
            ['scope' => CorporateRateScope::Workspace->value, 'scope_id' => $workspace->id],
            ['scope' => CorporateRateScope::Property->value,  'scope_id' => $workspace->property_id],
            ['scope' => CorporateRateScope::Global->value,    'scope_id' => null],
        ]);

        return $this->pickBest($candidates, $publicPrice);
    }

    /**
     * Find the best rate for a Unit lease.
     *
     * @return array{rate: CorporateRate, unit_price: int, off_per_unit: int}|null
     */
    public function forUnit(Corporate $corporate, Unit $unit): ?array
    {
        $publicPrice = (int) $unit->annual_rent_naira;
        if ($publicPrice <= 0) {
            return null;
        }

        $candidates = $this->candidates($corporate, [
            ['scope' => CorporateRateScope::Unit->value,     'scope_id' => $unit->id],
            ['scope' => CorporateRateScope::Property->value, 'scope_id' => $unit->property_id],
            ['scope' => CorporateRateScope::Global->value,   'scope_id' => null],
        ]);

        return $this->pickBest($candidates, $publicPrice);
    }

    /**
     * Pull every active rate matching any of the scope+id tuples.
     *
     * @param  list<array{scope:string, scope_id:int|null}>  $tuples
     * @return \Illuminate\Support\Collection<int, CorporateRate>
     */
    private function candidates(Corporate $corporate, array $tuples)
    {
        $query = CorporateRate::query()
            ->where('corporate_id', $corporate->id)
            ->activeAt(Carbon::now())
            ->where(function ($q) use ($tuples): void {
                foreach ($tuples as $t) {
                    $q->orWhere(function ($qq) use ($t): void {
                        $qq->where('scope', $t['scope']);
                        if ($t['scope_id'] === null) {
                            $qq->whereNull('scope_id');
                        } else {
                            $qq->where('scope_id', $t['scope_id']);
                        }
                    });
                }
            });

        return $query->get();
    }

    /**
     * From a candidate set, pick the rate that produces the lowest
     * unit price for the guest. Ties broken by scope specificity
     * (unit > workspace > category > property > global).
     *
     * $nights gates length-of-stay-conditional rates (rate.min_nights).
     * A rate whose min_nights exceeds the stay's nights is simply
     * skipped — as if it didn't exist in the candidate set.
     *
     * @param  \Illuminate\Support\Collection<int, CorporateRate>  $candidates
     * @return array{rate: CorporateRate, unit_price: int, off_per_unit: int}|null
     */
    private function pickBest($candidates, int $publicPrice, int $nights = 1): ?array
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $rank = [
            CorporateRateScope::Unit->value         => 5,
            CorporateRateScope::Workspace->value    => 4,
            CorporateRateScope::RoomCategory->value => 3,
            CorporateRateScope::Property->value     => 2,
            CorporateRateScope::Global->value       => 1,
        ];

        $best = null;
        $bestPrice = $publicPrice;
        $bestRank = -1;

        foreach ($candidates as $rate) {
            // Length-of-stay gate: an LoS-conditional rate only counts
            // when the stay is long enough. Rates with a null
            // min_nights are unconditional and always considered.
            $minNights = $rate->min_nights;
            if ($minNights !== null && $minNights > $nights) {
                continue;
            }

            $candidatePrice = $rate->rate_type->apply($publicPrice, (int) $rate->rate_value);
            $candidateRank = $rank[$rate->scope->value] ?? 0;

            // Prefer the lower price; on tie, the more specific scope.
            if ($candidatePrice < $bestPrice
                || ($candidatePrice === $bestPrice && $candidateRank > $bestRank)) {
                $best = $rate;
                $bestPrice = $candidatePrice;
                $bestRank = $candidateRank;
            }
        }

        if ($best === null || $bestPrice >= $publicPrice) {
            return null;
        }

        return [
            'rate'         => $best,
            'unit_price'   => $bestPrice,
            'off_per_unit' => $publicPrice - $bestPrice,
        ];
    }
}
