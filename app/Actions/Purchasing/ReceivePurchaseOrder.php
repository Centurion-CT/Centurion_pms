<?php

declare(strict_types=1);

namespace App\Actions\Purchasing;

use App\Actions\Pos\ReceiveStock;
use App\Enums\PurchaseOrderStatus;
use App\Models\Operator;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Accept a delivery against a PO. Callers pass a map of
 * [line_id => quantity_received_now]; the action:
 *   - clamps the receipt at the outstanding qty per line (can't receive
 *     more than was ordered)
 *   - writes one Received stock movement per line via ReceiveStock,
 *     stamping the PO's unit_cost as the new cost basis
 *   - increments quantity_received on each line
 *   - transitions the PO status:
 *       fully received  → Received (+ stamps received_at)
 *       partially       → PartiallyReceived
 *
 * Idempotent-safe: if you send `line_id => 0` for a line the action
 * silently skips it. A PO with status Draft is auto-promoted to Sent
 * on first receipt so operators can skip the "mark as sent" step when
 * stock walks in the door with an invoice already attached.
 */
class ReceivePurchaseOrder
{
    public function __construct(private readonly ReceiveStock $receiveStock) {}

    /**
     * @param  array<int, int>  $receiptByLineId  line id => quantity received now
     * @return array{received_lines: int, movements: int}
     */
    public function handle(
        PurchaseOrder $po,
        array $receiptByLineId,
        ?Operator $operator = null,
        ?string $note = null,
    ): array {
        if ($po->status === PurchaseOrderStatus::Cancelled) {
            throw new LogicException('Cannot receive against a cancelled purchase order.');
        }
        if ($po->status === PurchaseOrderStatus::Received) {
            throw new LogicException('Purchase order is already fully received.');
        }

        return DB::transaction(function () use ($po, $receiptByLineId, $operator, $note): array {
            $movementLines = [];
            $updatedLines = 0;

            foreach ($po->lines()->lockForUpdate()->get() as $line) {
                $requested = (int) ($receiptByLineId[$line->id] ?? 0);
                if ($requested <= 0) {
                    continue;
                }
                $outstanding = $line->outstanding();
                if ($outstanding <= 0) {
                    continue; // already fully received on this line
                }
                if ($requested > $outstanding) {
                    throw new InvalidArgumentException(
                        "Receipt for line {$line->id} exceeds outstanding qty ({$requested} > {$outstanding}).",
                    );
                }

                $line->quantity_received += $requested;
                $line->save();
                $updatedLines++;

                $movementLines[] = [
                    'product_id' => (int) $line->product_id,
                    'quantity'   => $requested,
                    'unit_cost'  => (int) $line->unit_cost,
                ];
            }

            $movements = 0;
            if ($movementLines !== []) {
                $movements = $this->receiveStock->handle(
                    lines:    $movementLines,
                    operator: $operator,
                    note:     $note ?? "PO {$po->reference}",
                );
            }

            $po->refresh()->load('lines');
            if ($po->isFullyReceived()) {
                $po->status = PurchaseOrderStatus::Received;
                $po->received_at = now();
            } elseif ($po->hasPartialReceipt()) {
                $po->status = PurchaseOrderStatus::PartiallyReceived;
                if ($po->placed_at === null) {
                    $po->placed_at = now();
                }
            }
            $po->save();

            return [
                'received_lines' => $updatedLines,
                'movements'      => $movements,
            ];
        });
    }

    /**
     * Convenience — mark the whole PO as received in full (used by
     * the "Receive all" button on the PO detail page).
     *
     * @return array{received_lines: int, movements: int}
     */
    public function receiveAll(PurchaseOrder $po, ?Operator $operator = null, ?string $note = null): array
    {
        $map = $po->lines
            ->mapWithKeys(fn (PurchaseOrderLine $l) => [$l->id => $l->outstanding()])
            ->all();

        return $this->handle($po, $map, $operator, $note);
    }
}
