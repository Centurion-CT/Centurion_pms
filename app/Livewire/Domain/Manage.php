<?php

declare(strict_types=1);

namespace App\Livewire\Domain;

use App\Actions\Domains\AttachCustomDomain;
use App\Actions\Domains\ChangeSlug;
use App\Actions\Domains\CheckOwnDomain;
use App\Enums\CustomDomainStatus;
use App\Jobs\Domains\VerifyCustomDomain;
use App\Models\Operator;
use App\Models\Tenant;
use App\Services\PostRegistrationLoginLink;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Where guests find the business (PRD §6.6, docs/MULTITENANCY.md step 4):
 *  - Its address on the platform, `{slug}{suffix}`, renamable once a year.
 *  - Its own domain: attach, DNS records to set, live status, remove.
 *
 * Lives on the business's address at /domain.
 */
#[Layout('layouts.tenant-auth')]
class Manage extends Component
{
    #[Validate('required|string|max:191|regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i', message: 'Enter a domain like book.yourbrand.com, without https://.')]
    public string $custom_domain = '';

    #[Validate('required|string|max:60')]
    public string $slug = '';

    public function mount(): void
    {
        $tenant = $this->tenant();
        $this->custom_domain = (string) $tenant->custom_domain;
        $this->slug = $tenant->slug;
    }

    public function attach(AttachCustomDomain $action): void
    {
        $this->custom_domain = strtolower(trim(preg_replace('#^https?://#i', '', $this->custom_domain) ?? '', " /\t\n"));
        $this->validateOnly('custom_domain');

        $action->handle($this->tenant(), $this->custom_domain);

        $this->dispatch('notify', title: 'Domain saved', message: 'Add the DNS records below. We check every 15 minutes.', kind: 'ok');
    }

    public function checkAgain(CheckOwnDomain $live): void
    {
        $tenant = $this->tenant();
        if ($tenant->custom_domain === null) {
            return;
        }

        if ($tenant->domain_verified_at === null) {
            if ($tenant->custom_domain_status === CustomDomainStatus::Error) {
                $tenant->forceFill(['custom_domain_status' => CustomDomainStatus::Pending])->save();
            }
            VerifyCustomDomain::dispatch($tenant->id);
            $this->dispatch('notify', title: 'Checking DNS', message: 'Refresh in a few seconds.', kind: 'ok');

            return;
        }

        $live->handle($tenant)
            ? $this->dispatch('notify', title: 'Live', message: "{$tenant->custom_domain} is answering for your business.", kind: 'ok')
            : $this->dispatch('notify', title: 'Not answering yet', message: "{$tenant->custom_domain} doesn't reach us over HTTPS yet.", kind: 'warn');
    }

    public function remove(): void
    {
        $this->tenant()->forceFill([
            'custom_domain' => null,
            'custom_domain_verification_token' => null,
            'custom_domain_status' => null,
            'domain_verified_at' => null,
            'custom_domain_live_at' => null,
            'custom_domain_failed_checks' => 0,
            'custom_domain_dokwe_id' => null,
        ])->save();
        $this->custom_domain = '';

        $this->dispatch('notify', title: 'Domain removed', message: 'Guests use your '.config('brand.name').' address again.', kind: 'ok');
    }

    public function renameSlug(ChangeSlug $action, PostRegistrationLoginLink $links)
    {
        $this->validateOnly('slug');

        $tenant = $this->tenant();
        $before = $tenant->slug;
        $tenant = $action->handle($tenant, $this->slug);

        if ($tenant->slug === $before) {
            return null;
        }

        // Sessions belong to an address, so carry the manager over to the
        // new one already signed in.
        /** @var Operator $operator */
        $operator = auth('web')->user();

        return redirect()->away($links->generate($tenant, $operator));
    }

    public function render(): View
    {
        return view('livewire.domain.manage', [
            'tenant' => $this->tenant()->refresh(),
            'suffix' => (string) config('keverna.tenant_domain_suffix'),
            'cnameTarget' => (string) config('keverna.custom_domain_cname_target'),
            'aRecords' => (array) config('keverna.custom_domain_a_records'),
            'verifyName' => AttachCustomDomain::VERIFY_LABEL,
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return Tenant::current();
    }
}
