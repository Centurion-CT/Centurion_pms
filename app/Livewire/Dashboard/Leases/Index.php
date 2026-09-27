<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Leases;

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\LeasePayment;
use App\Services\Corporates\CorporateBookingService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function terminate(int $id, CorporateBookingService $corporates): void
    {
        $l = Lease::query()->find($id);
        if ($l === null) {
            return;
        }
        $l->status = LeaseStatus::Terminated;
        $l->save();
        $l->unit?->update(['status' => 'available']);

        if ($l->corporate_id !== null) {
            $released = $corporates->releaseForLease($l->refresh());
            if ($released === null) {
                // Captured already, refund the unused fraction back
                // to the wallet. v1 refunds the full original spend;
                // pro-rata is a Phase 4 ticket.
                $corporates->refundForLease($l->refresh());
            }
        }

        session()->flash('status', 'Lease terminated.');
    }

    public function render(): View
    {
        $leases = $this->leases();

        return view('livewire.dashboard.leases.index', [
            'leases'       => $leases,
            'statuses'     => LeaseStatus::cases(),
            'stats'        => $this->stats(),
            'rentStates'   => $this->rentStates($leases->pluck('id')->all()),
            'rentRollBars' => $this->rentRollBars(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Lease>
     */
    private function leases(): LengthAwarePaginator
    {
        $q = Lease::query()->with(['unit', 'customer'])->latest('started_at');

        if ($this->search !== '') {
            $term = $this->search;
            $q->where(function ($qq) use ($term): void {
                $qq->where('reference', 'like', '%'.$term.'%')
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

        if ($this->statusFilter !== '') {
            $q->where('status', $this->statusFilter);
        }

        return $q->paginate(15);
    }

    /**
     * Per-lease rent-collection state derived from LeasePayment rows.
     * Runs in one query per page (whereIn on the page's lease_ids)
     * then bucketises per-lease in memory so the blade renders one
     * status pill per row without hitting N+1.
     *
     *   overdue  → has past-due payment with paid_at NULL
     *   upcoming → next payment due within 30 days
     *   paid     → every past-due payment settled
     *   new      → no LeasePayment rows yet (schedule not built)
     *
     * @param  array<int, int>  $leaseIds
     * @return array<int, string>
     */
    private function rentStates(array $leaseIds): array
    {
        if ($leaseIds === []) return [];
        $today = Carbon::today();
        $upcomingCutoff = $today->copy()->addDays(30);

        $payments = LeasePayment::query()
            ->whereIn('lease_id', $leaseIds)
            ->orderBy('due_at')
            ->get(['lease_id', 'due_at', 'paid_at'])
            ->groupBy('lease_id');

        $out = [];
        foreach ($leaseIds as $lid) {
            $rows = $payments->get($lid);
            if ($rows === null || $rows->isEmpty()) {
                $out[$lid] = 'new';
                continue;
            }
            $hasOverdue = false;
            $hasUpcoming = false;
            foreach ($rows as $p) {
                $due = $p->due_at instanceof Carbon
                    ? $p->due_at
                    : Carbon::parse((string) $p->due_at);
                $paid = $p->paid_at !== null;
                if (! $paid && $due->lessThan($today)) {
                    $hasOverdue = true;
                    break;
                }
                if (! $paid && $due->lessThanOrEqualTo($upcomingCutoff)) {
                    $hasUpcoming = true;
                }
            }
            $out[$lid] = match (true) {
                $hasOverdue  => 'overdue',
                $hasUpcoming => 'upcoming',
                default      => 'paid',
            };
        }
        return $out;
    }

    /**
     * 12-month rent-roll: expected rent per month across every Active
     * lease that overlaps that month. Uses monthly_rent_naira when
     * present, otherwise annual_rent_naira / 12 so the chart still
     * paints for older annual-only rows. Rolling window = last 6
     * months + next 5 months + current (12 total) so an operator
     * sees momentum + upcoming pipeline in one strip.
     *
     * @return array<int, array{month: string, iso: string, amount: int, is_current: bool}>
     */
    private function rentRollBars(): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subMonths(6)->startOfMonth();
        $months = [];
        for ($m = 0; $m < 12; $m++) {
            $when = $start->copy()->addMonths($m);
            $months[] = $when;
        }

        // Fetch every Active or Notice-Served lease that could overlap
        // the window. Terminated + Expired are skipped since they no
        // longer generate rent-roll.
        $leases = Lease::query()
            ->whereIn('status', [
                LeaseStatus::Active->value,
                // Keep other statuses that carry future rent when
                // present in the enum; guard optional cases.
            ])
            ->get([
                'id', 'started_at', 'expires_at',
                'annual_rent_naira', 'monthly_rent_naira',
            ]);

        $bars = [];
        foreach ($months as $when) {
            $endOfMonth = $when->copy()->endOfMonth();
            $total = 0;
            foreach ($leases as $l) {
                $started = $l->started_at instanceof Carbon
                    ? $l->started_at
                    : Carbon::parse((string) $l->started_at);
                $expires = $l->expires_at instanceof Carbon
                    ? $l->expires_at
                    : Carbon::parse((string) $l->expires_at);
                if ($started->greaterThan($endOfMonth)) continue;
                if ($expires->lessThan($when)) continue;
                $monthly = $l->monthly_rent_naira
                    ?? (int) round(((int) $l->annual_rent_naira) / 12);
                $total += (int) $monthly;
            }
            $bars[] = [
                'month'      => $when->format('M'),
                'iso'        => $when->format('Y-m'),
                'amount'     => $total,
                'is_current' => $when->isSameMonth($today),
            ];
        }
        return $bars;
    }

    /**
     * @return array<string,int>
     */
    private function stats(): array
    {
        $active = Lease::query()->where('status', LeaseStatus::Active->value)->count();
        $expiringSoon = Lease::query()
            ->where('status', LeaseStatus::Active->value)
            ->whereBetween('expires_at', [now(), now()->addDays(60)])
            ->count();
        $annualRoll = (int) Lease::query()
            ->where('status', LeaseStatus::Active->value)
            ->sum('annual_rent_naira');

        return [
            'active'         => $active,
            'expiring_soon'  => $expiringSoon,
            'annual_roll'    => $annualRoll,
        ];
    }
}
