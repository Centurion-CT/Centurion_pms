<?php

declare(strict_types=1);

namespace App\Livewire\Domain;

use App\Actions\Domains\AttachCustomDomain;
use App\Actions\Domains\ChangeSlug;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Operator-facing domain management (PRD §6.6.1 + §6.6.2). Combines:
 *  - Slug change (rename {old}.keverna.com to {new}.keverna.com)
 *  - Custom domain BYOD attach + DNS instructions + re-verify trigger
 *
 * Lives on the tenant subdomain at /domain.
 */
#[Layout('layouts.tenant-auth')]
class Manage extends Component
{
    #[Validate('required|string|max:191|regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i')]
    public string $custom_domain = '';

    #[Validate('required|string|max:60|regex:/^[a-z0-9-]+$/')]
    public string $slug = '';

    public function mount(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $this->custom_domain = (string) $tenant->custom_domain;
        $this->slug = $tenant->slug;
    }

    public function attach(AttachCustomDomain $action): void
    {
        $this->validateOnly('custom_domain');

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $action->handle($tenant, $this->custom_domain);

        session()->flash('status', __('Domain attached, set the DNS records below and we\'ll verify automatically.'));
    }

    public function reverify(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        if ($tenant->custom_domain === null) {
            return;
        }

        VerifyCustomDomain::dispatch($tenant->id);
        session()->flash('status', __('Re-checking DNS now, refresh in a few seconds.'));
    }

    public function renameSlug(ChangeSlug $action): void
    {
        $this->validateOnly('slug');

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $action->handle($tenant, $this->slug);

        session()->flash('status', __('Slug updated. Your previous URL will redirect for 90 days.'));
    }

    public function render(): View
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::current();

        return view('livewire.domain.manage', [
            'tenant' => $tenant->refresh(),
            'cnameTarget' => (string) config('keverna.custom_domain_cname_target'),
        ]);
    }
}
