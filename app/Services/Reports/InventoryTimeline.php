<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\StockMovementReason;
use App\Models\Activity;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PhysicalCount;
use App\Models\Product;
use App\Models\StockMovement;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Merges Spatie's activity_log (Product / Outlet field changes) with the
 * stock_movements ledger (quantity changes) into a single timeline of
 * inventory "change entries", suitable for the Inventory > Change history
 * page and the property-wide Summary page.
 *
 * The two data sources have different shapes; this service normalises
 * them into a common entry array so the caller doesn't care where a row
 * came from. Sorted descending by `when_ts` (unix seconds), capped at
 * SOURCE_LIMIT rows per source before merge to keep the in-memory work
 * bounded on busy tenants.
 *
 * All queries respect tenant scoping (Activity has a global scope;
 * StockMovement uses BelongsToTenant), so a caller in a bound-tenant
 * request can't leak another tenant's rows.
 *
 * The `outletId` filter narrows both sources to a specific outlet:
 *  - Activity rows where subject IS that outlet, OR subject is a
 *    Product whose outlet_id matches
 *  - StockMovement rows where the underlying Product's outlet_id matches
 */
class InventoryTimeline
{
    public const int SOURCE_LIMIT = 500;

    /**
     * @param  list<string>  $kindFilter  {'price','cost','tax','stock','other'}
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $productId = null,
        ?int $outletId = null,
        ?int $operatorId = null,
        array $kindFilter = [],
        int $sourceLimit = self::SOURCE_LIMIT,
    ): Collection {
        return $this->activityEntries($from, $to, $outletId, $sourceLimit)
            ->concat($this->stockEntries($from, $to, $outletId, $sourceLimit))
            ->sortByDesc('when_ts')
            ->filter(fn (array $e): bool => $this->matchesFilters($e, $productId, $operatorId, $kindFilter))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function activityEntries(?Carbon $from, ?Carbon $to, ?int $outletId, int $sourceLimit): Collection
    {
        $query = Activity::query()
            ->whereIn('subject_type', [Product::class, Outlet::class])
            ->orderByDesc('created_at')
            ->limit($sourceLimit);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
        if ($outletId !== null) {
            // Either the change is on the outlet itself, or on a product
            // belonging to that outlet. Subquery keeps this readable and
            // lets Postgres / MySQL both plan it as an IN().
            $productIds = Product::query()
                ->where('outlet_id', $outletId)
                ->pluck('id');
            $query->where(function ($q) use ($outletId, $productIds): void {
                $q->where(function ($qq) use ($outletId): void {
                    $qq->where('subject_type', Outlet::class)
                        ->where('subject_id', $outletId);
                })->orWhere(function ($qq) use ($productIds): void {
                    $qq->where('subject_type', Product::class)
                        ->whereIn('subject_id', $productIds);
                });
            });
        }

        return $query->get()->map(function (Activity $a): array {
            $subject = $this->resolveSubject($a);
            $causer = $a->causer;
            $causerName = $causer instanceof Operator ? $causer->name : null;
            $causerId = $causer instanceof Operator ? (int) $causer->id : null;
            $properties = $a->properties ?? collect();
            $newAttrs = (array) ($properties['attributes'] ?? []);
            $oldAttrs = (array) ($properties['old'] ?? []);

            $fieldChanges = [];
            $kinds = [];
            foreach ($newAttrs as $field => $newValue) {
                $oldValue = $oldAttrs[$field] ?? null;
                $fieldChanges[] = [
                    'field' => (string) $field,
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
                $kinds[] = $this->classifyField((string) $field);
            }
            $kinds = array_values(array_unique($kinds));

            /** @var array<string, mixed> $entry */
            $entry = [
                'source' => 'activity',
                'when' => $a->created_at,
                'when_ts' => $a->created_at !== null ? (int) $a->created_at->timestamp : 0,
                'causer_name' => $causerName,
                'causer_id' => $causerId,
                'subject_type' => $a->subject_type === Product::class ? 'product' : 'outlet',
                'subject_id' => (int) $a->subject_id,
                'subject_label' => $subject['label'],
                'subject_missing' => $subject['missing'],
                'kinds' => $kinds,
                'event' => $a->event,
                'field_changes' => $fieldChanges,
                'summary' => $this->summariseFieldChanges($fieldChanges),
            ];

