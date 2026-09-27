<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Corporates;

use App\Enums\CorporateKycStatus;
use App\Enums\CorporateStatus;
use App\Models\Corporate;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('admin.layout')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'kyc')]
    public string $kycFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedKycFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.corporates.index', [
            'corporates'    => $this->paginate(),
            'statusOptions' => CorporateStatus::cases(),
            'kycOptions'    => CorporateKycStatus::cases(),
            'stats'         => $this->stats(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Corporate>
     */
    private function paginate(): LengthAwarePaginator
    {
        $q = Corporate::query()->with('wallet')->latest('id');
        $needle = trim($this->search);
        if ($needle !== '') {
            $q->where(function ($qq) use ($needle): void {
                $qq->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('slug', 'like', '%'.$needle.'%')
                    ->orWhere('primary_email', 'like', '%'.$needle.'%');
            });
        }
        if ($this->statusFilter !== '' && CorporateStatus::tryFrom($this->statusFilter) !== null) {
            $q->where('status', $this->statusFilter);
        }
        if ($this->kycFilter !== '' && CorporateKycStatus::tryFrom($this->kycFilter) !== null) {
            $q->where('kyc_status', $this->kycFilter);
        }

        return $q->paginate(20);
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        return [
            'active'    => Corporate::query()->where('status', CorporateStatus::Active->value)->count(),
            'onboarding'=> Corporate::query()->where('status', CorporateStatus::Onboarding->value)->count(),
            'suspended' => Corporate::query()->where('status', CorporateStatus::Suspended->value)->count(),
            'float'     => (int) \App\Models\CorporateWallet::query()->sum('balance_naira'),
        ];
    }
}
