<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\RentSchedule;

use App\Enums\LeasePaymentStatus;
use App\Models\LeasePayment;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'when')]
    public string $whenFilter = 'open';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingWhenFilter(): void
    {
        $this->resetPage();
    }

    public function markPaid(int $id): void
    {
        $p = LeasePayment::query()->find($id);
        if ($p === null) {
            return;
        }
        $p->status = LeasePaymentStatus::Paid;
        $p->paid_at = now();
        $p->save();
        session()->flash('status', 'Payment recorded.');
    }

    public function waive(int $id): void
    {
        $p = LeasePayment::query()->find($id);
        if ($p === null) {
            return;
        }
        $p->status = LeasePaymentStatus::Waived;
        $p->save();
        session()->flash('status', 'Payment waived.');
    }

    public function render(): View
    {
        // Mark any open payment whose due_at is in the past as Overdue.
        // Lightweight on-demand reconciliation so the UI always reflects
        // the truth without needing a scheduled job.
        LeasePayment::query()
            ->whereIn('status', [LeasePaymentStatus::Upcoming->value, LeasePaymentStatus::Due->value])
            ->where('due_at', '<', now()->startOfDay())
            ->update(['status' => LeasePaymentStatus::Overdue->value]);
        LeasePayment::query()
            ->where('status', LeasePaymentStatus::Upcoming->value)
            ->whereBetween('due_at', [now()->startOfDay(), now()->addDays(7)])
            ->update(['status' => LeasePaymentStatus::Due->value]);

        return view('livewire.dashboard.rent-schedule.index', [
            'payments' => $this->payments(),
            'statuses' => LeasePaymentStatus::cases(),
            'stats'    => $this->stats(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, LeasePayment>
     */
    private function payments(): LengthAwarePaginator
    {
        $q = LeasePayment::query()
            ->with(['lease.customer', 'lease.unit'])
            ->orderBy('due_at');

        if ($this->whenFilter === 'open') {
            $q->whereIn('status', [
                LeasePaymentStatus::Upcoming->value,
                LeasePaymentStatus::Due->value,
                LeasePaymentStatus::Overdue->value,
            ]);
        } elseif ($this->whenFilter === 'paid') {
            $q->where('status', LeasePaymentStatus::Paid->value);
        }

        if ($this->statusFilter !== '') {
            $q->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $term = $this->search;
            $q->whereHas('lease', function ($lq) use ($term): void {
                $lq->where('reference', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', function ($cq) use ($term): void {
                        $cq->where('name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%')
                            ->orWhere('phone', 'like', '%'.$term.'%');
                    })
                    ->orWhereHas('unit', function ($uq) use ($term): void {
                        $uq->where('name', 'like', '%'.$term.'%');
                    });
            });
        }

        return $q->paginate(20);
    }

    /**
     * @return array<string,int>
     */
    private function stats(): array
    {
        $overdue = (int) LeasePayment::query()
            ->where('status', LeasePaymentStatus::Overdue->value)
            ->sum('amount_naira');
        $dueSoon = (int) LeasePayment::query()
            ->whereIn('status', [LeasePaymentStatus::Due->value, LeasePaymentStatus::Upcoming->value])
            ->whereBetween('due_at', [now(), now()->addDays(30)])
            ->sum('amount_naira');
        $collectedYtd = (int) LeasePayment::query()
            ->where('status', LeasePaymentStatus::Paid->value)
            ->whereYear('paid_at', now()->year)
            ->sum('amount_naira');

        return [
            'overdue'       => $overdue,
            'due_soon'      => $dueSoon,
            'collected_ytd' => $collectedYtd,
        ];
    }
}
