<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Enums\OperatorRole;
use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\Outlet;
use App\Models\OutletShift;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Shift ledger: every open + closed shift the operator can see.
 *
 * Access:
 *   - Manager: every shift at every outlet in their branch.
 *   - PosStaff: only their own shifts.
 *
 * Filters mirror the Sales page — outlet, status (open/closed), date
 * range — so operators reach for familiar controls.
 */
class Shifts extends Component
{
    use UsesPosKioskLayout;
    use WithPagination;

    #[Url(as: 'outlet')]
    public string $outletFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';  // '' | 'open' | 'closed'

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['outletFilter', 'statusFilter', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $operator = auth('web')->user();
        $isManager = $operator?->role === OperatorRole::Manager;

        $availableOutlets = $isManager
            ? Outlet::query()->active()->orderBy('name')->get(['id', 'name'])
            : $operator->outlets()->where('active', true)->orderBy('name')->get(['outlets.id', 'name']);

        $query = OutletShift::query()
            ->with([
                'outlet:id,name,type',
                'operator:id,name',
                'closedBy:id,name',
            ])
            ->orderByDesc('opened_at');

        // Access scope: PosStaff sees only their own shifts. Managers
        // see every shift on outlets in their branch (via availableOutlets).
        if (! $isManager) {
            $query->where('operator_id', $operator->id);
        } else {
            $query->whereIn('outlet_id', $availableOutlets->pluck('id'));
        }

        if ($this->outletFilter !== '') {
            $query->where('outlet_id', (int) $this->outletFilter);
        }
        if ($this->statusFilter === 'open') {
            $query->whereNull('closed_at');
        } elseif ($this->statusFilter === 'closed') {
            $query->whereNotNull('closed_at');
        }
        if ($this->dateFrom !== '') {
            $query->whereDate('opened_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '') {
            $query->whereDate('opened_at', '<=', $this->dateTo);
        }

        /** @var LengthAwarePaginator<int, OutletShift> $shifts */
        $shifts = $query->paginate(25);

        return $this->applyKioskLayout(view('livewire.dashboard.pos.shifts', [
            'shifts' => $shifts,
            'availableOutlets' => $availableOutlets,
            'isManager' => $isManager,
        ]));
    }
}
