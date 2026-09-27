<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Services\Admin\RevenueSnapshot;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Revenue dashboard (PRD §18). Numbers only in v1, Chart.js wrapping
 * is a v2 ticket.
 */
#[Layout('admin.layout')]
class Revenue extends Component
{
    public function render(RevenueSnapshot $snapshot): View
    {
        return view('livewire.admin.revenue', $snapshot->build());
    }
}
