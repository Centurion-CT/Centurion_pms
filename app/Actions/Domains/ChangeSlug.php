<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Jobs\Domains\ProvisionTenantSubdomain;
use App\Models\SlugRedirect;
use App\Models\Tenant;
use App\Services\SlugGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Renames a tenant's slug (PRD §6.6.1). Constraints:
 *  - Once per year (slug_changed_at must be NULL or > 365 days old)
 *  - New slug must be unique among tenants AND among unexpired redirects
 *  - Old slug becomes a 90-day 301 redirect to the new one
 *  - New {slug}.keverna.com CNAME is provisioned via ProvisionTenantSubdomain
 *
 * Returns the refreshed tenant.
 */
class ChangeSlug
{
    private const REDIRECT_TTL_DAYS = 90;

    public function __construct(private SlugGenerator $slugs) {}

    public function handle(Tenant $tenant, string $desiredSlug): Tenant
    {
        $this->guardCooldown($tenant);

        $newSlug = Str::slug($desiredSlug);
        if ($newSlug === '') {
            throw ValidationException::withMessages(['slug' => __('Slug cannot be empty.')]);
        }

        if ($newSlug === $tenant->slug) {
            return $tenant; // no-op
        }

        $this->guardCollision($tenant, $newSlug);

        $oldSlug = $tenant->slug;

        DB::transaction(function () use ($tenant, $newSlug, $oldSlug): void {
            // Create / refresh the 90-day redirect record FIRST so a concurrent
            // request to the old slug never sees a window where neither the
            // tenant nor the redirect exists.
            SlugRedirect::query()->updateOrCreate(
                ['from_slug' => $oldSlug],
                [
                    'to_tenant_id' => $tenant->id,
                    'expires_at' => Carbon::now()->addDays(self::REDIRECT_TTL_DAYS),
                ],
            );

            $tenant->forceFill([
                'slug' => $newSlug,
                'slug_changed_at' => Carbon::now(),
                'subdomain_provisioned_at' => null, // re-provision required
            ])->save();
        });

        ProvisionTenantSubdomain::dispatch($tenant->id);

        return $tenant->refresh();
    }

    private function guardCooldown(Tenant $tenant): void
    {
        if ($tenant->slug_changed_at === null) {
            return;
        }
        if ($tenant->slug_changed_at->diffInDays(Carbon::now()) >= 365) {
            return;
        }

        throw ValidationException::withMessages([
            'slug' => __('You can only change your slug once every 365 days. Last change: :date', [
                'date' => $tenant->slug_changed_at->toFormattedDateString(),
            ]),
        ]);
    }

    private function guardCollision(Tenant $tenant, string $newSlug): void
    {
        $clashTenant = Tenant::query()
            ->withTrashed()
            ->where('slug', $newSlug)
            ->where('id', '!=', $tenant->id)
            ->exists();

        $clashRedirect = SlugRedirect::query()
            ->where('from_slug', $newSlug)
            ->where('to_tenant_id', '!=', $tenant->id)
            ->where('expires_at', '>', Carbon::now())
            ->exists();

        if ($clashTenant || $clashRedirect) {
            $suggestion = $this->slugs->fromPropertyName($newSlug);
            throw ValidationException::withMessages([
                'slug' => __('That slug is taken. Try :suggestion.', ['suggestion' => $suggestion]),
            ]);
        }
    }
}
