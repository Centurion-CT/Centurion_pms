<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Customers;

use App\Enums\CustomerLifecycle;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * CRM list view. Supports free-text search across name/email/phone,
 * lifecycle filter, tag filter, VIP toggle, and sort.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'lifecycle', except: '')]
    public string $lifecycleFilter = '';

    #[Url(as: 'tag', except: '')]
    public string $tagFilter = '';

    #[Url(as: 'vip', except: '')]
    public string $vipFilter = '';

    /** '1' to show only customers with wallet_balance > 0. */
    #[Url(as: 'wallet', except: '')]
    public string $walletFilter = '';

    /**
     * 'all' lifts the per-branch scope so the operator sees every
     * Customer under the tenant across every property they can
     * access. Default 'branch' scopes to PropertyContext::current().
     * Shown as a toggle chip when the tenant has 2+ properties.
     */
    #[Url(as: 'scope', except: 'branch')]
    public string $propertyScope = 'branch';

    #[Url(as: 'sort', except: 'recent')]
    public string $sort = 'recent';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLifecycleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTagFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVipFilter(): void
    {
        $this->resetPage();
    }

    public function updatedWalletFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->lifecycleFilter = '';
        $this->tagFilter = '';
        $this->vipFilter = '';
        $this->walletFilter = '';
        $this->sort = 'recent';
        $this->resetPage();
    }

    public function render(): View
    {
        $tenantId = (int) Tenant::current()?->id;

        // LEFT JOIN customer_wallets so the "wallet balance" column and
        // sort work even for customers who've never had a wallet row.
        // COALESCE keeps their balance at 0 instead of null.
        // Scope the CRM list to the operator's current branch — a
        // mixed-portfolio tenant (multiple standalone apartments,
        // maybe a hotel) shouldn't see every branch's customers
        // rolled into one directory. Uses PropertyContext under the
        // hood via BelongsToProperty.
        $query = Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())
            ->leftJoin('customer_wallets', 'customer_wallets.customer_id', '=', 'customers.id')
            ->select('customers.*')
            ->selectRaw('COALESCE(customer_wallets.balance_naira, 0) as wallet_balance_amt')
            ->with('wallet');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('customers.name', 'like', $term)
                    ->orWhere('customers.email', 'like', $term)
                    ->orWhere('customers.phone', 'like', $term);
            });
        }

        if ($this->lifecycleFilter !== '') {
            $query->where('customers.lifecycle', $this->lifecycleFilter);
        }

        if ($this->tagFilter !== '') {
            $query->whereJsonContains('customers.tags', $this->tagFilter);
        }

        if ($this->vipFilter === '1') {
            $query->where('customers.is_vip', true);
        }

        if ($this->walletFilter === '1') {
            $query->where('customer_wallets.balance_naira', '>', 0);
        }

        match ($this->sort) {
            'name_asc' => $query->orderBy('customers.name'),
            'value_desc' => $query->orderByDesc('customers.lifetime_value'),
            'bookings_desc' => $query->orderByDesc('customers.total_bookings'),
            'wallet_desc' => $query->orderByDesc('wallet_balance_amt'),
            default => $query->orderByDesc('customers.last_booking_at')->orderByDesc('customers.id'),
        };

        $customers = $query->paginate(20);

        // Lifecycle counts for the head-row filter chips.
        $counts = [
            'all' => Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->count(),
            'prospect' => Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->inLifecycle(CustomerLifecycle::Prospect)->count(),
            'active' => Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->inLifecycle(CustomerLifecycle::Active)->count(),
            'dormant' => Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->inLifecycle(CustomerLifecycle::Dormant)->count(),
            'churned' => Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->inLifecycle(CustomerLifecycle::Churned)->count(),
            'vip' => Customer::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->where('is_vip', true)->count(),
            'wallet' => CustomerWallet::query()->when($this->propertyScope === 'branch', fn ($q) => $q->forCurrentProperty())->where('balance_naira', '>', 0)->count(),
        ];

        // The scope toggle is only meaningful when the tenant runs
        // more than one property — hide it otherwise so single-branch
        // operators don't see a mysterious "This branch / All"
        // control that flips nothing.
        $propertyCount = Tenant::current()?->properties()->count() ?? 0;

        return view('livewire.dashboard.customers.index', [
            'customers' => $customers,
            'lifecycleOptions' => CustomerLifecycle::cases(),
            'allTags' => Customer::distinctTagsForTenant($tenantId),
            'counts' => $counts,
            'showScopeToggle' => $propertyCount > 1,
            'scope' => $this->propertyScope,
        ]);
    }
}
