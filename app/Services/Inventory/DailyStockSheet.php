<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily stock sheet — one row per product for a given date,
 * scoped to either an outlet or the store.
 *
 * Everything on the sheet is derived from stock_movements + Product +
 * physical_counts. The only user-entered per-day data is
 * physical_qty + remark on the physical_counts row. Reads three
 * grouped queries (opening, day-sums, counts) so a 200-product bar
 * renders in a couple of SELECTs regardless of how busy the day was.
 *
 * Column semantics
 * ----------------
 *  opening         = quantity_after of the last StockMovement strictly
 *                    before the start of the date, else 0.
 *  supply_in       = sum of positive deltas on the date (Received at
 *                    the store, Issued at the outlet, adjust-up).
 *  consumption     = sum of |negative deltas| on the date (Sold at the
 *                    outlet, Issued at the store, wasted, adjust-down).
 *  total           = opening + supply_in.
 *  price           = current sell price of the outlet product (₦ kobo).
 *  total_naira     = consumption × price. Represents revenue for
 *                    outlet sheets; represents outlet-side sale-value
 *                    of stock issued for store sheets.
 *  expected_balance= total - consumption.
 *  physical_qty    = user-entered count from physical_counts (null if
 *                    not yet counted).
 *  variance        = physical_qty - expected_balance (null if
 *                    physical_qty is null).
 *  remark          = physical_counts.remark if set, else auto-classified
 *                    from variance ("balanced" / "N flat" / "N over").
 */
class DailyStockSheet
{
    /**
     * @return Collection<int, array{
     *     sn:int, product_id:int, name:string, sku:?string,
     *     opening:int, supply_in:int, total:int,
     *     consumption:int, price:int, total_naira:int,
     *     expected_balance:int, physical_qty:?int, variance:?int,
     *     remark:?string, note:?string
     * }>
     */
    public function build(Carbon $date, ?int $outletId = null, ?int $storeId = null): Collection
    {
        if ($outletId === null && $storeId === null) {
            return collect();
        }

        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $products = Product::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return collect();
        }

        $productIds = $products->pluck('id')->all();

        $openingRows = $this->fetchOpenings($productIds, $startOfDay);
        $daySums = $this->fetchDaySums($productIds, $startOfDay, $endOfDay);
        $counts = $this->fetchCounts($productIds, $date);

