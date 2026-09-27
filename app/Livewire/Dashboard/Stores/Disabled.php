<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Stores;

use App\Enums\StockModel;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Central store is turned off" landing for the /dashboard/store family.
 *
 * The store surfaces are gated by {@see \App\Http\Middleware\EnsureCentralStoreMode},
 * which used to 404 the whole family in per_department mode. That left
 * operators stranded when they hit a bookmark, old link, or typed the
 * URL directly. The middleware now redirects here instead, so the
 * chrome stays intact and there's a one-click path to enable it.
 */
#[Layout('layouts.tenant-auth')]
class Disabled extends Component
{
    public function render(): View
    {
        $property = Tenant::current()?->property;

        return view('livewire.dashboard.stores.disabled', [
            'property' => $property,
            'currentMode' => $property?->stock_model ?? StockModel::PerDepartment,
        ]);
    }
}
