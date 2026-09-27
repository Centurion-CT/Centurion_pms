<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Tenants;

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\Admin\DangerousTenantDelete;
use App\Services\Auth\TotpService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dangerous, irreversible tenant delete form. Reached at
 * /admin/tenants/{tenant}/destroy. Super-admin only; middleware
 * on the route gate handles that. This form additionally requires:
 *
 *   1. The admin has enrolled 2FA (checked on mount).
 *   2. The admin types the tenant slug exactly to confirm intent.
 *   3. The admin enters a fresh TOTP code (per-request, not the
 *      session pass flag). Recovery codes deliberately do NOT work
 *      here - the admin has to actually hold the second factor.
 *   4. A written reason lands in the audit log.
 */
#[Layout('admin.layout')]
class Destroy extends Component
{
    public Tenant $tenant;

    public string $slugConfirm = '';
    public string $totpCode = '';
    public string $reason = '';

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        if (! $admin->hasTwoFactor()) {
            session()->flash('flash', 'Enrol 2FA before you can delete tenants.');
            $this->redirect(route('admin.two-factor.enroll'));
        }
    }

    public function destroy(DangerousTenantDelete $service, TotpService $totp): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $errors = [];
        if ($this->slugConfirm !== $this->tenant->slug) {
            $errors['slugConfirm'] = 'The slug you typed does not match. Delete refused.';
        }
        if (trim($this->reason) === '') {
            $errors['reason'] = 'Reason is required for the audit trail.';
        }
        if (! $totp->verify((string) $admin->two_factor_secret, $this->totpCode)) {
            $errors['totpCode'] = 'That 2FA code is wrong. Refresh your authenticator + try the current code.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $service->handle($this->tenant, $admin, trim($this->reason));

        session()->flash('flash', "Tenant {$this->tenant->slug} + all its data have been permanently deleted.");
        $this->redirect(route('admin.tenants.index'));
    }

    public function render(): View
    {
        return view('livewire.admin.tenants.destroy');
    }
}
