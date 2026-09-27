<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Inventory;

use App\Models\Outlet;
use App\Services\Inventory\DailyStockSheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Outlet daily stock book, pivoted matrix: dates rows × products
 * columns, each product with 5 sub-cells (Opening / New / Total /
 * Sold / Closing). Auto-refreshes every 15s so POS sales bump the
 * Sold cell for today without a manual reload.
 *
 * Closing is derived from the ledger (Opening + New − Sold). Outlets
 * don't enter physical counts on the daily view, variance tracking
 * is a store-keeper concern handled elsewhere.
 */
#[Layout('layouts.tenant-auth')]
class Sheet extends Component
{
    public Outlet $outlet;

    /** How many days of history to show, ending today. */
    #[Url(as: 'days', except: 14)]
    public int $daysBack = 14;

    public function mount(Outlet $outlet): void
    {
        $this->outlet = $outlet;
        if ($this->daysBack < 1) {
            $this->daysBack = 14;
        }
        if ($this->daysBack > 60) {
            $this->daysBack = 60;
        }
    }

    public function extendWindow(int $moreDays): void
    {
        $this->daysBack = min(60, $this->daysBack + $moreDays);
    }

    public function render(DailyStockSheet $sheet): View
    {
        $today = Carbon::today();
        $from = $today->copy()->subDays($this->daysBack - 1);

        $matrix = $sheet->buildMatrix($this->outlet->id, $from, $today);

        return view('livewire.dashboard.inventory.sheet', [
            'outlet'   => $this->outlet,
            'products' => $matrix['products'],
            'dates'    => $matrix['dates'],
            'cells'    => $matrix['cells'],
            'today'    => $today->toDateString(),
        ]);
    }
}
