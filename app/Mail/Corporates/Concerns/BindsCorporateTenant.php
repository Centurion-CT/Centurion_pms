<?php

declare(strict_types=1);

namespace App\Mail\Corporates\Concerns;

use App\Models\Corporate;
use App\Models\Tenant;
use Illuminate\Mail\Mailables\Headers;

/**
 * Corporate mailables run on a queue worker with no business bound, and
 * a corporate belongs to one business. Bind that business before the
 * mail renders (same hook as BindsBookingTenant) so the corporate loads,
 * the header carries its branding, and links go to its own address.
 */
trait BindsCorporateTenant
{
    private ?Tenant $corporateTenant = null;

    public function headers(): Headers
    {
        $tenantId = Corporate::query()->withoutGlobalScopes()
            ->whereKey($this->corporateIdForTenant())
            ->value('tenant_id');

        $this->corporateTenant = $tenantId === null ? null : Tenant::query()->find($tenantId);
        $this->corporateTenant?->makeCurrent();

        return new Headers;
    }

    /** The corporate portal's wallet page on the corporate's business. */
    protected function walletUrl(): string
    {
        $host = $this->corporateTenant?->primaryHost() ?? (string) config('keverna.central_domain');

        return 'https://'.$host.'/corporates/wallet';
    }

    abstract protected function corporateIdForTenant(): ?int;
}
