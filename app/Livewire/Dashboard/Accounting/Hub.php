<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Accounting;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Accounting hub — a launcher for money-out / money-in modules.
 * Ships today with Expenses; future apps (Revenue snapshot, P&L,
 * Payroll…) will land as tiles alongside it.
 */
#[Layout('layouts.tenant-auth')]
class Hub extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard.accounting.hub');
    }
}
