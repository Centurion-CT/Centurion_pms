<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tenant-wide invoices list. Filters live in URL state so staff can
 * bookmark "unpaid Q3" or "voided last month". BelongsToTenant scope
 * keeps us in-tenant automatically, no explicit tenant_id where.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function render(): View
    {
        $query = Invoice::query()
            ->with(['booking:id,booking_ref,guest_name'])
            ->orderByDesc('id');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->dateFrom !== '') {
            $query->whereDate('issued_at', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '') {
            $query->whereDate('issued_at', '<=', $this->dateTo);
        }
        if ($this->search !== '') {
            $needle = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('number', 'like', $needle)
                    ->orWhere('guest_name', 'like', $needle)
                    ->orWhere('guest_email', 'like', $needle)
                    ->orWhereHas('booking', fn ($b) => $b->where('booking_ref', 'like', $needle));
            });
        }

        /** @var LengthAwarePaginator $invoices */
        $invoices = $query->paginate(25);

        $outstanding = (int) Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Partial->value])
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as bal')
            ->value('bal');

        $paidMtd = (int) Invoice::query()
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount_paid');

        $overdue = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Partial->value])
            ->whereNotNull('issued_at')
            ->whereDate('issued_at', '<', now()->subDays(14)->toDateString())
            ->count();

        return view('livewire.dashboard.invoices.index', [
            'invoices'     => $invoices,
            'statuses'     => InvoiceStatus::cases(),
            'outstanding'  => $outstanding,
            'paidMtd'      => $paidMtd,
            'overdueCount' => $overdue,
        ])->layout('layouts.tenant-auth');
    }
}
