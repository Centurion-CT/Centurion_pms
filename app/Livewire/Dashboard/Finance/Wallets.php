<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Livewire\Dashboard\Customers\Wallet;
use App\Models\CustomerWallet;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Property-wide roster of every guest wallet, the "who's holding
 * money?" view Finance opens when reconciling deposits on file.
 *
 * Each row drills into the per-customer wallet tab
 * ({@see Wallet}) for the ledger +
 * top-up / refund controls.
 */
#[Layout('layouts.tenant-auth')]
class Wallets extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** funded = balance > 0 (default). all = every wallet ever created. */
    #[Url(as: 'filter', except: 'funded')]
    public string $balanceFilter = 'funded';

    #[Url(as: 'sort', except: 'balance_desc')]
    public string $sort = 'balance_desc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBalanceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = CustomerWallet::query()
            ->with('customer')
            ->join('customers', 'customers.id', '=', 'customer_wallets.customer_id')
            ->whereNull('customers.deleted_at')
            ->select('customer_wallets.*');

        if ($this->balanceFilter === 'funded') {
            $query->where('customer_wallets.balance_naira', '>', 0);
        }

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('customers.name', 'like', $term)
                    ->orWhere('customers.email', 'like', $term)
                    ->orWhere('customers.phone', 'like', $term);
            });
        }

        match ($this->sort) {
            'balance_asc' => $query->orderBy('customer_wallets.balance_naira'),
            'name_asc' => $query->orderBy('customers.name'),
            'recent' => $query->orderByDesc('customer_wallets.updated_at'),
            default => $query->orderByDesc('customer_wallets.balance_naira'),
        };

        // Aggregate stats, computed with the balance filter respected
        // so the KPI reflects what the roster shows.
        $baseAgg = CustomerWallet::query();
        $totalOnDeposit = (int) $baseAgg->clone()->where('balance_naira', '>', 0)->sum('balance_naira');
        $fundedCount = (int) $baseAgg->clone()->where('balance_naira', '>', 0)->count();
        $totalWallets = (int) $baseAgg->clone()->count();

        return view('livewire.dashboard.finance.wallets', [
            'wallets' => $query->paginate(25),
            'totalOnDeposit' => $totalOnDeposit,
            'fundedCount' => $fundedCount,
            'totalWallets' => $totalWallets,
        ]);
    }
}
