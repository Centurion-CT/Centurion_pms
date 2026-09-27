<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\AdminImpersonation;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mints a single-use 15-minute impersonation token for an admin to land
 * inside a tenant's dashboard as the first operator on file (PRD §18).
 *
 * Plaintext is returned once (in the URL) and never persisted — only
 * `hash('sha256', $plaintext)` is stored. Audit row is written before the
 * URL is returned so the trail exists even if the admin never clicks.
 */
class IssueImpersonationToken
{
    public const TOKEN_TTL_MINUTES = 15;

    private const RAW_TOKEN_BYTES = 48;

    public function handle(Tenant $tenant, Admin $admin): string
    {
        $plaintext = Str::random(self::RAW_TOKEN_BYTES);

        AdminImpersonation::query()->create([
            'admin_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => Carbon::now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ]);

        activity('admin')
            ->causedBy($admin)
            ->performedOn($tenant)
            ->event('impersonation_issued')
            ->withProperties(['ttl_minutes' => self::TOKEN_TTL_MINUTES])
            ->log("Admin {$admin->name} issued an impersonation link for {$tenant->slug}.");

        return 'https://'.$tenant->primaryHost().'/admin-impersonate/'.$plaintext;
    }
}
