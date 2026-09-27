<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Dashboard;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin dashboard home (PRD §18). At-a-glance counters for the platform
 * across all tenants. No tenant scope to lift, admin guard never has one
 * bound, and Tenant is the parent model in any case.
 */
#[Layout('admin.layout')]
class Home extends Component
{
    public function render(): View
    {
        $counts = [
            'tenants_total' => Tenant::query()->count(),
            'tenants_active' => Tenant::query()
                ->whereIn('status', [TenantStatus::Active, TenantStatus::Trial, TenantStatus::Grace])
                ->count(),
            'tenants_locked' => Tenant::query()->where('status', TenantStatus::Locked)->count(),
            'tenants_archived' => Tenant::query()->where('status', TenantStatus::Archived)->count(),
        ];

        return view('livewire.admin.dashboard.home', ['counts' => $counts]);
    }
}
