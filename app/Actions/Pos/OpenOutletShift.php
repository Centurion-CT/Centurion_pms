<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Models\Operator;
use App\Models\Outlet;
use App\Models\OutletShift;
use App\Models\OutletShiftStockSnapshot;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Open a shift for the operator at the outlet. Snapshots the current
 * quantity_on_hand for every tracked product so the closing view can
 * compute opening → sold → closing side-by-side.
 *
 * Guardrails:
 *   - Refuses to open a second shift for the same (outlet, operator)
 *     while one is still open.
 *   - Refuses if there is ANY other open shift on the outlet by another
 *     operator — the outlet has a single till at a time. Force-close
 *     the existing shift first (via CloseOutletShift with a reason).
 */
class OpenOutletShift
{
    public function handle(Outlet $outlet, Operator $operator): OutletShift
    {
        return DB::transaction(function () use ($outlet, $operator): OutletShift {
            $existing = OutletShift::query()
                ->where('outlet_id', $outlet->id)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->operator_id === $operator->id) {
                    throw new RuntimeException("You already have an open shift on {$outlet->name}. Close it before opening a new one.");
                }
                throw new RuntimeException(
                    "{$existing->operator?->name} still has an open shift on {$outlet->name}. Ask a manager to close it before opening a new one."
                );
            }

            $now = Carbon::now();

            $shift = OutletShift::query()->create([
                'tenant_id' => $outlet->tenant_id,
                'outlet_id' => $outlet->id,
                'operator_id' => $operator->id,
                'opened_at' => $now,
            ]);

            $this->snapshotStock($shift, kind: 'opening', capturedAt: $now);

            activity('outlet_shift')
                ->causedBy($operator)
                ->performedOn($shift)
                ->event('shift_opened')
                ->withProperties([
                    'outlet' => $outlet->name,
                    'opened_at' => $now->toIso8601String(),
                ])
                ->log("Operator {$operator->name} opened a shift on {$outlet->name}.");

            return $shift->fresh(['snapshots']);
        });
    }

    /**
     * Snapshot every stock-tracked product in the outlet. Runs the same
     * way for opening + closing so CloseOutletShift can call it too.
     */
    public function snapshotStock(OutletShift $shift, string $kind, Carbon $capturedAt): void
    {
        $products = Product::query()
            ->where('outlet_id', $shift->outlet_id)
            ->where('track_stock', true)
            ->get(['id', 'name', 'quantity_on_hand', 'price']);

        $rows = $products->map(fn (Product $p) => [
            'tenant_id' => $shift->tenant_id,
            'outlet_shift_id' => $shift->id,
            'product_id' => $p->id,
            'kind' => $kind,
            'quantity_on_hand' => (int) $p->quantity_on_hand,
            'product_name' => $p->name,
            'unit_price' => (int) $p->price,
            'captured_at' => $capturedAt,
            'created_at' => $capturedAt,
            'updated_at' => $capturedAt,
        ])->all();

        if ($rows === []) {
            return;
        }
        OutletShiftStockSnapshot::query()->insert($rows);
    }
}
