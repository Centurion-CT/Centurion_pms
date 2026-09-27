<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Enums\OperatorRole;
use App\Enums\PosSaleStatus;
use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Recent-sales list. Manager sees every outlet's sales; PosStaff
 * only their assigned outlets. Filters by outlet, status, date.
 */
// Layout chosen dynamically via UsesPosKioskLayout, see Terminal.php.
class Sales extends Component
{
    use UsesPosKioskLayout;
    use WithPagination;

    #[Url(as: 'outlet')]
    public string $outletFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function updated(string $property): void { $this->resetPage(); }

    public function render(): View
    {
        $operator = auth('web')->user();
        $isManager = $operator?->role === OperatorRole::Manager;

        // Scope the outlet list to the operator's current branch —
        // Outlet has property_id, so a multi-branch org's switcher
        // filters both the dropdown AND the sale rows below (sales
        // inherit the filter via whereIn('outlet_id', $availableOutlets)).
        $propertyId = app(PropertyContext::class)->current()?->getKey();

        $availableOutlets = $isManager
            ? Outlet::query()->active()
                ->when($propertyId, fn ($q, $pid) => $q->where('property_id', $pid))
                ->orderBy('name')->get(['id', 'name'])
            : $operator->outlets()->where('active', true)
                ->when($propertyId, fn ($q, $pid) => $q->where('outlets.property_id', $pid))
                ->orderBy('name')->get(['outlets.id', 'name']);

        $query = PosSale::query()
            ->with([
                'outlet:id,name',
                'operator:id,name',
                'booking:id,booking_ref,guest_name',
                'customer:id,name,phone',
                'complimentaryBy:id,name',
                // Needed so the ledger row can show the give-away
                // value on complimentary sales (sale.subtotal is
                // floored to 0 in the comp path).
                'lines:id,pos_sale_id,subtotal',
            ])
            ->orderByDesc('id');

        // Managers usually see every outlet's sales; multi-branch orgs
        // still need to bucket by branch so a Lagos manager doesn't see
        // Abuja's till. Non-managers already get the whereIn constraint
        // just below via availableOutlets which is now branch-scoped.
        if ($isManager && $propertyId !== null) {
            $query->whereHas('outlet', fn ($o) => $o->where('property_id', $propertyId));
        }

        // Scope to assigned outlets for non-managers.
        if (! $isManager) {
            $query->whereIn('outlet_id', $availableOutlets->pluck('id'));
        }

        if ($this->outletFilter !== '') {
            $query->where('outlet_id', $this->outletFilter);
        }
        if ($this->statusFilter !== '' && PosSaleStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }
        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhereHas('booking', fn ($b) => $b->where('booking_ref', 'like', $like)->orWhere('guest_name', 'like', $like));
            });
        }

        /** @var LengthAwarePaginator<int, PosSale> $sales */
        $sales = $query->paginate(25);

        return $this->applyKioskLayout(view('livewire.dashboard.pos.sales', [
            'sales'            => $sales,
            'availableOutlets' => $availableOutlets,
            'statuses'         => PosSaleStatus::cases(),
        ]));
    }
}
