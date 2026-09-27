<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Customers;

use App\Actions\Customers\RecordWalletTopup;
use App\Actions\Customers\RefundFromWallet;
use App\Enums\CustomerWalletTransactionType;
use App\Enums\OperatorRole;
use App\Models\Customer;
use App\Models\Operator;
use App\Services\Customers\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Guest wallet management tab. Two write surfaces (top-up + refund)
 * plus a filterable transaction ledger and the PDF-statement download.
 *
 * Refunds are Manager-only: {@see RefundFromWallet} enforces the same
 * gate server-side, this component just hides the form for other roles.
 */
#[Layout('layouts.tenant-auth')]
class Wallet extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    /** Top-up form. */
    #[Validate('required|integer|min:1|max:99999999')]
    public int $topupAmount = 0;

    #[Validate('required|in:cash,transfer,pos,other')]
    public string $topupMethod = 'cash';

    #[Validate('nullable|string|max:200')]
    public string $topupNote = '';

    /** Refund form (manager-only). */
    #[Validate('required|integer|min:1|max:99999999')]
    public int $refundAmount = 0;

    #[Validate('required|in:cash,transfer,pos,other')]
    public string $refundMethod = 'cash';

    #[Validate('required|string|min:3|max:400')]
    public string $refundReason = '';

    /** Ledger filter, used only for the PDF download link. */
    #[Validate('nullable|date')]
    public string $filterFrom = '';

    #[Validate('nullable|date|after_or_equal:filterFrom')]
    public string $filterTo = '';

    #[Validate('nullable|in:all,topup,spend,refund,adjustment')]
    public string $filterType = 'all';

    public function mount(Customer $customer): void
    {
        $this->customerId = $customer->id;
    }

    public function topup(RecordWalletTopup $action): void
    {
        $this->validateOnly('topupAmount');
        $this->validateOnly('topupMethod');
        $this->validateOnly('topupNote');

        $operator = $this->operator();
        if ($operator === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Not authenticated.', kind: 'err');

            return;
        }

        try {
            $action->handle(
                customer: $this->customer(),
                operator: $operator,
                amount: $this->topupAmount,
                method: $this->topupMethod,
                referenceNote: $this->topupNote !== '' ? $this->topupNote : null,
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');

            return;
        }

        $this->reset(['topupAmount', 'topupNote']);
        $this->topupMethod = 'cash';
        $this->dispatch('notify', title: 'Done', message: 'Top-up recorded.', kind: 'ok');
        $this->resetPage();
    }

    public function refund(RefundFromWallet $action): void
    {
        $operator = $this->operator();
        if ($operator === null) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Not authenticated.', kind: 'err');

            return;
        }
        if ($operator->role !== OperatorRole::Manager) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: 'Only managers can refund wallet funds.', kind: 'err');

            return;
        }

        $this->validateOnly('refundAmount');
        $this->validateOnly('refundMethod');
        $this->validateOnly('refundReason');

        try {
            $action->handle(
                customer: $this->customer(),
                operator: $operator,
                amount: $this->refundAmount,
                method: $this->refundMethod,
                reason: $this->refundReason,
            );
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');

            return;
        }

        $this->reset(['refundAmount', 'refundReason']);
        $this->refundMethod = 'cash';
        $this->dispatch('notify', title: 'Done', message: 'Refund processed.', kind: 'ok');
        $this->resetPage();
    }

    public function render(): View
    {
        $customer = $this->customer();
        app(WalletService::class)->ensureWallet($customer);
        $customer->loadMissing('wallet');

        $query = $customer->walletTransactions()
            ->with(['booking', 'posSale', 'charge', 'approvedBy']);

        if ($this->filterType !== 'all') {
            $query->where('type', $this->filterType);
        }

        return view('livewire.dashboard.customers.wallet', [
            'customer' => $customer,
            'operator' => $this->operator(),
            'balance' => $customer->walletBalance(),
            'transactions' => $query->paginate(20),
            'typeOptions' => [
                'all' => 'All types',
                CustomerWalletTransactionType::Topup->value => 'Top-ups',
                CustomerWalletTransactionType::Spend->value => 'Spends',
                CustomerWalletTransactionType::Refund->value => 'Refunds',
                CustomerWalletTransactionType::Adjustment->value => 'Adjustments',
            ],
            'isManager' => $this->operator()?->role === OperatorRole::Manager,
            'statementUrl' => route('tenant.customers.wallet.statement', array_filter([
                'customer' => $customer->id,
                'from' => $this->filterFrom !== '' ? $this->filterFrom : null,
                'to' => $this->filterTo !== '' ? $this->filterTo : null,
            ])),
        ]);
    }

    private function customer(): Customer
    {
        // Same authorization guard as CustomerShow: operator in branch
        // A can't URL-hop into branch B's wallet.
        return Customer::query()->forCurrentProperty()->findOrFail($this->customerId);
    }

    private function operator(): ?Operator
    {
        $user = Auth::guard('web')->user();

        return $user instanceof Operator ? $user : null;
    }
}
