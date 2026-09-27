<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Corporate;

use App\Enums\CorporateMemberRole;
use App\Enums\WalletTransactionType;
use App\Models\CorporateWalletTopup;
use App\Models\CorporateWalletTransaction;
use App\Services\Corporates\TopupService;
use App\Services\Corporates\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Corporate-admin wallet view. Shows balance + held + available, plus
 * the in-flight + recent top-ups, plus the full transaction ledger.
 *
 * Top-up flow:
 *   1. Admin enters an amount → submits
 *   2. {@see startTopup} creates a CorporateWalletTopup row + asks
 *      Paystack for an authorization URL
 *   3. Livewire returns a JS redirect to that URL via redirect()
 *   4. Admin completes payment on Paystack
 *   5. Paystack POSTs the webhook → wallet credited; admin lands on
 *      the callback URL which re-verifies + bounces back here
 */
#[Layout('layouts.corporate-portal')]
class Wallet extends Component
{
    use WithPagination;

    #[Url(as: 'type')]
    public string $typeFilter = '';

    public ?int $requestAmount = null;
    public string $requestNote = '';

    public function mount(): void
    {
        $this->guardAdmin();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Kick off a Paystack-funded top-up. We return a Livewire redirect
     * to the authorization URL, the browser leaves the portal, the
     * admin pays, then Paystack bounces them back via callback_url.
     */
    public function startTopup(TopupService $topups)
    {
        $this->guardAdmin();
        $data = $this->validate([
            'requestAmount' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'requestNote'   => ['nullable', 'string', 'max:240'],
        ]);

        $member = auth('corporate')->user();
        $corporate = $member->corporate;

        try {
            $result = $topups->initiate(
                $corporate,
                $member,
                (int) $data['requestAmount'],
                $data['requestNote'] !== '' ? $data['requestNote'] : null,
            );
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');

            return null;
        }

        return $this->redirect($result['authorization_url']);
    }

    public function cancelTopup(int $topupId, TopupService $topups): void
    {
        $this->guardAdmin();
        $corporate = auth('corporate')->user()->corporate;
        /** @var CorporateWalletTopup|null $topup */
        $topup = CorporateWalletTopup::query()->find($topupId);
        if ($topup === null || $topup->corporate_id !== $corporate->id) {
            return;
        }
        $topups->markCancelled($topup);
        $this->dispatch('notify', title: 'Done', message: 'Top-up cancelled.', kind: 'ok');
    }

    public function render(WalletService $wallets): View
    {
        $member = auth('corporate')->user();
        $corporate = $member->corporate;
        $wallet = $wallets->ensureWallet($corporate);

        $pendingTopups = CorporateWalletTopup::query()
            ->where('corporate_id', $corporate->id)
            ->where('status', CorporateWalletTopup::STATUS_PENDING)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $recentTopups = CorporateWalletTopup::query()
            ->where('corporate_id', $corporate->id)
            ->whereIn('status', [
                CorporateWalletTopup::STATUS_SUCCEEDED,
                CorporateWalletTopup::STATUS_FAILED,
                CorporateWalletTopup::STATUS_CANCELLED,
            ])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('livewire.portal.corporate.wallet', [
            'wallet'         => $wallet,
            'transactions'   => $this->paginate(),
            'types'          => WalletTransactionType::cases(),
            'pendingTopups'  => $pendingTopups,
            'recentTopups'   => $recentTopups,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, CorporateWalletTransaction>
     */
    private function paginate(): LengthAwarePaginator
    {
        $corporate = auth('corporate')->user()->corporate;
        $q = CorporateWalletTransaction::query()
            ->where('corporate_id', $corporate->id)
            ->with('member')
            ->latest('id');

        if ($this->typeFilter !== '' && WalletTransactionType::tryFrom($this->typeFilter) !== null) {
            $q->where('type', $this->typeFilter);
        }

        return $q->paginate(25);
    }

    private function guardAdmin(): void
    {
        $member = auth('corporate')->user();
        if ($member === null || $member->role !== CorporateMemberRole::Admin) {
            throw new AccessDeniedHttpException('Corporate admin access required.');
        }
    }
}
