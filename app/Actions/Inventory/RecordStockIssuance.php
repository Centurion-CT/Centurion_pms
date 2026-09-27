<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Pos\RecordStockMovement;
use App\Enums\StockMovementReason;
use App\Models\Operator;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Store → outlet stock issuance. Writes a paired -N (store product) /
 * +N (outlet product) via the existing RecordStockMovement choke-point
 * inside one outer transaction, then cross-links the two rows through
 * their polymorphic `source` so InventoryTimeline can group them.
 *
 * Mirrors the pattern used for `Transferred` (peer-to-peer between
 * outlets) — same paired-movement shape, different reason.
 */
class RecordStockIssuance
{
    public function __construct(
        private readonly RecordStockMovement $recordMovement,
    ) {}

    /**
     * @return array{store: StockMovement, outlet: StockMovement}
     */
    public function handle(
        Product $storeProduct,
        Product $outletProduct,
        int $quantity,
        ?Operator $operator = null,
        ?string $note = null,
        ?Carbon $occurredAt = null,
    ): array {
        $this->guard($storeProduct, $outletProduct, $quantity);

        return DB::transaction(function () use (
            $storeProduct, $outletProduct, $quantity, $operator, $note, $occurredAt
        ): array {
            $storeMovement = $this->recordMovement->handle(
                product:    $storeProduct,
                delta:      -$quantity,
                reason:     StockMovementReason::Issued,
                operator:   $operator,
                unitCost:   $storeProduct->cost,
                note:       $note,
                occurredAt: $occurredAt,
            );

            $outletMovement = $this->recordMovement->handle(
                product:    $outletProduct,
                delta:      $quantity,
                reason:     StockMovementReason::Issued,
                operator:   $operator,
                unitCost:   $storeProduct->cost,
                note:       $note,
                occurredAt: $occurredAt,
            );

            // Cross-link — each row's `source` points at its pair, so
            // the timeline can render "1 issuance" instead of 2 separate
            // rows and reconciliation can walk either side to find the
            // other.
            $storeMovement->forceFill([
                'source_type' => StockMovement::class,
                'source_id'   => $outletMovement->id,
            ])->save();

            $outletMovement->forceFill([
                'source_type' => StockMovement::class,
                'source_id'   => $storeMovement->id,
            ])->save();

            return ['store' => $storeMovement, 'outlet' => $outletMovement];
        });
    }

    private function guard(Product $storeProduct, Product $outletProduct, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Issuance quantity must be positive.');
        }
        if ($storeProduct->store_id === null) {
            throw new RuntimeException(
                "Source product '{$storeProduct->name}' is not a store product.",
            );
        }
        if ($outletProduct->outlet_id === null) {
            throw new RuntimeException(
                "Target product '{$outletProduct->name}' is not an outlet product.",
            );
        }
        if ((int) $storeProduct->tenant_id !== (int) $outletProduct->tenant_id) {
            throw new RuntimeException('Store and outlet products belong to different tenants.');
        }
        if ((int) $storeProduct->quantity_on_hand < $quantity) {
            throw new RuntimeException(
                "Not enough stock in store — {$storeProduct->name} has {$storeProduct->quantity_on_hand} on hand, tried to issue {$quantity}.",
            );
        }
    }
}
