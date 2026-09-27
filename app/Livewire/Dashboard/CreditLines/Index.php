<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\CreditLines;

use App\Enums\CreditLineStatus;
use App\Models\CreditLine;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Roster of every credit line on the property with its running
 * outstanding. Front desk lands here to open a fresh tab or drill
 * into an existing one; management uses it as the "who owes what"
 * board before doing the offline settlement rounds.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
    }

    public function render(): View
    {
        $q = CreditLine::query()
            ->with(['customer', 'rooms'])
            ->withSum('charges as charged_total', 'amount')
            ->withSum('settlements as settled_total', 'amount')
            ->orderByDesc('updated_at');

        if ($this->statusFilter !== '') {
            $q->where('status', $this->statusFilter);
        }

        $needle = trim($this->search);
        if ($needle !== '') {
            $q->where(function ($qq) use ($needle): void {
                $qq->where('name', 'like', "%{$needle}%")
                    ->orWhereHas('customer', function ($cq) use ($needle): void {
                        $cq->where('name', 'like', "%{$needle}%")
                            ->orWhere('phone', 'like', "%{$needle}%")
                            ->orWhere('email', 'like', "%{$needle}%");
                    })
                    ->orWhereHas('rooms', function ($rq) use ($needle): void {
                        $rq->where('room_number', 'like', "%{$needle}%");
                    });
            });
        }

        $lines = $q->get();

        // KPI strip totals, computed after filter so the numbers
        // reflect what's on screen.
        $totals = [
            'lines' => $lines->count(),
            'outstanding' => (int) $lines->sum(fn ($l) => max(0, (int) $l->charged_total - (int) $l->settled_total)),
        ];

        return view('livewire.dashboard.credit-lines.index', [
            'lines' => $lines,
            'totals' => $totals,
            'statusOptions' => CreditLineStatus::cases(),
        ]);
    }
}
