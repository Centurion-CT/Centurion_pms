<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PosSaleStatus;
use App\Models\OutletShift;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a raw OutletShift into a fully-hydrated report structure used
 * by both the Livewire ShiftShow view AND the CSV export controller.
 * Keeps the two in lockstep so the CSV never shows numbers that don't
 * match the on-screen report.
 */
class ShiftReportBuilder
{
    /**
     * @return array{
     *   window: array{opened_at: Carbon, closed_at: Carbon},
     *   is_open: bool,
     *   sales: array{count: int, gross: int, net: int, voided_count: int, voided_amount: int, comp_count: int, comp_value: int, avg_ticket: int},
     *   tenders: array<int, array{method: string, count: int, amount: int}>,
     *   stock: array<int, array{product_id: int, name: string, opening_qty: int, sold_qty: int, other_delta: int, closing_qty: int|null, expected_closing: int, variance: int|null, unit_price: int|null}>
     * }
     */
    public function build(OutletShift $shift): array
    {
        $openedAt = $shift->opened_at;
        // Open shifts report against "now" so the operator can see
        // what's happened so far without closing.
        $windowEnd = $shift->closed_at ?? Carbon::now();

        $baseSales = PosSale::query()
            ->where('outlet_id', $shift->outlet_id)
            ->where('operator_id', $shift->operator_id)
            ->whereBetween('created_at', [$openedAt, $windowEnd]);

        $completed = (clone $baseSales)->where('status', PosSaleStatus::Completed->value);
        $voided = (clone $baseSales)->where('status', PosSaleStatus::Voided->value);

        $completedCollection = (clone $completed)->get(['id', 'total', 'is_complimentary']);
        $count = $completedCollection->count();
        $gross = (int) $completedCollection->sum('total');
        $voidedCount = (int) (clone $voided)->count();
        $voidedAmount = (int) (clone $voided)->sum('total');
        $net = $gross;  // gross already excludes voided rows (only Completed summed)

        $compRows = $completedCollection->where('is_complimentary', true);
        $compCount = $compRows->count();
        $compValue = (int) PosSaleLine::query()
            ->whereIn('pos_sale_id', $compRows->pluck('id'))
            ->sum('subtotal');

        // Per-tender rollup via the Payment ledger. Every completed
        // sale writes one Payment row per tender, so grouping by method
        // gives us the granular split (cash / card / room / …).
        $tenders = \App\Models\Payment::query()
            ->whereIn('pos_sale_id', $completedCollection->pluck('id'))
            ->where('status', 'succeeded')
            ->selectRaw('method, COUNT(*) as tender_count, SUM(amount) as amount')
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'method' => (string) $row->method,
                'count' => (int) $row->tender_count,
                'amount' => (int) $row->amount,
            ])
            ->all();

        // Per-product stock reconciliation.
        $openingByProduct = $shift->snapshots
            ->where('kind', 'opening')
            ->keyBy('product_id');
        $closingByProduct = $shift->snapshots
            ->where('kind', 'closing')
            ->keyBy('product_id');

        // Sold-during-shift per product (POS side).
        $soldByProduct = PosSaleLine::query()
            ->whereIn('pos_sale_id', $completedCollection->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as sold_qty')
            ->groupBy('product_id')
            ->pluck('sold_qty', 'product_id');

        // Other movements during the window (receive, adjust, waste,
        // transfer). Excludes 'sold' movements — those are captured
        // by soldByProduct above so we don't double-count.
        $otherMovements = StockMovement::query()
            ->whereBetween('occurred_at', [$openedAt, $windowEnd])
            ->whereIn('product_id', $openingByProduct->keys())
            ->where('reason', '!=', \App\Enums\StockMovementReason::Sold->value)
            ->selectRaw('product_id, SUM(delta) as other_delta')
            ->groupBy('product_id')
            ->pluck('other_delta', 'product_id');

        $stock = $openingByProduct->map(function ($opening) use ($closingByProduct, $soldByProduct, $otherMovements) {
            $pid = (int) $opening->product_id;
            $openingQty = (int) $opening->quantity_on_hand;
            $sold = (int) ($soldByProduct[$pid] ?? 0);
            $other = (int) ($otherMovements[$pid] ?? 0);
            $expectedClosing = $openingQty - $sold + $other;

            $closingRow = $closingByProduct->get($pid);
            $closingQty = $closingRow ? (int) $closingRow->quantity_on_hand : null;
            $variance = $closingQty === null ? null : ($closingQty - $expectedClosing);

            return [
                'product_id' => $pid,
                'name' => $opening->product_name,
                'unit_price' => $opening->unit_price !== null ? (int) $opening->unit_price : null,
                'opening_qty' => $openingQty,
                'sold_qty' => $sold,
                'other_delta' => $other,
                'closing_qty' => $closingQty,
                'expected_closing' => $expectedClosing,
                'variance' => $variance,
            ];
        })
        ->sortBy(fn (array $r) => mb_strtolower((string) $r['name']))
        ->values()
        ->all();

        return [
            'window' => [
                'opened_at' => $openedAt,
                'closed_at' => $windowEnd,
            ],
            'is_open' => $shift->closed_at === null,
            'sales' => [
                'count' => $count,
                'gross' => $gross,
                'net' => $net,
                'voided_count' => $voidedCount,
                'voided_amount' => $voidedAmount,
                'comp_count' => $compCount,
                'comp_value' => $compValue,
                'avg_ticket' => $count > 0 ? (int) round($net / $count) : 0,
            ],
            'tenders' => $tenders,
            'stock' => $stock,
        ];
    }
}
