<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Integrations;

use App\Models\Tenant;
use App\Services\Multitenancy\TenantMediaDisk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Catalog of third-party services the tenant can wire up. Each entry
 * links to its own setup page, this component is dumb by design so
 * new integrations drop into the array without a rewrite.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    public function render(TenantMediaDisk $mediaDisk): View
    {
        $tenant = Tenant::current();
        $dokwe = $mediaDisk->describe($tenant);

        return view('livewire.dashboard.integrations.index', [
            'integrations' => [
                [
                    'key'         => 'dokwe-storage',
                    'name'        => 'Dokwe Storage',
                    'category'    => 'Storage',
                    'description' => "Send tenant uploads (property photos, invoices, PDFs) to a Dokwe space you own. Your bucket, your retention policy, your bill.",
                    'route'       => 'tenant.integrations.dokwe-storage',
                    'connected'   => $dokwe['connected'],
                    'status_line' => $dokwe['connected']
                        ? 'Connected · '.$dokwe['bucket']
                        : 'Not connected',
                ],
            ],
        ]);
    }
}
