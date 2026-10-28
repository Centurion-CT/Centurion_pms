<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\AllBranches;

use App\Services\Owner\AllBranchesOverview;
use App\Services\Properties\PropertyContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Live activity on the All branches page. Its own component so the
 * 30-second poll redraws only this list, not the money figures.
 */
class Activity extends Component
{
    public function render(PropertyContext $context, AllBranchesOverview $overview): View
    {
        return view('livewire.dashboard.all-branches.activity', [
            'items' => $overview->activity($context->allForTenant()),
        ]);
    }
}
