<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\OperatorRole;
use App\Enums\PropertyType;
use App\Enums\StockModel;
use App\Enums\TenantStatus;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Multitenancy\HostTenantFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a business with its first branch and its manager, in one
 * transaction. Used by platform sign-up and by the one-off /setup wizard.
 *
 * The manager chose their own password and confirmed their email before
 * this runs, so they are created verified and activated.
 */
class CreateBusiness
{
    /**
     * @param  array{
     *     name: string,
     *     slug: string,
     *     branch_name?: string|null,
     *     branch_address?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     type?: string|null,
     *     expected_units?: int|null,
     *     manager_name: string,
     *     manager_email: string,
     *     manager_phone?: string|null,
     *     manager_password_hash: string,
     * }  $input
     */
    public function handle(array $input): Operator
    {
        $name = trim($input['name']);
        $branchName = trim((string) ($input['branch_name'] ?? '')) ?: $name;
        $city = trim((string) ($input['city'] ?? '')) ?: null;
        $email = strtolower(trim($input['manager_email']));
        $type = PropertyType::tryFrom((string) ($input['type'] ?? '')) ?? PropertyType::Hotel;

        return DB::transaction(function () use ($input, $name, $branchName, $city, $email, $type): Operator {
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $input['slug'],
                'email' => $email,
                'phone' => $input['manager_phone'] ?? null,
                'expected_units' => $input['expected_units'] ?? null,
                'status' => TenantStatus::Active->value,
            ]);

            // BelongsToTenant fills tenant_id on the branch and manager.
            $tenant->makeCurrent();

            $branchSlug = Str::slug($branchName) ?: 'main';

            Property::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $branchName,
                'slug' => $branchSlug,
                'public_slug' => $this->uniquePublicSlug($input['slug']),
                'type' => $type->value,
                'stock_model' => StockModel::StoreAndRequisition->value,
                'address' => trim((string) ($input['branch_address'] ?? '')) ?: (string) $city,
                'city' => $city,
                'state' => trim((string) ($input['state'] ?? '')) ?: null,
                'is_live' => true,
                // The business's own landing copes with no rooms yet; the
                // public listing waits until they turn it on in Settings.
                'is_listed_publicly' => false,
                'primary_color' => '#1a1a1a',
                'font_theme' => 'inter_playfair',
            ]);

            $operator = new Operator([
                'tenant_id' => $tenant->id,
                'name' => trim($input['manager_name']),
                'email' => $email,
                'phone' => $input['manager_phone'] ?? null,
                'password' => $input['manager_password_hash'],
                'role' => OperatorRole::Manager->value,
                'email_verified_at' => now(),
            ]);
            // No invite to claim: activated, or Operator::canSignIn refuses.
            $operator->forceFill(['activated_at' => now()]);
            $operator->save();

            // The mobile apps sign in through the person's identity, which
            // can span several businesses. Someone already on Centurion
            // keeps their existing identity (and its password).
            $identity = IdentityAccount::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $operator->name, 'password' => $input['manager_password_hash']],
            );
            IdentityOperatorLink::query()->firstOrCreate(
                ['identity_account_id' => $identity->id, 'tenant_id' => $tenant->id],
                ['operator_id' => $operator->id],
            );

            HostTenantFinder::forget();

            return $operator;
        });
    }

    /** Branch public slugs are unique across every business. */
    private function uniquePublicSlug(string $base): string
    {
        $slug = $base;
        for ($n = 2; Property::query()->withoutGlobalScopes()->where('public_slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