            return $entry;
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function stockEntries(?Carbon $from, ?Carbon $to, ?int $outletId, int $sourceLimit): Collection
    {
        $query = StockMovement::query()
            ->with(['product:id,name,outlet_id,is_rotational,qty_in_use,qty_at_laundry', 'operator:id,name'])
            ->orderByDesc('occurred_at')
            ->limit($sourceLimit);

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }
        if ($outletId !== null) {
            $query->whereHas('product', fn ($q) => $q->where('outlet_id', $outletId));
        }

        $movements = $query->get();

        // Enrich store-side Issued rows (delta < 0) with the paired
        // outlet-side balance + same-day physical count. Lets the store
        // keeper see, on one row: "we issued 60, outlet's book landed on
        // 60, but they counted 58" — the exact signal for a store↔outlet
        // gap. Batch-loaded so this stays two extra queries at most.
        $storeIssued = $movements->filter(
            fn (StockMovement $m): bool => $m->reason === StockMovementReason::Issued
                && (int) $m->delta < 0
                && $m->source_id !== null,
        );
        $pairIds = $storeIssued->pluck('source_id')->filter()->unique()->all();
        $pairs = $pairIds !== []
            ? StockMovement::query()
                ->whereIn('id', $pairIds)
                ->with(['product:id,name,outlet_id', 'product.outlet:id,name'])
                ->get()
                ->keyBy('id')
            : collect();

        // Same-day physical count on the outlet product, keyed
        // "{product_id}|{yyyy-mm-dd}". Whole-day comparison via whereDate
        // so a date-cast column stored as datetime still matches.
        $countLookup = collect();
        $countPairs = [];
        foreach ($storeIssued as $m) {
            $pair = $pairs->get($m->source_id);
            if ($pair !== null && $pair->product_id !== null && $m->occurred_at !== null) {
                $countPairs[] = [
                    'pid' => (int) $pair->product_id,
                    'date' => $m->occurred_at->toDateString(),
                ];
            }
        }
        if ($countPairs !== []) {
            $pids = array_values(array_unique(array_column($countPairs, 'pid')));
            $dates = array_values(array_unique(array_column($countPairs, 'date')));
            // SQLite (and the date cast on PhysicalCount) stores the
            // column as datetime, so `whereIn('count_date', $dates)`
            // silently misses. whereDate() strips the time portion for
            // the comparison and matches correctly on both drivers.
            $counts = PhysicalCount::query()
                ->whereIn('product_id', $pids)
                ->where(function ($q) use ($dates): void {
                    foreach ($dates as $d) {
                        $q->orWhereDate('count_date', $d);
                    }
                })
                ->get(['product_id', 'count_date', 'physical_qty']);
            $countLookup = $counts->keyBy(
                fn (PhysicalCount $c): string => $c->product_id.'|'.$c->count_date->toDateString(),
            );
        }

