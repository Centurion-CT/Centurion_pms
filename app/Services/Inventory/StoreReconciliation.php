<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StockMovementReason;
use App\Models\Outlet;
use App\Models\PosSaleLine;
use App\Models\Product;
use App\Models\Store;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-store reconciliation for a date range — answers the property
 * manager's core question: are outlets pilfering, over-ordering, or
 * under-selling?
 *
 * For each store product it computes:
 *   - opening qty       — quantity_after at the last movement <  from
 *   - received qty      — sum of positive deltas WHERE reason=Received in window
 *   - issued qty        — sum of negative deltas WHERE reason=Issued in window
 *                         (also broken down by receiving outlet)
 *   - sold qty          — POS sale line qty for products SOURCED from
 *                         this store (via outlet product ↔ store product
 *                         match on sku/name) grouped per outlet
 *   - expected closing  — opening + received − issued (in the store)
 *   - variance qty      — physical count − expected (from DailyStockSheet
 *                         if the sheet has been reconciled for the day)
 *
 * Money side (all in naira, snapshot at movement time):
 *   - received value    — Σ received_qty × unit_cost captured on the receipt
 *   - issued value      — Σ issued_qty × unit_cost captured at issuance
 *                         (cost snapshot copied from store product at the
 *                         moment RecordStockIssuance ran — so a later cost
 *                         edit doesn't retroactively re-value old issuances)
 *   - sold value        — Σ pos_sale_line.subtotal on those outlet products
 *   - variance value    — variance_qty × current_unit_cost
 *
 * NOT computed here: cost of goods SOLD (needs weighted-average
 * costing per outlet, which is a follow-up). Everything above uses
 * transaction-cost snapshots so the picture is consistent within a
 * period even if the operator edits prices mid-period.
 */
class StoreReconciliation
{
    /**
     * @return array{
     *     window: array{from: string, to: string},
     *     store: array{id: int, name: string},
     *     totals: array{received: array{qty:int, value:int}, issued: array{qty:int, value:int}, sold: array{qty:int, value:int}, variance: array{qty:int, value:int}},
     *     outlets: list<array{id:int, name:string}>,
     *     rows: list<array<string,mixed>>,
     * }
     */
    public function build(Store $store, Carbon $from, Carbon $to): array
    {
        $fromDt = $from->copy()->startOfDay();
        $toDt = $to->copy()->endOfDay();

        // Products this store carries (regardless of activity in
        // window) so the report shows a full inventory row even when
        // nothing moved.
        $storeProducts = Product::query()
            ->where('store_id', $store->getKey())
            ->orderBy('name')
            ->get();

        if ($storeProducts->isEmpty()) {
            return $this->emptyResult($store, $fromDt, $toDt);
        }

        // Every outlet on the property that could have received stock
        // from this store — used for the "issued to" column headers.
        $outlets = Outlet::query()
            ->where('property_id', $store->property_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $storeProductIds = $storeProducts->pluck('id')->all();

        // Movements in the window on this store's products, split by
        // reason so a single query does the work of six.
        $movementsInWindow = StockMovement::query()
            ->whereIn('product_id', $storeProductIds)
            ->whereBetween('occurred_at', [$fromDt, $toDt])
            ->get(['product_id', 'reason', 'delta', 'unit_cost', 'occurred_at']);

        // Opening qty = quantity_after at the LAST movement strictly
        // before window start. Null means we've never moved this
        // product → opening 0.
        $openingByProduct = StockMovement::query()
            ->whereIn('product_id', $storeProductIds)
            ->where('occurred_at', '<', $fromDt)
            ->selectRaw('product_id, MAX(id) as last_id')
            ->groupBy('product_id')
            ->get()
            ->pluck('last_id', 'product_id');
        $openingRows = $openingByProduct->isNotEmpty()
            ? StockMovement::query()
                ->whereIn('id', $openingByProduct->values()->all())
                ->get(['product_id', 'quantity_after'])
                ->keyBy('product_id')
            : collect();

        // Issued movements carry a source link back to a StockRequest —
        // the paired outlet-side movement (issued_pair_movement_id on
        // the request) gives us the RECEIVING outlet. Fetch those
        // pair rows to attribute issuances to outlets.
        $issuedRows = StockMovement::query()
            ->whereIn('product_id', $storeProductIds)
            ->where('reason', StockMovementReason::Issued->value)
            ->whereBetween('occurred_at', [$fromDt, $toDt])
            ->get(['id', 'product_id', 'delta', 'unit_cost', 'source_type', 'source_id']);

        // Join through StockRequest → pair movement → outlet product
        // to find which outlet received each issuance. Keyed as
        // (store_product_id, outlet_id) → running total.
        $issuedByOutlet = $this->issuancesByOutlet($issuedRows);

        // POS sales in the window for products that were sourced from
        // this store. Match by (sku, name) between store products and
        // outlet products — a store product and its outlet-side twin
        // share those. Keyed as (store_product_id, outlet_id).
        $soldByOutlet = $this->salesByOutlet($storeProducts, $store, $fromDt, $toDt);

        // Physical counts from the DailyStockSheet closest to $to.
        // Skipped for MVP — a follow-up can wire in DailyStockSheet.
        $physicalByProduct = collect();

        $rows = [];
        $totRecQty = $totRecVal = $totIssQty = $totIssVal = 0;
        $totSoldQty = $totSoldVal = $totVarQty = $totVarVal = 0;

        foreach ($storeProducts as $p) {
            $opening = (int) ($openingRows[$p->id]->quantity_after ?? 0);

            $received = $movementsInWindow->where('product_id', $p->id)
                ->where('reason', StockMovementReason::Received);
            $recQty = (int) $received->sum('delta');
            $recVal = (int) $received->sum(fn ($m) => (int) $m->delta * (int) ($m->unit_cost ?? 0));

            $issued = $movementsInWindow->where('product_id', $p->id)
                ->where('reason', StockMovementReason::Issued);
            // Issued deltas are negative on the store side. Flip sign
            // for display so "issued 5" reads as 5, not -5.
            $issQty = -1 * (int) $issued->sum('delta');
            $issVal = -1 * (int) $issued->sum(fn ($m) => (int) $m->delta * (int) ($m->unit_cost ?? 0));

            $expectedClosing = $opening + $recQty - $issQty;
            $physical = $physicalByProduct[$p->id] ?? null;
            $varQty = $physical !== null ? ($physical - $expectedClosing) : 0;
            $varVal = $varQty * (int) ($p->cost ?? 0);

            $perOutletIssued = [];
            $perOutletSold = [];
            $soldQty = 0;
            $soldVal = 0;
            foreach ($outlets as $o) {
                $qty = (int) ($issuedByOutlet[$p->id][$o->id]['qty'] ?? 0);
                $val = (int) ($issuedByOutlet[$p->id][$o->id]['value'] ?? 0);
                $perOutletIssued[$o->id] = ['qty' => $qty, 'value' => $val];

                $sQty = (int) ($soldByOutlet[$p->id][$o->id]['qty'] ?? 0);
                $sVal = (int) ($soldByOutlet[$p->id][$o->id]['value'] ?? 0);
                $perOutletSold[$o->id] = ['qty' => $sQty, 'value' => $sVal];
                $soldQty += $sQty;
                $soldVal += $sVal;
            }

            $rows[] = [
                'product_id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'unit_cost' => (int) ($p->cost ?? 0),
                'opening' => $opening,
                'received' => ['qty' => $recQty, 'value' => $recVal],
                'issued' => ['qty' => $issQty, 'value' => $issVal, 'per_outlet' => $perOutletIssued],
                'sold' => ['qty' => $soldQty, 'value' => $soldVal, 'per_outlet' => $perOutletSold],
                'expected_closing' => $expectedClosing,
                'physical_closing' => $physical,
                'variance' => ['qty' => $varQty, 'value' => $varVal],
            ];

            $totRecQty += $recQty; $totRecVal += $recVal;
            $totIssQty += $issQty; $totIssVal += $issVal;
            $totSoldQty += $soldQty; $totSoldVal += $soldVal;
            $totVarQty += $varQty; $totVarVal += $varVal;
        }

        return [
            'window' => ['from' => $fromDt->toDateString(), 'to' => $toDt->toDateString()],
            'store' => ['id' => $store->id, 'name' => $store->name],
            'totals' => [
                'received' => ['qty' => $totRecQty, 'value' => $totRecVal],
                'issued' => ['qty' => $totIssQty, 'value' => $totIssVal],
                'sold' => ['qty' => $totSoldQty, 'value' => $totSoldVal],
                'variance' => ['qty' => $totVarQty, 'value' => $totVarVal],
            ],
            'outlets' => $outlets->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->all(),
            'rows' => $rows,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StockMovement>  $issuedRows
     * @return array<int, array<int, array{qty:int, value:int}>>
     */
    private function issuancesByOutlet(Collection $issuedRows): array
    {
        if ($issuedRows->isEmpty()) return [];

        // RecordStockIssuance writes each pair with source_type =
        // StockMovement + source_id pointing at the OTHER side of the
        // pair. So a store-side Issued row's source_id points at the
        // outlet-side movement. Walk that pair → outlet product to
        // learn which outlet received the stock.
        $pairIds = $issuedRows->where('source_type', StockMovement::class)
            ->pluck('source_id')->filter()->unique()->all();
        if ($pairIds === []) return [];

        $pairs = StockMovement::query()
            ->whereIn('id', $pairIds)
            ->get(['id', 'product_id'])
            ->keyBy('id');

        $outletProductIds = $pairs->pluck('product_id')->filter()->unique()->all();
        $outletProducts = \App\Models\Product::query()
            ->whereIn('id', $outletProductIds)
            ->get(['id', 'outlet_id'])
            ->keyBy('id');

        $out = [];
        foreach ($issuedRows as $m) {
            $paired = $pairs[$m->source_id] ?? null;
            if ($paired === null) continue;
            $op = $outletProducts[$paired->product_id] ?? null;
            if ($op === null || $op->outlet_id === null) continue;
            $outletId = (int) $op->outlet_id;
            $pid = (int) $m->product_id;
            $qty = -1 * (int) $m->delta;                       // flip sign for display
            $val = $qty * (int) ($m->unit_cost ?? 0);
            $out[$pid][$outletId]['qty'] = ($out[$pid][$outletId]['qty'] ?? 0) + $qty;
            $out[$pid][$outletId]['value'] = ($out[$pid][$outletId]['value'] ?? 0) + $val;
        }
        return $out;
    }

    /**
     * Match store products to their outlet-side twins by sku (falls
     * back to name), then aggregate PosSaleLine qty + subtotal per
     * (store_product_id, outlet_id).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $storeProducts
     * @return array<int, array<int, array{qty:int, value:int}>>
     */
    private function salesByOutlet(Collection $storeProducts, Store $store, Carbon $from, Carbon $to): array
    {
        if ($storeProducts->isEmpty()) return [];

        // Twin map: store_product_id → list of outlet_product_ids.
        // sku is unique per (tenant, sku) so a store SKU may map to
        // multiple outlet products if the operator provisioned one
        // per outlet manually.
        $skuMap = $storeProducts->filter(fn ($p) => (string) $p->sku !== '')
            ->pluck('id', 'sku');
        $nameMap = $storeProducts->filter(fn ($p) => (string) $p->sku === '')
            ->pluck('id', 'name');

        $outletProducts = Product::query()
            ->whereNotNull('outlet_id')
            ->whereHas('outlet', fn ($q) => $q->where('property_id', $store->property_id))
            ->where(function ($q) use ($skuMap, $nameMap): void {
                $q->whereIn('sku', $skuMap->keys()->all())
                    ->orWhereIn('name', $nameMap->keys()->all());
            })
            ->get(['id', 'outlet_id', 'sku', 'name']);

        // outlet_product_id → [store_product_id, outlet_id]
        $twins = [];
        foreach ($outletProducts as $op) {
            $storePid = $skuMap[$op->sku] ?? $nameMap[$op->name] ?? null;
            if ($storePid === null) continue;
            $twins[$op->id] = ['store_product_id' => (int) $storePid, 'outlet_id' => (int) $op->outlet_id];
        }
        if ($twins === []) return [];

        $lines = PosSaleLine::query()
            ->whereIn('product_id', array_keys($twins))
            ->whereHas('sale', fn ($s) => $s->whereBetween('created_at', [$from, $to])
                ->where('status', \App\Enums\PosSaleStatus::Completed->value))
            ->get(['product_id', 'quantity', 'subtotal']);

        $out = [];
        foreach ($lines as $l) {
            $t = $twins[$l->product_id] ?? null;
            if ($t === null) continue;
            $sp = $t['store_product_id']; $o = $t['outlet_id'];
            $out[$sp][$o]['qty'] = ($out[$sp][$o]['qty'] ?? 0) + (int) $l->quantity;
            $out[$sp][$o]['value'] = ($out[$sp][$o]['value'] ?? 0) + (int) $l->subtotal;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(Store $store, Carbon $from, Carbon $to): array
    {
        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'store' => ['id' => $store->id, 'name' => $store->name],
            'totals' => [
                'received' => ['qty' => 0, 'value' => 0],
                'issued' => ['qty' => 0, 'value' => 0],
                'sold' => ['qty' => 0, 'value' => 0],
                'variance' => ['qty' => 0, 'value' => 0],
            ],
            'outlets' => [],
            'rows' => [],
        ];
    }
}
