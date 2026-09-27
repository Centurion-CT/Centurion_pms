<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Memberships;

use App\Actions\Corporates\RenewCorporateMembership;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Services\Corporates\CorporateBookingService;
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
    public string $statusFilter = 'active';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function pause(int $id): void
    {
        $m = Membership::query()->find($id);
        if ($m === null) {
            return;
        }
        $m->status = MembershipStatus::Paused;
        $m->save();
        session()->flash('status', 'Membership paused.');
    }

    public function resume(int $id): void
    {
        $m = Membership::query()->find($id);
        if ($m === null) {
            return;
        }
        $m->status = MembershipStatus::Active;
        $m->save();
        session()->flash('status', 'Membership resumed.');
    }

    public function cancel(int $id, CorporateBookingService $corporates): void
    {
        $m = Membership::query()->find($id);
        if ($m === null) {
            return;
        }
        $m->status = MembershipStatus::Cancelled;
        $m->expires_at = now()->toDateString();
        $m->save();

        // Corporate wallet rebate. Pending memberships (uncaptured)
        // release the hold; active ones (already captured by
        // markRenewed) get refunded.
        if ($m->corporate_id !== null) {
            $released = $corporates->releaseForMembership($m->refresh());
            if ($released === null) {
                $corporates->refundForMembership($m->refresh());
            }
        }

        session()->flash('status', 'Membership cancelled.');
    }

    public function markRenewed(
        int $id,
        CorporateBookingService $corporates,
        RenewCorporateMembership $renew,
    ): void {
        $m = Membership::query()->find($id);
        if ($m === null || $m->next_renewal_at === null) {
            return;
        }

        $wasPending = $m->status === MembershipStatus::Pending;

        // First-period flip (Pending → Active) captures the existing
        // hold placed at signup. Wallet movement is handled by
        // captureForMembership.
        if ($wasPending) {
            $m->status = MembershipStatus::Active;
            $m->next_renewal_at = $this->advance($m->next_renewal_at, $m->billing_interval->value);
            $m->save();
            if ($m->corporate_id !== null) {
                $corporates->captureForMembership($m->refresh());
            }
            session()->flash('status', 'Renewal recorded.');

            return;
        }

        // Subsequent renewals on Active corporate memberships run the
        // same wallet-aware cycle the scheduled command does (hold +
        // capture + advance, pause on insufficient balance).
        if ($m->corporate_id !== null) {
            // Force the action to treat this as due even if next_renewal_at
            // is still in the future, operator's clicking "Mark renewed"
            // is an explicit intent to bill now.
            $m->next_renewal_at = now();
            $m->save();

            $result = $renew->handle($m->refresh());
            $message = match ($result) {
                RenewCorporateMembership::RESULT_RENEWED   => 'Renewal charged to corporate wallet.',
                RenewCorporateMembership::RESULT_PAUSED_LOW => 'Wallet has insufficient funds, membership paused.',
                default                                     => 'Renewal recorded.',
            };
            session()->flash('status', $message);

            return;
        }

        // Non-corporate (individual Paystack-subscription) memberships
        // just push the date forward, Paystack's recurring billing
        // handles the actual payment.
        $m->next_renewal_at = $this->advance($m->next_renewal_at, $m->billing_interval->value);
        $m->save();
        session()->flash('status', 'Renewal recorded.');
    }

    private function advance(\Illuminate\Support\Carbon $from, string $interval): \Illuminate\Support\Carbon
    {
        return match ($interval) {
            'month' => $from->copy()->addMonth(),
            'year'  => $from->copy()->addYear(),
            'week'  => $from->copy()->addWeek(),
            default => $from->copy()->addMonth(),
        };
    }

    public function render(): View
    {
        return view('livewire.dashboard.memberships.index', [
            'memberships' => $this->memberships(),
            'statuses'    => MembershipStatus::cases(),
            'stats'       => $this->stats(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Membership>
     */
    private function memberships(): LengthAwarePaginator
    {
        $q = Membership::query()
            ->with(['customer', 'plan', 'workspace'])
            ->orderByDesc('id');

        if ($this->search !== '') {
            $term = $this->search;
            $q->whereHas('customer', function ($cq) use ($term): void {
                $cq->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('phone', 'like', '%'.$term.'%');
            });
        }

        if ($this->statusFilter !== '') {
            $q->where('status', $this->statusFilter);
        }

        return $q->paginate(15);
    }

    /**
     * @return array<string,int|float>
     */
    private function stats(): array
    {
        $active = Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->count();
        $mrr = (int) Membership::query()
            ->where('status', MembershipStatus::Active->value)
            ->whereIn('billing_interval', ['month', 'year'])
            ->get()
            ->sum(function (Membership $m): int {
                return $m->billing_interval->value === 'month'
                    ? (int) $m->amount_naira
                    : (int) round($m->amount_naira / 12);
            });

        return ['active' => $active, 'mrr' => $mrr];
    }
}