        return $movements->map(function (StockMovement $m) use ($pairs, $countLookup): array {
            $delta = (int) $m->delta;
            $sign = $delta >= 0 ? '+' : '−';
            $magnitude = abs($delta);
            $reason = $m->reason instanceof StockMovementReason ? $m->reason->label() : (string) $m->reason;
            $operator = $m->operator;
            $product = $m->product;

            // Paired outlet balance for store-side Issued rows only.
            // Outlet-side rows (delta > 0) already show the receiving
            // product's own quantity_after in the Balance column, so
            // a chip would just repeat information.
            $paired = null;
            if ($m->reason === StockMovementReason::Issued
                && $delta < 0
                && $m->source_id !== null
            ) {
                $pair = $pairs->get($m->source_id);
                if ($pair !== null && $pair->product !== null) {
                    $countKey = $pair->product_id.'|'.($m->occurred_at?->toDateString() ?? '');
                    $count = $countLookup->get($countKey);
                    $paired = [
                        'outlet_name'          => $pair->product->outlet?->name,
                        'outlet_balance_after' => (int) $pair->quantity_after,
                        'outlet_counted'       => $count?->physical_qty,
                    ];
                }
            }

            // Rotational bucket snapshot — for durables (bedsheets,
            // towels) the running "clean stock" alone under-reports
            // total holdings; the in-use + at-laundry buckets carry
            // the rest of the count. Pulled from the product row's
            // materialised counters (updated by SendToLaundry /
            // ReceiveFromLaundry / ApplyHousekeepingTurnover).
            $rotational = null;
            if ($product instanceof Product && $product->is_rotational) {
                $rotational = [
                    'in_use'     => (int) $product->qty_in_use,
                    'at_laundry' => (int) $product->qty_at_laundry,
                ];
            }

            /** @var array<string, mixed> $entry */
            $entry = [
                'source' => 'stock',
                'when' => $m->occurred_at,
                'when_ts' => $m->occurred_at !== null ? (int) $m->occurred_at->timestamp : 0,
                'causer_name' => $operator instanceof Operator ? $operator->name : null,
                'causer_id' => $operator instanceof Operator ? (int) $operator->id : null,
                'subject_type' => 'product',
                'subject_id' => (int) $m->product_id,
                'subject_label' => $product instanceof Product ? $product->name : '(deleted product)',
                'subject_missing' => ! $product instanceof Product,
                'kinds' => ['stock'],
                'event' => 'stock_'.strtolower($reason),
                'field_changes' => [],
                'summary' => "{$sign}{$magnitude} · {$reason}".($m->note ? " · {$m->note}" : ''),
                'balance_after' => (int) $m->quantity_after,
                'paired' => $paired,
                'rotational' => $rotational,
            ];

            return $entry;
        });
    }

    /**
     * @return array{label: string, missing: bool}
     */
    private function resolveSubject(Activity $a): array
    {
        if ($a->subject_type === Product::class) {
            $p = Product::query()
                ->withoutGlobalScope(TenantScope::class)
                ->find($a->subject_id);

            return ['label' => $p instanceof Product ? $p->name : '(deleted product)', 'missing' => ! $p instanceof Product];
        }
        if ($a->subject_type === Outlet::class) {
            $o = Outlet::query()
                ->withoutGlobalScope(TenantScope::class)
                ->find($a->subject_id);

            return ['label' => $o instanceof Outlet ? $o->name : '(deleted outlet)', 'missing' => ! $o instanceof Outlet];
        }

        return ['label' => '(unknown)', 'missing' => true];
    }

    private function classifyField(string $field): string
    {
        return match ($field) {
            'price' => 'price',
            'cost' => 'cost',
            'tax_rate' => 'tax',
            default => 'other',
        };
    }

    /**
     * @param  list<array{field: string, old: mixed, new: mixed}>  $changes
     */
    private function summariseFieldChanges(array $changes): string
    {
        if ($changes === []) {
            return 'Updated';
        }
        $parts = [];
        foreach ($changes as $c) {
            $label = $this->fieldLabel($c['field']);
            $old = $this->formatFieldValue($c['field'], $c['old']);
            $new = $this->formatFieldValue($c['field'], $c['new']);
            $parts[] = "{$label} {$old} → {$new}";
        }

        return implode(' · ', $parts);
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'price' => 'Price',
            'cost' => 'Cost',
            'tax_rate' => 'Tax',
            'name' => 'Name',
            'sku' => 'SKU',
            'barcode' => 'Barcode',
            'active' => 'Active',
            'track_stock' => 'Track stock',
            'low_stock_threshold' => 'Low-stock threshold',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    private function formatFieldValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($field, ['price', 'cost'], true)) {
            return '₦'.number_format((int) $value);
        }
        if ($field === 'tax_rate') {
            return rtrim(rtrim((string) $value, '0'), '.').'%';
        }
        if (in_array($field, ['active', 'track_stock'], true)) {
            return $value ? 'on' : 'off';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[…]';
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $kindFilter
     */
    private function matchesFilters(array $entry, ?int $productId, ?int $operatorId, array $kindFilter): bool
    {
        if ($productId !== null) {
            // A product filter hides outlet rows.
            if ($entry['subject_type'] !== 'product' || (int) $entry['subject_id'] !== $productId) {
                return false;
            }
        }
        if ($operatorId !== null && (int) ($entry['causer_id'] ?? 0) !== $operatorId) {
            return false;
        }
        if ($kindFilter !== []) {
            $entryKinds = $entry['kinds'] ?? [];
            if (array_intersect($kindFilter, $entryKinds) === []) {
                return false;
            }
        }

        return true;
    }
}
