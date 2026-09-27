<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Stores;

use App\Models\Store;
use App\Services\Inventory\StoreReconciliation as Snapshot;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Store reconciliation report — the property manager's headline
 * question in one screen. For the currently-selected branch's
 * store: what was received, what went out to each outlet, what did
 * each outlet sell, what's the variance?
 *
 * Scoped to the branch the operator has picked (PropertyContext).
 * Nothing renders when the branch's stock model is PerDepartment —
 * that mode doesn't have a central store to reconcile against.
 */
#[Layout('layouts.tenant-auth')]
class Reconciliation extends Component
{
    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    public function mount(): void
    {
        // Default window: last 7 days inclusive. Matches DailyStockSheet's
        // usual daily cadence — a manager typically checks the past
        // week for a "did anything walk off?" gut check.
        if ($this->from === '') $this->from = Carbon::today()->subDays(6)->toDateString();
        if ($this->to === '') $this->to = Carbon::today()->toDateString();
    }

    public function render(Snapshot $snap): View
    {
        $property = app(PropertyContext::class)->current();
        $store = $property !== null
            ? Store::query()->where('property_id', $property->getKey())->first()
            : null;

        try {
            $from = Carbon::parse($this->from);
            $to = Carbon::parse($this->to);
        } catch (\Throwable) {
            $from = Carbon::today()->subDays(6);
            $to = Carbon::today();
        }
        if ($to->lessThan($from)) [$from, $to] = [$to, $from];

        $data = $store !== null
            ? $snap->build($store, $from, $to)
            : null;

        return view('livewire.dashboard.stores.reconciliation', [
            'store' => $store,
            'property' => $property,
            'data' => $data,
            'centralStore' => $property?->stock_model?->usesCentralStore() ?? false,
        ]);
    }
}
