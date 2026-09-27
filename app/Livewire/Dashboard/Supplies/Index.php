<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Supplies;

use App\Enums\OutletType;
use App\Enums\StockModel;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Supplies landing — one card per Housekeeping-type outlet with
 * on-hand + low-stock + pending-request signal. Aimed at Front desk,
 * House cleaners, Porters, and Housekeeping supervisors: they need
 * to see "what's left" without touching the full inventory admin
 * surface, so this page is read-oriented, drill-through to the
 * SuppliesShow detail for a specific outlet.
 *
 * Managers, Inventory clerks, and Stock keepers also carry `supplies`
 * on their module list; they get the same landing so they don't have
 * to hop between screens to eyeball housekeeping state.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public function render(): View
    {
        $outlets = Outlet::query()
            ->where('type', OutletType::Housekeeping)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $outletIds = $outlets->pluck('id')->all();

        // Aggregate signals per outlet, done in three cheap queries
        // instead of N+1 through the collection.
        $productCounts = Product::query()
            ->whereIn('outlet_id', $outletIds)
            ->where('active', true)
            ->selectRaw('outlet_id, count(*) as total, sum(case when low_stock_threshold > 0 and quantity_on_hand <= low_stock_threshold then 1 else 0 end) as low')
            ->groupBy('outlet_id')
            ->get()
            ->keyBy('outlet_id');

        // Named list of the low SKUs per outlet, so the card can
        // read "Bedsheet, Bath Towel low" instead of just "2 low".
        // Cheaper than one query per card because we scope by
        // outletIds up front, cap the read, and group in PHP.
        $lowNames = collect();
        if ($outletIds !== []) {
            $lowNames = Product::query()
                ->whereIn('outlet_id', $outletIds)
                ->where('active', true)
                ->where('low_stock_threshold', '>', 0)
                ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
                ->orderBy('quantity_on_hand')
                ->limit(50)
                ->get(['outlet_id', 'name'])
                ->groupBy('outlet_id');
        }

        $pendingByOutlet = StockRequest::query()
            ->whereIn('outlet_id', $outletIds)
            ->pending()
            ->selectRaw('outlet_id, count(*) as pending')
            ->groupBy('outlet_id')
            ->get()
            ->keyBy('outlet_id');

        $summaries = $outlets->map(function (Outlet $o) use ($productCounts, $pendingByOutlet, $lowNames): array {
            $stats = $productCounts->get($o->id);
            $pending = $pendingByOutlet->get($o->id);
            $lows = $lowNames->get($o->id, collect());

            return [
                'outlet'      => $o,
                'total'       => (int) ($stats->total ?? 0),
                'low'         => (int) ($stats->low ?? 0),
                'low_names'   => $lows->pluck('name')->take(3)->all(),
                'low_extra'   => max(0, $lows->count() - 3),
                'pending'     => (int) ($pending->pending ?? 0),
            ];
        });

        // Per-department properties can't file requisitions, so the
        // "N pending" chip on each card is dead data; suppress it.
        $usesCentralStore = (Tenant::current()?->property?->stock_model
            ?? StockModel::StoreAndRequisition)->usesCentralStore();

        return view('livewire.dashboard.supplies.index', [
            'summaries'        => $summaries,
            'usesCentralStore' => $usesCentralStore,
        ]);
    }
}