        return $products->values()->map(function (Product $product, int $index) use ($openingRows, $daySums, $counts) {
            $opening = (int) ($openingRows[$product->id]->quantity_after ?? 0);
            $supplyIn = (int) ($daySums[$product->id]->supply_in ?? 0);
            $consumption = (int) ($daySums[$product->id]->consumption ?? 0);
            $total = $opening + $supplyIn;
            $expectedBalance = $total - $consumption;

            $count = $counts[$product->id] ?? null;
            $physical = $count?->physical_qty;
            $variance = $physical === null ? null : ($physical - $expectedBalance);
            $remark = $count?->remark ?? $this->autoRemark($variance);

            $price = (int) $product->price;

            return [
                'sn'               => $index + 1,
                'product_id'       => $product->id,
                'name'             => $product->name,
                'sku'              => $product->sku,
                'opening'          => $opening,
                'supply_in'        => $supplyIn,
                'total'            => $total,
                'consumption'      => $consumption,
                'price'            => $price,
                'total_naira'      => $consumption * $price,
                'expected_balance' => $expectedBalance,
                'physical_qty'     => $physical,
                'variance'         => $variance,
                'remark'           => $remark,
                'note'             => $count?->note,
            ];
        });
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, StockMovement>
     */
    private function fetchOpenings(array $productIds, Carbon $startOfDay): Collection
    {
        // Two-step: (1) pick the max id per product before the day,
        // (2) fetch those rows for quantity_after. Cheaper than a
        // window function on MySQL/SQLite.
        $lastIds = StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->where('occurred_at', '<', $startOfDay)
            ->select('product_id', DB::raw('MAX(id) as last_id'))
            ->groupBy('product_id')
            ->pluck('last_id');

        if ($lastIds->isEmpty()) {
            return collect();
        }

        return StockMovement::query()
            ->whereIn('id', $lastIds)
            ->get(['id', 'product_id', 'quantity_after'])
            ->keyBy('product_id');
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, object{supply_in:int, consumption:int}>
     */
    private function fetchDaySums(array $productIds, Carbon $startOfDay, Carbon $endOfDay): Collection
    {
        return StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->whereBetween('occurred_at', [$startOfDay, $endOfDay])
            ->select(
                'product_id',
                DB::raw('SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) as supply_in'),
                DB::raw('SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END) as consumption'),
            )
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');
    }

    /**
     * @param  list<int>  $productIds
     * @return Collection<int, PhysicalCount>
     */
    private function fetchCounts(array $productIds, Carbon $date): Collection
    {
        // whereDate() (not where()) — SQLite stores `date`-cast columns
        // with an implicit time component, so string comparison against
        // "YYYY-MM-DD" misses.
        return PhysicalCount::query()
            ->whereIn('product_id', $productIds)
            ->whereDate('count_date', $date)
            ->get()
            ->keyBy('product_id');
    }

    private function autoRemark(?int $variance): ?string
    {
        if ($variance === null) {
            return null;
        }
        if ($variance === 0) {
            return 'balanced';
        }
        if ($variance < 0) {
            return abs($variance).' flat';
        }

        return $variance.' over';
    }

    /**
     * Multi-day pivoted matrix — the outlet-book layout: rows are
     * dates, columns are products, each product has 5 sub-cells
     * (opening / new / total / sold / closing).
     *
     * `opening` is chained across days: opening[N] = closing[N-1] via
     * the ledger's quantity_after. Reads two grouped queries per day
     * bucket + one physical-count fetch across the whole window, so a
     * 30-day × 40-product matrix stays cheap.
     *
     * @return array{
     *     products: Collection<int, Product>,
     *     dates: list<Carbon>,
     *     cells: array<string, array<int, array{
     *         opening:int, new:int, total:int, sold:int,
     *         expected:int, physical:?int, closing:int,
     *         remark:?string, variance:?int,
     *     }>>,
     * }
     */
    public function buildMatrix(int $outletId, Carbon $from, Carbon $to): array
    {
        $products = Product::query()
            ->where('outlet_id', $outletId)
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();

        // Newest date first so today lands on top of the visible table.
        $dates = [];
        $cursor = $to->copy()->startOfDay();
        $floor = $from->copy()->startOfDay();
        while ($cursor->greaterThanOrEqualTo($floor)) {
            $dates[] = $cursor->copy();
            $cursor->subDay();
        }

        if ($products->isEmpty() || $dates === []) {
            return ['products' => $products, 'dates' => $dates, 'cells' => []];
        }

        $productIds = $products->pluck('id')->all();
        $windowStart = $from->copy()->startOfDay();
        $windowEnd = $to->copy()->endOfDay();

        // Openings at the start of the window (baseline for chaining).
        $baseline = $this->fetchOpenings($productIds, $windowStart);

        // Per-day sums across the whole window in one query, keyed
        // (date, product) so we don't run one query per day.
        $daySums = $this->fetchWindowSumsByDay($productIds, $windowStart, $windowEnd);

        // Physical counts across the window.
        $counts = $this->fetchCountsInWindow($productIds, $windowStart, $windowEnd);

        // Ascending walk so opening[N] = closing[N-1] chains correctly,
        // then we key by ISO date for the view to pick out.
        $running = [];
        foreach ($productIds as $pid) {
            $running[$pid] = (int) ($baseline[$pid]->quantity_after ?? 0);
        }

        $cells = [];
        foreach (array_reverse($dates) as $day) {
            $dateKey = $day->toDateString();
            $cells[$dateKey] = [];
            foreach ($productIds as $pid) {
                $opening = $running[$pid];
                $sums = $daySums[$dateKey][$pid] ?? null;
                $supplyIn = (int) ($sums['supply_in'] ?? 0);
                $sold = (int) ($sums['consumption'] ?? 0);
                $total = $opening + $supplyIn;
                $expected = $total - $sold;

                $count = $counts[$dateKey][$pid] ?? null;
                $physical = $count?->physical_qty;
                $closing = $physical ?? $expected;
                $variance = $physical === null ? null : ($physical - $expected);

                $cells[$dateKey][$pid] = [
                    'opening'  => $opening,
                    'new'      => $supplyIn,
                    'total'    => $total,
                    'sold'     => $sold,
                    'expected' => $expected,
                    'physical' => $physical,
                    'closing'  => $closing,
                    'variance' => $variance,
                    'remark'   => $count?->remark ?? $this->autoRemark($variance),
                ];

                // Chain — tomorrow's opening is today's closing (physical
                // wins over expected when the operator has counted).
                $running[$pid] = $closing;
            }
        }

        return ['products' => $products, 'dates' => $dates, 'cells' => $cells];
    }

    /**
     * Per-day sums keyed by ISO date, then product. One query for the
     * whole window using GROUP BY DATE(occurred_at).
     *
     * @param  list<int>  $productIds
     * @return array<string, array<int, array{supply_in:int, consumption:int}>>
     */
    private function fetchWindowSumsByDay(array $productIds, Carbon $windowStart, Carbon $windowEnd): array
    {
        $rows = StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->select(
                'product_id',
                DB::raw("DATE(occurred_at) as day"),
                DB::raw('SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END) as supply_in'),
                DB::raw('SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END) as consumption'),
            )
            ->groupBy('product_id', DB::raw('DATE(occurred_at)'))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $day = (string) $r->day;
            $out[$day][(int) $r->product_id] = [
                'supply_in'   => (int) $r->supply_in,
                'consumption' => (int) $r->consumption,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, array<int, PhysicalCount>>
     */
    private function fetchCountsInWindow(array $productIds, Carbon $windowStart, Carbon $windowEnd): array
    {
        $rows = PhysicalCount::query()
            ->whereIn('product_id', $productIds)
            ->whereBetween('count_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $day = $r->count_date instanceof Carbon
                ? $r->count_date->toDateString()
                : (string) $r->count_date;
            $out[$day][(int) $r->product_id] = $r;
        }

        return $out;
    }
}
