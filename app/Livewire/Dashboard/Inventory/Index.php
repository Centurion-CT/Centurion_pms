<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Landing page for the Inventory module. Lists outlets, headline
 * counters, and quick links into each outlet's catalog.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public function render(): View
    {
        $outlets = Outlet::query()
            ->withCount(['products' => fn ($q) => $q->where('active', true)])
            ->orderBy('name')
            ->get();

        $totalProducts = (int) Product::query()->where('active', true)->count();
        $lowStock = (int) Product::query()
            ->where('active', true)
            ->where('track_stock', true)
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')
            ->count();

        $op = auth('web')->user();
        $role = ($op instanceof \App\Models\Operator ? $op->role : null) ?? OperatorRole::Manager;

        return view('livewire.dashboard.inventory.index', [
            'outlets' => $outlets,
            'totalOutlets' => $outlets->count(),
            'totalProducts' => $totalProducts,
            'lowStock' => $lowStock,
            'outletTypes' => OutletType::cases(),
            'canManagePrices' => $role->canManagePrices(),
        ]);
    }
}
