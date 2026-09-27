<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\OutletType;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deducts amenity stock from a Housekeeping outlet for a batch of room
 * turnovers.
 *
 * For each product on the outlet with `usage_per_clean > 0`:
 *   - decrement quantity_on_hand by (usage_per_clean * rooms)
 *   - if rotational, bump qty_in_use by the same amount (linens leave
 *     the shelf but aren't spent — they'll come back through the
 *     laundry loop)
 *   - write a StockMovement row with reason=Used, quantity_after set,
 *     and a note recording the room count + operator-supplied reference
 *
 * All updates run inside one transaction. Returns the number of lines
 * that moved so the caller can render a "N items deducted" toast.
 */
class ApplyHousekeepingTurnover
{
    /**
     * @return array{lines_moved:int, total_units:int}
     */
    public function handle(
        Outlet $outlet,
        int $roomsCleaned,
        ?string $noteRef = null,
        ?Operator $operator = null,
    ): array {
        if ($outlet->type !== OutletType::Housekeeping) {
            throw new RuntimeException('Turnover consumption can only run against a Housekeeping outlet.');
        }
        if ($roomsCleaned <= 0) {
            throw new RuntimeException('Rooms cleaned must be a positive integer.');
        }

        $products = Product::query()
            ->where('outlet_id', $outlet->id)
            ->where('active', true)
            ->where('track_stock', true)
            ->where('usage_per_clean', '>', 0)
            ->get();

        if ($products->isEmpty()) {
            return ['lines_moved' => 0, 'total_units' => 0];
        }

        $baseNote = sprintf(
            'Turnover · %d %s%s',
            $roomsCleaned,
            $roomsCleaned === 1 ? 'room' : 'rooms',
            $noteRef !== null && $noteRef !== '' ? ' · '.$noteRef : '',
        );

        return DB::transaction(function () use ($products, $roomsCleaned, $baseNote, $operator): array {
            $linesMoved = 0;
            $totalUnits = 0;

            foreach ($products as $product) {
                $delta = -1 * $product->usage_per_clean * $roomsCleaned;
                $abs = abs($delta);

                // Won't over-draw; if there's less than we'd consume,
                // deduct what's on hand and mark the shortfall in the
                // note. Keeps the sheet honest instead of failing the
                // whole batch.
                $take = min($abs, $product->quantity_on_hand);
                $shortfall = $abs - $take;

                if ($take === 0) {
                    continue;
                }

                $product->quantity_on_hand -= $take;
                if ($product->is_rotational) {
                    $product->qty_in_use += $take;
                }
                $product->save();

                StockMovement::query()->create([
                    'tenant_id'      => $product->tenant_id,
                    'product_id'     => $product->id,
                    'operator_id'    => $operator?->id,
                    'reason'         => StockMovementReason::Used,
                    'delta'          => -$take,
                    'quantity_after' => $product->quantity_on_hand,
                    'note'           => $shortfall > 0
                        ? $baseNote.' · shortfall '.$shortfall
                        : $baseNote,
                    'occurred_at'    => now(),
                ]);

                $linesMoved++;
                $totalUnits += $take;
            }

            return ['lines_moved' => $linesMoved, 'total_units' => $totalUnits];
        });
    }
}
