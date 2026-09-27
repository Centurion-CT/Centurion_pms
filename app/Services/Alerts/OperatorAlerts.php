<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\StockRequestStatus;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockRequest;
use App\Services\Properties\PropertyContext;
use Illuminate\Support\Collection;

/**
 * "To whom it concerns" alert routing for the dashboard bell + card.
 *
 * Every alert type carries a role-gate so a HouseCleaner doesn't hear a
 * bar keeper's inventory low-stock chime, and a StockKeeper does hear a
 * fresh outlet requisition even though it doesn't live in their sidebar
 * badge. Two entry points:
 *
 *   items(Operator)   flat list of alert items keyed by kind, ready to
 *                     render in the bell dropdown / dashboard card.
 *   counts(Operator)  aggregate {store_request: N, low_stock: N} used
 *                     to drive the bell red-dot + dashboard tile.
 *
 * All queries scope to the operator's current branch via PropertyContext
 * — a Lagos-branch stock keeper doesn't get pinged for Abuja's requests.
 */
class OperatorAlerts
{
    /**
     * @return array{store_request: int, low_stock: int}
     */
    public function counts(Operator $operator): array
    {
        return [
            'store_request' => $this->storeRequestCount($operator),
            'low_stock'     => $this->lowStockCount($operator),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function items(Operator $operator): Collection
    {
        return collect()
            ->concat($this->storeRequestItems($operator))
            ->concat($this->lowStockItems($operator))
            ->sortByDesc(fn (array $i): int => (int) ($i['at']?->timestamp ?? 0))
            ->values();
    }

    /* ── Store requests, Manager + StockKeeper only ─────────────── */

    private function storeRequestCount(Operator $operator): int
    {
        if (! $this->canAttendStoreRequests($operator)) {
            return 0;
        }
        $propertyId = $this->currentPropertyId();

        return (int) StockRequest::query()
            ->where('status', StockRequestStatus::Pending)
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('outlet', fn ($o) => $o->where('property_id', $pid)))
            ->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function storeRequestItems(Operator $operator): Collection
    {
        if (! $this->canAttendStoreRequests($operator)) {
            return collect();
        }
        $propertyId = $this->currentPropertyId();

        return StockRequest::query()
            ->where('status', StockRequestStatus::Pending)
            ->with(['outlet:id,name,property_id', 'product:id,name', 'requestedBy:id,name'])
            ->when($propertyId, fn ($q, $pid) => $q->whereHas('outlet', fn ($o) => $o->where('property_id', $pid)))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (StockRequest $r): array => [
                'kind'       => 'store_request',
                'kind_label' => 'Store request',
                'title'      => $r->product?->name ?? 'Product',
                'subtitle'   => trim(($r->outlet?->name ?? 'Outlet').' · '.$r->quantity.' unit'.($r->quantity === 1 ? '' : 's').($r->requestedBy ? ' · by '.$r->requestedBy->name : '')),
                'at'         => $r->created_at,
                'url'        => route('tenant.store.index'),
            ]);
    }

    /* ── Low stock, split by attendable-outlet type ─────────────── */

    private function lowStockCount(Operator $operator): int
    {
        $outletIds = $this->attendableOutletIds($operator);
        if ($outletIds === []) {
            return 0;
        }

        return (int) Product::query()
            ->whereIn('outlet_id', $outletIds)
            ->where('active', true)
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
            ->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lowStockItems(Operator $operator): Collection
    {
        $outletIds = $this->attendableOutletIds($operator);
        if ($outletIds === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('outlet_id', $outletIds)
            ->where('active', true)
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
            ->with(['outlet:id,name,type'])
            ->orderBy('quantity_on_hand')
            ->limit(10)
            ->get()
            ->map(fn (Product $p): array => [
                'kind'       => 'low_stock',
                'kind_label' => 'Low stock',
                'title'      => $p->name,
                'subtitle'   => ($p->outlet?->name ?? 'Outlet').' · '.$p->quantity_on_hand.' left (threshold '.$p->low_stock_threshold.')',
                // Housekeeping SKUs point at the Supplies page, everything
                // else points at Inventory. Puts the operator one click
                // away from doing something about it.
                'at'         => $p->updated_at,
                'url'        => $p->outlet?->type === OutletType::Housekeeping
                    ? route('tenant.supplies.show', $p->outlet_id)
                    : route('tenant.inventory.outlets.show', $p->outlet_id),
            ]);
    }

    /* ── Role gates ─────────────────────────────────────────────── */

    private function canAttendStoreRequests(Operator $operator): bool
    {
        $role = $operator->role ?? OperatorRole::Manager;

        return in_array($role, [OperatorRole::Manager, OperatorRole::StockKeeper], true);
    }

    /**
     * IDs of outlets on the current branch whose low-stock alerts this
     * operator should hear. Housekeeping supplies fan out widely
     * (cleaners, front desk, supervisors all care); bar/kitchen/laundry
     * are the inventory-admin family.
     *
     * @return list<int>
     */
    private function attendableOutletIds(Operator $operator): array
    {
        $role = $operator->role ?? OperatorRole::Manager;
        $propertyId = $this->currentPropertyId();

        $seesHousekeeping = in_array($role, [
            OperatorRole::Manager,
            OperatorRole::Frontdesk,
            OperatorRole::HouseCleaner,
            OperatorRole::Porter,
            OperatorRole::Supervisor,
            OperatorRole::InventoryClerk,
            OperatorRole::StockKeeper,
        ], true);

        $seesInventory = in_array($role, [
            OperatorRole::Manager,
            OperatorRole::InventoryClerk,
            OperatorRole::StockKeeper,
        ], true);

        if (! $seesHousekeeping && ! $seesInventory) {
            return [];
        }

        $query = Outlet::query()
            ->where('active', true)
            ->when($propertyId, fn ($q, $pid) => $q->where('property_id', $pid));

        if ($seesHousekeeping && ! $seesInventory) {
            $query->where('type', OutletType::Housekeeping);
        } elseif ($seesInventory && ! $seesHousekeeping) {
            $query->where('type', '!=', OutletType::Housekeeping);
        }
        // else: sees both, no additional filter

        return $query->pluck('id')->all();
    }

    private function currentPropertyId(): ?int
    {
        return app(PropertyContext::class)->current()?->getKey();
    }
}
