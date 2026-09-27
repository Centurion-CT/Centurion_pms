<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PosSaleStatus;
use App\Enums\StockMovementReason;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\PosSaleReturn;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Line-level partial refund against a Completed POS sale.
 *
 * Guest brings back one of the four drinks she ordered? Cashier picks
 * that line, sets quantity = 1, and this action:
 *   1. Restores stock (RecordStockMovement: reason=Adjusted, positive)
 *      — composite products walk the recipe like sale did on the way
 *      out. Non-tracked products skip stock.
 *   2. Creates a `pos_sale_returns` row + one child per refunded line
 *      (snapshot-priced) so the audit trail carries who returned what
 *      and why.
 *   3. Records the refund on the money ledger — a new Payment row with
 *      status=Refunded so Finance's "Refunds" metric nets it out of
 *      Collected without disturbing the original Succeeded rows.
 *   4. Charge-to-room path: reduces (or deletes when driven to zero)
 *      the associated BookingCharge, provided it hasn't been settled
 *      yet. Settled ones stay put — ops needs to refund those out-of-band.
 *
 * Multiple returns can happen against the same sale as long as the
 * outstanding qty on each line stays non-negative. Void is still the
 * right tool for reversing a whole sale in one action.
 */
class RefundPosSaleLines
{
    public function __construct(private readonly RecordStockMovement $movements) {}

    /**
     * @param  list<array{sale_line_id: int, quantity: int}>  $returns
     * @param  'cash'|'transfer'|'card'|'pos'|'room'|'store_credit'|'other'  $refundMethod
     */
    public function handle(
        PosSale $sale,
        Operator $operator,
        array $returns,
        string $reason,
        string $refundMethod = 'cash',
    ): PosSaleReturn {
        if ($sale->status !== PosSaleStatus::Completed) {
            throw new RuntimeException("Sale {$sale->reference} is {$sale->status->value}; only Completed sales can be refunded.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required for the refund.');
        }
        if ($returns === []) {
            throw new InvalidArgumentException('At least one line must be selected to refund.');
        }

        return DB::transaction(function () use ($sale, $operator, $returns, $reason, $refundMethod): PosSaleReturn {
            $now = Carbon::now();
            $saleLines = $sale->lines->keyBy('id');

            $normalised = [];   // per-line effective quantities post-clamp
            $totalRefund = 0;

            foreach ($returns as $r) {
                $lineId = (int) ($r['sale_line_id'] ?? 0);
                $qty = (int) ($r['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $line = $saleLines->get($lineId);
                if ($line === null) {
                    throw new InvalidArgumentException("Line {$lineId} isn't on sale {$sale->reference}.");
                }

                // Reserve outstanding = original - already-returned.
                $alreadyReturned = (int) DB::table('pos_sale_return_lines')
                    ->where('pos_sale_line_id', $line->id)
                    ->sum('quantity');
                $outstanding = max(0, (int) $line->quantity - $alreadyReturned);
                if ($qty > $outstanding) {
                    throw new InvalidArgumentException(
                        "Line {$line->id} has only {$outstanding} unit(s) still refundable ({$qty} requested).",
                    );
                }

                $subtotal = $qty * (int) $line->unit_price;
                $normalised[] = [
                    'line'     => $line,
                    'qty'      => $qty,
                    'subtotal' => $subtotal,
                ];
                $totalRefund += $subtotal;
            }

            if ($totalRefund <= 0) {
                throw new InvalidArgumentException('Refund total came out to zero — nothing to refund.');
            }

            // 1. Restore stock per refunded line.
            foreach ($normalised as $row) {
                $line = $row['line'];
                if ($line->product_id === null) {
                    continue;
                }
                $product = Product::query()->with('recipeItems.component')->find($line->product_id);
                if ($product === null) {
                    continue;
                }

                $recipe = $product->recipeItems;
                if ($recipe->isNotEmpty()) {
                    foreach ($recipe as $item) {
                        $component = $item->component;
                        if ($component === null || ! $component->track_stock) {
                            continue;
                        }
                        $this->movements->handle(
                            product:  $component,
                            delta:    (int) $row['qty'] * (int) $item->quantity,
                            reason:   StockMovementReason::Adjusted,
                            operator: $operator,
                            source:   $sale,
                            note:     "Return · {$product->name} · {$sale->reference}: {$reason}",
                            occurredAt: $now,
                        );
                    }
                    continue;
                }

                if (! $product->track_stock) {
                    continue;
                }
                $this->movements->handle(
                    product:  $product,
                    delta:    (int) $row['qty'],
                    reason:   StockMovementReason::Adjusted,
                    operator: $operator,
                    source:   $sale,
                    note:     "Return of {$sale->reference}: {$reason}",
                    occurredAt: $now,
                );
            }

            // 2. Ledger the return event + line children.
            /** @var PosSaleReturn $return */
            $return = $sale->returns()->create([
                'tenant_id'     => $sale->tenant_id,
                'operator_id'   => $operator->id,
                'refund_amount' => $totalRefund,
                'refund_method' => $refundMethod,
                'reason'        => $reason,
                'refunded_at'   => $now,
            ]);
            foreach ($normalised as $row) {
                $return->lines()->create([
                    'tenant_id'        => $sale->tenant_id,
                    'pos_sale_line_id' => $row['line']->id,
                    'quantity'         => (int) $row['qty'],
                    'unit_price'       => (int) $row['line']->unit_price,
                    'subtotal'         => (int) $row['subtotal'],
                ]);
            }

            // 3. Money-ledger — a Refunded Payment row so Finance nets
            // it against Collected without touching the original.
            Payment::query()->create([
                'tenant_id'          => $sale->tenant_id,
                'booking_id'         => $sale->booking_id,
                'invoice_id'         => null,
                'pos_sale_id'        => $sale->id,
                'paystack_reference' => 'pos-refund-'.Str::lower(Str::ulid()->toBase32()),
                'amount'             => $totalRefund,
                'type'               => PaymentType::PosSale,
                'method'             => $refundMethod,
                'reference_note'     => "Refund · {$reason}",
                'status'             => PaymentStatus::Refunded,
                'paid_at'            => $now,
            ]);

            // 4. Charge-to-room adjustment — reduce or delete any
            // still-unsettled BookingCharge from the original sale.
            if ($sale->booking_id !== null) {
                $charge = BookingCharge::query()
                    ->where('booking_id', $sale->booking_id)
                    ->where('description', 'like', '%'.$sale->reference)
                    ->where('settled', false)
                    ->first();
                if ($charge !== null) {
                    $remaining = (int) $charge->amount - $totalRefund;
                    if ($remaining <= 0) {
                        $charge->delete();
                    } else {
                        $charge->update(['amount' => $remaining]);
                    }
                }
            }

            activity('pos_sale')
                ->causedBy($operator)
                ->performedOn($sale)
                ->event('pos_sale_refunded')
                ->withProperties([
                    'reference'     => $sale->reference,
                    'refund_amount' => $totalRefund,
                    'reason'        => $reason,
                    'method'        => $refundMethod,
                ])
                ->log("Operator {$operator->name} refunded ₦{$totalRefund} on {$sale->reference}: {$reason}");

            return $return->fresh('lines');
        });
    }
}
