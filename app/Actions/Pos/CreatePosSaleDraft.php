<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\PosSaleStatus;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Instantiate a draft sale + its line items from a cart array. Draft
 * sales don't touch stock or payments — they're a persistent shopping
 * cart the operator can edit before committing via {@see CompletePosSale}.
 *
 * Cart lines are either product-backed (product_id set, name +
 * unit_price snapshotted from the product) or freeform (product_id
 * null, caller supplies name + unit_price for one-off "Other" lines).
 */
class CreatePosSaleDraft
{
    /**
     * @param  list<array{product_id?: int|null, name?: string|null, quantity: int, unit_price?: int|null, is_complimentary?: bool}>  $cart
     */
    public function handle(
        Outlet $outlet,
        Operator $operator,
        array $cart,
        ?int $bookingId = null,
        ?int $customerId = null,
        ?string $notes = null,
        ?string $clientKey = null,
        ?int $creditLineId = null,
        bool $saleIsComplimentary = false,
        ?string $complimentaryReason = null,
        ?string $customerPhoneSnapshot = null,
        ?string $customerNameSnapshot = null,
    ): PosSale {
        if ($cart === []) {
            throw new InvalidArgumentException('Cannot create a sale with an empty cart.');
        }
        // A sale routes charge-to-room to either a booking folio OR a
        // credit line, never both — pick one or leave the sale free.
        if ($bookingId !== null && $creditLineId !== null) {
            throw new InvalidArgumentException(
                'Sale can charge to a booking or a credit line, not both.',
            );
        }
        if ($saleIsComplimentary && ($complimentaryReason === null || trim($complimentaryReason) === '')) {
            throw new InvalidArgumentException(
                'Complimentary sales must carry a reason for the audit trail.',
            );
        }

        return DB::transaction(function () use (
            $outlet, $operator, $cart, $bookingId, $customerId, $notes, $clientKey,
            $creditLineId, $saleIsComplimentary, $complimentaryReason,
            $customerPhoneSnapshot, $customerNameSnapshot,
        ): PosSale {
            $sale = PosSale::query()->create([
                'tenant_id' => $outlet->tenant_id,
                'outlet_id' => $outlet->id,
                'operator_id' => $operator->id,
                'reference' => self::makeReference($outlet),
                'client_key' => $clientKey,
                'status' => PosSaleStatus::Draft,
                'booking_id' => $bookingId,
                'credit_line_id' => $creditLineId,
                'customer_id' => $customerId,
                'customer_phone_snapshot' => $customerPhoneSnapshot !== null && $customerPhoneSnapshot !== ''
                    ? $customerPhoneSnapshot : null,
                'customer_name_snapshot' => $customerNameSnapshot !== null && $customerNameSnapshot !== ''
                    ? $customerNameSnapshot : null,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'notes' => $notes,
                'is_complimentary' => $saleIsComplimentary,
                'complimentary_by_operator_id' => $saleIsComplimentary ? $operator->id : null,
                'complimentary_reason' => $saleIsComplimentary ? substr(trim((string) $complimentaryReason), 0, 200) : null,
            ]);

            $chargeableSubtotal = 0;
            foreach ($cart as $line) {
                $quantity = (int) ($line['quantity'] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                $productId = isset($line['product_id']) ? (int) $line['product_id'] : null;
                $name = (string) ($line['name'] ?? '');
                $unitPrice = isset($line['unit_price']) ? (int) $line['unit_price'] : null;
                $lineIsComp = ! empty($line['is_complimentary']);

                if ($productId) {
                    $product = Product::query()->find($productId);
                    if ($product === null || $product->outlet_id !== $outlet->id) {
                        continue; // silently drop cross-outlet or missing products
                    }
                    $name = $name !== '' ? $name : $product->name;
                    $unitPrice = $unitPrice ?? (int) $product->price;
                }

                if ($name === '' || $unitPrice === null) {
                    throw new InvalidArgumentException('Freeform cart lines need name + unit_price.');
                }

                $lineSubtotal = $quantity * $unitPrice;
                // Comp lines still record their original unit price +
                // subtotal (for reporting), but their contribution to
                // the sale's chargeable subtotal is zero. When the whole
                // sale is comped everything is treated the same way.
                if (! $lineIsComp && ! $saleIsComplimentary) {
                    $chargeableSubtotal += $lineSubtotal;
                }

                PosSaleLine::query()->create([
                    'tenant_id' => $outlet->tenant_id,
                    'pos_sale_id' => $sale->id,
                    'product_id' => $productId,
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'is_complimentary' => $lineIsComp || $saleIsComplimentary,
                ]);
            }

            // Recompute totals from the chargeable (non-comp) lines. A
            // fully complimentary sale ends up total = 0 and no Payment
            // rows are written by CompletePosSale.
            $tax = (int) round($chargeableSubtotal * ((float) $outlet->tax_rate / 100));
            $sale->forceFill([
                'subtotal' => $chargeableSubtotal,
                'tax_amount' => $tax,
                'total' => $chargeableSubtotal + $tax,
            ])->save();

            return $sale->load('lines');
        });
    }

    private static function makeReference(Outlet $outlet): string
    {
        $prefix = strtoupper(substr($outlet->slug, 0, 3)) ?: 'POS';

        return 'KV-'.$prefix.'-'.Str::upper(Str::random(6));
    }
}
