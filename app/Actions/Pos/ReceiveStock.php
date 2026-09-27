<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk stock-in — a delivery from a supplier or an internal transfer
 * that adds units to inventory. Each line writes one movement via
 * {@see RecordStockMovement}. `unit_cost` is captured on the movement
 * for margin reporting and, when provided, becomes the product's new
 * cost basis (last-received wins — simple; recipe-based costing is
 * a Phase 2 concern).
 */
class ReceiveStock
{
    public function __construct(private readonly RecordStockMovement $movements) {}

    /**
     * @param  list<array{product_id: int, quantity: int, unit_cost?: int|null}>  $lines
     * @return int  count of movements written
     */
    public function handle(
        array $lines,
        ?Operator $operator = null,
        ?string $note = null,
    ): int {
        if ($lines === []) {
            throw new InvalidArgumentException('Cannot receive an empty stock delivery.');
        }

        return DB::transaction(function () use ($lines, $operator, $note): int {
            $count = 0;
            foreach ($lines as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }
                $product = Product::query()->findOrFail((int) $line['product_id']);
                if (! $product->track_stock) {
                    continue; // silently skip service items
                }

                $unitCost = isset($line['unit_cost']) ? (int) $line['unit_cost'] : null;
                if ($unitCost !== null && $unitCost >= 0) {
                    $product->forceFill(['cost' => $unitCost])->save();
                }

                $this->movements->handle(
                    product:  $product,
                    delta:    $quantity,
                    reason:   StockMovementReason::Received,
                    operator: $operator,
                    unitCost: $unitCost,
                    note:     $note,
                );
                $count++;
            }
            return $count;
        });
    }
}
