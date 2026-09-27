<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\PosSaleStatus;
use App\Enums\StockMovementReason;
use App\Models\Activity;
use App\Models\Operator;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * "Watch-outs" for the Summary page — the cheapest leak signals we can
 * compute from what's already in the database:
 *
 *  - Voided sales (count + total ₦) — a sale that was rung up then
 *    reversed. Legit for a mis-punch, suspect when a single cashier
 *    accounts for most of them.
 *  - Wasted stock (units + cost ₦) — StockMovement with reason=Wasted.
 *    Kitchen spillage, expired stock, etc.
 *  - Manual stock adjustments (count) — reason=Adjusted, i.e. someone
 *    edited quantity_on_hand outside a receive/sale flow.
 *  - Downward price edits (count) — an operator lowered a product's
 *    sell price. Not always bad, but worth surfacing.
 *
 * All queries respect tenant scoping (Activity has a global scope;
 * StockMovement + PosSale use BelongsToTenant).
 */
class SummaryWatchouts
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        Carbon $from,
        Carbon $to,
        ?int $outletId = null,
        ?int $operatorId = null,
    ): array {
        return [
            'voidedSales' => $this->voidedSales($from, $to, $outletId, $operatorId),
            'wastedStock' => $this->wastedStock($from, $to, $outletId, $operatorId),
            'stockAdjustments' => $this->stockAdjustments($from, $to, $outletId, $operatorId),
            'priceCuts' => $this->priceCuts($from, $to, $outletId, $operatorId),
        ];
    }

    /**
     * @return array{count: int, amount: int}
     */
    private function voidedSales(Carbon $from, Carbon $to, ?int $outletId, ?int $operatorId): array
    {
        $query = PosSale::query()
            ->where('status', PosSaleStatus::Voided->value)
            ->whereBetween('voided_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }
        if ($operatorId !== null) {
            $query->where('operator_id', $operatorId);
        }

        return [
            'count' => (int) (clone $query)->count(),
            'amount' => (int) (clone $query)->sum('total'),
        ];
    }

    /**
     * Wasted units + rough ₦ cost, using the current product cost as a
     * proxy (we don't snapshot cost at movement time — matches how the
     * inventory KPIs compute margin).
     *
     * @return array{units: int, cost: int}
     */
    private function wastedStock(Carbon $from, Carbon $to, ?int $outletId, ?int $operatorId): array
    {
        $query = StockMovement::query()
            ->where('reason', StockMovementReason::Wasted->value)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($outletId !== null) {
            $query->whereHas('product', fn ($q) => $q->where('outlet_id', $outletId));
        }
        if ($operatorId !== null) {
            $query->where('operator_id', $operatorId);
        }

        // delta is negative for waste — flip sign so "units" is positive.
        $rows = $query->with('product:id,cost')->get(['id', 'delta', 'product_id', 'operator_id']);
        $units = 0;
        $cost = 0;
        foreach ($rows as $m) {
            $qty = abs((int) $m->delta);
            $units += $qty;
            $cost += $qty * (int) ($m->product->cost ?? 0);
        }

        return ['units' => $units, 'cost' => $cost];
    }

    private function stockAdjustments(Carbon $from, Carbon $to, ?int $outletId, ?int $operatorId): int
    {
        $query = StockMovement::query()
            ->where('reason', StockMovementReason::Adjusted->value)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($outletId !== null) {
            $query->whereHas('product', fn ($q) => $q->where('outlet_id', $outletId));
        }
        if ($operatorId !== null) {
            $query->where('operator_id', $operatorId);
        }

        return (int) $query->count();
    }

    /**
     * Downward price edits — Activity rows on Product where the new
     * price is lower than the old one. Uses the JSON `properties`
     * payload Spatie stores; we filter in PHP because SQLite (used in
     * tests) doesn't have first-class JSON path support.
     */
    private function priceCuts(Carbon $from, Carbon $to, ?int $outletId, ?int $operatorId): int
    {
        $query = Activity::query()
            ->where('subject_type', Product::class)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        if ($operatorId !== null) {
            $query->where('causer_type', Operator::class)
                ->where('causer_id', $operatorId);
        }
        if ($outletId !== null) {
            $productIds = Product::query()->where('outlet_id', $outletId)->pluck('id');
            $query->whereIn('subject_id', $productIds);
        }

        $count = 0;
        foreach ($query->get(['id', 'properties']) as $a) {
            $props = $a->properties ?? collect();
            $newAttrs = (array) ($props['attributes'] ?? []);
            $oldAttrs = (array) ($props['old'] ?? []);
            if (! array_key_exists('price', $newAttrs) || ! array_key_exists('price', $oldAttrs)) {
                continue;
            }
            if ((int) $newAttrs['price'] < (int) $oldAttrs['price']) {
                $count++;
            }
        }

        return $count;
    }
}
