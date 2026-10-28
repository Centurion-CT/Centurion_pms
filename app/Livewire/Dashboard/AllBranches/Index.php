<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\AllBranches;

use App\Services\Owner\AllBranchesOverview;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * All branches: money, tonight's rooms, a row per branch, what needs
 * attention and live activity for the whole hotel on one page
 * (design/all-branches). Managers, plus operators granted the
 * overview.all_branches permission (route middleware role:overview).
 *
 * Shows every branch the hotel has, whatever the viewer's branch
 * access: it is read-only, and is meant for an owner, accountant or
 * area manager. "Open" (which switches the viewer into a branch) only
 * appears on branches they can work in.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(except: 'month')]
    public string $period = 'month';

    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, AllBranchesOverview::PERIODS, true) ? $period : 'month';
        if ($this->period === 'custom') {
            $this->from ??= now()->startOfMonth()->toDateString();
            $this->to ??= now()->toDateString();
        } else {
            $this->from = null;
            $this->to = null;
        }
    }

    public function updatedFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedTo(): void
    {
        $this->period = 'custom';
    }

    public function render(PropertyContext $context, AllBranchesOverview $overview): View
    {
        $period = in_array($this->period, AllBranchesOverview::PERIODS, true) ? $this->period : 'month';
        $properties = $context->allForTenant();
        $canOpen = $context->all()->pluck('id')->map(fn ($id) => (int) $id)->flip();

        return view('livewire.dashboard.all-branches.index', [
            'data' => $overview->build($properties, $period, $this->from, $this->to),
            'properties' => $properties,
            'canOpen' => $canOpen,
            'current' => $context->current(),
            'period' => $period,
        ]);
    }
}
