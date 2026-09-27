<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Tenants;

use App\Actions\Admin\ExtendSubscription;
use App\Actions\Admin\IssueImpersonationToken;
use App\Actions\Admin\ResendOperatorVerification;
use App\Actions\Admin\RetriggerDomainVerification;
use App\Enums\VerificationChannel;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

/**
 * Tenant detail page in the admin panel (PRD §18). Surfaces the admin
 * write actions: extend subscription, re-verify custom domain, impersonate.
 *
 * Route-model binding hydrates `$tenant` from the URL, Tenant has no
 * tenant scope (it IS the tenant), so admins see every row by default.
 */
#[Layout('admin.layout')]
class Show extends Component
{
    #[Locked]
    public int $tenantId;

    #[Validate('required|integer|min:1|max:365')]
    public int $extendDays = 30;

    public ?string $impersonationUrl = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenantId = $tenant->id;
    }

    public function extend(ExtendSubscription $action): void
    {
        try {
            $this->validate();
            $action->handle($this->tenant(), $this->admin(), $this->extendDays);
            $this->dispatch('notify', title: 'Done', message: "Extended by {$this->extendDays} days.", kind: 'ok');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function reverifyDomain(RetriggerDomainVerification $action): void
    {
        try {
            $action->handle($this->tenant(), $this->admin());
            $this->dispatch('notify', title: 'Done', message: 'Re-verification job queued.', kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function impersonate(IssueImpersonationToken $action): void
    {
        $this->impersonationUrl = null;

        try {
            $this->impersonationUrl = $action->handle($this->tenant(), $this->admin());
            $this->dispatch('notify', title: 'Done', message: 'Impersonation link generated. It expires in 15 minutes.', kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function resendEmailVerification(ResendOperatorVerification $action): void
    {
        $this->runResend($action, VerificationChannel::Email);
    }

    public function resendPhoneVerification(ResendOperatorVerification $action): void
    {
        $this->runResend($action, VerificationChannel::Phone);
    }

    private function runResend(ResendOperatorVerification $action, VerificationChannel $channel): void
    {
        try {
            $action->handle($this->tenant(), $this->admin(), $channel);
            $this->dispatch('notify', title: 'Done', message: "Resent {$channel->value} verification code to the founder operator.", kind: 'ok');
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(): View
    {
        $tenant = $this->tenant();
        // Onboarding OTP resend targets the founder operator (earliest by id,
        // per RegisterTenantOperator). Read inside the tenant context because
        // Operator has BelongsToTenant. Null when the tenant has no operators
        // yet, the resend section then hides.
        $founder = $tenant->execute(fn () => Operator::query()->orderBy('id')->first());

        // Properties are tenant-scoped, so load them inside the tenant
        // context. Feeds the concierge content-import panel — one
        // "Import content" link per property so the admin doesn't have
        // to type route params by hand.
        $properties = $tenant->execute(fn () => \App\Models\Property::query()
            ->orderBy('id')
            ->get(['id', 'name', 'city']));

        return view('livewire.admin.tenants.show', [
            'tenant' => $tenant,
            'activeSubscription' => $tenant->currentSubscription(),
            'founder' => $founder,
            'properties' => $properties,
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail($this->tenantId);
    }

    private function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = auth()->guard('admin')->user();

        return $admin;
    }
}
