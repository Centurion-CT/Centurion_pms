<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * One-time install wizard.
 *
 * Fires exactly once per install, when the DB has no Tenant row yet.
 * Bootstrap's NoCurrentTenant exception handler 302s any tenant-scoped
 * request to /setup, and this component is what /setup mounts.
 *
 * Currently a placeholder — the full form (hotel name, first property,
 * first Manager operator) lands in a follow-up. When the operator hits
 * "Finish install", the submit path calls a SeedInstallation action
 * that creates Tenant + Property + Operator atomically, then logs
 * the operator in and redirects to /dashboard.
 */
class Wizard extends Component
{
    public function mount(): void
    {
        // Already installed → nothing to do here.
        if (Tenant::query()->exists()) {
            abort(404);
        }
    }

    public function render(): View
    {
        return view('livewire.setup.wizard')
            ->layout('layouts.tenant-public');
    }
}
