<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Operator;
use App\Models\PhysicalCount;
use App\Models\Product;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Upsert the physical-count row for one product on one date. The
 * unique index on (tenant_id, product_id, count_date) means a
 * second save overwrites the first — the keeper can correct a
 * miscount without ledger side-effects.
 *
 * `remark` is optional at the app boundary; if the caller passes
 * null the sheet renderer will auto-classify based on variance
 * against expected balance. Passing a non-null string overrides.
 */
class SavePhysicalCount
{
    public function handle(
        Product $product,
        Carbon $date,
        int $physicalQty,
        ?string $remark = null,
        ?string $note = null,
        ?Operator $operator = null,
    ): PhysicalCount {
        if ($physicalQty < 0) {
            throw new RuntimeException('Physical count cannot be negative.');
        }
        if (! $product->track_stock) {
            throw new RuntimeException(
                "Cannot record a physical count for '{$product->name}' — track_stock is off.",
            );
        }

        // Manual find-or-create — SQLite persists a `date`-cast column
        // as datetime, so updateOrCreate's WHERE with the raw date
        // string misses and tries a fresh INSERT that violates the
        // unique index. whereDate() compares the date part correctly.
        $existing = PhysicalCount::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)
            ->whereDate('count_date', $date)
            ->first();

        $payload = [
            'physical_qty' => $physicalQty,
            'remark'       => $remark !== '' ? $remark : null,
            'note'         => $note !== '' ? $note : null,
            'operator_id'  => $operator?->id,
        ];

        if ($existing !== null) {
            $existing->fill($payload)->save();

            return $existing->fresh();
        }

        return PhysicalCount::query()->create($payload + [
            'tenant_id'  => $product->tenant_id,
            'product_id' => $product->id,
            'count_date' => $date->toDateString(),
        ]);
    }
}
