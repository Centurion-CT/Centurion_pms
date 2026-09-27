<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\PaymentStatus;
use App\Enums\PosSaleStatus;
use App\Enums\StockMovementReason;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reverse a completed sale. Restores stock (paired positive movement
 * per Sold entry), marks the Payment as Refunded, clears any
 * charge-to-room BookingCharge (deletes it if still unsettled;
 * otherwise leaves it — that money's been paid, ops needs to refund
 * separately). Manager-only in v1.
 *
 * Reason is required so the reversal is auditable — an accidental
 * void beats a silent one.
 */
class VoidPosSale
{
    public function __construct(private readonly RecordStockMovement $movements) {}

    public function handle(PosSale $sale, Operator $operator, string $reason): PosSale
    {
        if ($sale->status === PosSaleStatus::Voided) {
            return $sale;
        }
        if ($sale->status !== PosSaleStatus::Completed) {
            throw new RuntimeException("Sale {$sale->reference} is {$sale->status->value}; only Completed sales can be voided.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Void reason is required.');
        }

        return DB::transaction(function () use ($sale, $operator, $reason): PosSale {
            $now = Carbon::now();

            // 1. Restore stock for every Sold movement this sale wrote.
            $movements = StockMovement::query()
                ->where('source_type', PosSale::class)
                ->where('source_id', $sale->id)
                ->where('reason', StockMovementReason::Sold->value)
                ->get();

            foreach ($movements as $m) {
                $product = Product::query()->find($m->product_id);
                if ($product === null || ! $product->track_stock) {
                    continue;
                }
                $this->movements->handle(
                    product:  $product,
                    delta:    -1 * (int) $m->delta, // Sold was negative; restore is positive
                    reason:   StockMovementReason::Adjusted,
                    operator: $operator,
                    source:   $sale,
                    note:     "Void of {$sale->reference}: {$reason}",
                    occurredAt: $now,
                );
            }

            // 2. Refund the tender.
            $sale->payments()
                ->where('status', PaymentStatus::Succeeded->value)
                ->update([
                    'status'  => PaymentStatus::Refunded->value,
                    'paid_at' => $now,
                ]);

            // 3. Charge-to-room: if the BookingCharge hasn't been settled
            // yet, delete it. If it HAS been settled, leave it — ops
            // needs to arrange the refund out-of-band.
            if ($sale->booking_id !== null) {
                BookingCharge::query()
                    ->where('booking_id', $sale->booking_id)
                    ->where('description', 'like', '%'.$sale->reference)
                    ->where('settled', false)
                    ->delete();
            }

            $sale->forceFill([
                'status'        => PosSaleStatus::Voided,
                'voided_at'     => $now,
                'voided_reason' => $reason,
            ])->save();

            activity('pos_sale')
                ->causedBy($operator)
                ->performedOn($sale)
                ->event('pos_sale_voided')
                ->withProperties([
                    'reference' => $sale->reference,
                    'reason'    => $reason,
                    'total'     => $sale->total,
                ])
                ->log("Operator {$operator->name} voided sale {$sale->reference}: {$reason}");

            return $sale->fresh(['lines', 'payments']);
        });
    }
}
