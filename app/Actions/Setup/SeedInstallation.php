<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\Enums\OperatorRole;
use App\Enums\PropertyType;
use App\Enums\StockModel;
use App\Enums\TenantStatus;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Multitenancy\SoloTenantFinder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-shot install action. Fired from the /setup wizard when the
 * operator submits the form on a fresh install. Creates the singleton
 * Tenant + first Property + first Manager Operator inside a single
 * DB transaction so a mid-way failure leaves the DB usable (the
 * wizard reloads and the operator can try again).
 *
 * Refuses if a Tenant row already exists — the wizard's route + the
 * component's mount() both guard against this, so hitting this path
 * twice would mean the operator crafted a malicious POST.
 */
class SeedInstallation
{
    /**
     * @param  array{
     *     hotel_name: string,
     *     property_name: string,
     *     property_address: string,
     *     property_city?: string|null,
     *     property_state?: string|null,
     *     property_type?: string|null,
     *     manager_name: string,
     *     manager_email: string,
     *     manager_password: string,
     * }  $input
     */
    public function handle(array $input): Operator
    {
        if (Tenant::query()->exists()) {
            throw new RuntimeException('Installation already complete. Delete the tenants table row to re-run setup.');
        }

        $hotelName = trim((string) $input['hotel_name']);
        $slug = $this->slugify($hotelName);
        $propertyName = trim((string) $input['property_name']);
        $propertySlug = $this->slugify($propertyName);
        $propertyType = PropertyType::tryFrom((string) ($input['property_type'] ?? 'hotel'))
            ?? PropertyType::Hotel;

        $managerName = trim((string) $input['manager_name']);
        $managerEmail = strtolower(trim((string) $input['manager_email']));
        $managerPassword = (string) $input['manager_password'];

        return DB::transaction(function () use (
            $hotelName, $slug, $propertyName, $propertySlug, $propertyType,
            $managerName, $managerEmail, $managerPassword, $input,
        ): Operator {
            $tenant = new Tenant([
                'name' => $hotelName,
                'slug' => $slug,
                'email' => $managerEmail,
                'status' => TenantStatus::Active->value,
            ]);
            $tenant->save();

            // Make the tenant current for the rest of this transaction
            // so BelongsToTenant auto-fills tenant_id on Property + Operator.
            $tenant->makeCurrent();

            $property = new Property([
                'tenant_id' => $tenant->id,
                'name' => $propertyName,
                'slug' => $propertySlug,
                'public_slug' => $propertySlug,
                'type' => $propertyType->value,
                'stock_model' => StockModel::StoreAndRequisition->value,
                'address' => trim((string) $input['property_address']),
                'city' => trim((string) ($input['property_city'] ?? '')) ?: null,
                'state' => trim((string) ($input['property_state'] ?? '')) ?: null,
                'is_live' => true,
                // Default off: the operator's own branded landing renders
                // gracefully with zero rooms (the "coming soon" hero copy
                // in tenant.landing handles the empty inventory case).
                // Flip this true from Settings → Property once the marketplace
                // stays layout should take over — `PublicLandingController::show`
                // dispatches to StaysShowController when it's on.
                'is_listed_publicly' => false,
                'primary_color' => '#1a1a1a',
                'font_theme' => 'inter_playfair',
            ]);
            $property->save();

            $operator = new Operator([
                'tenant_id' => $tenant->id,
                'name' => $managerName,
                'email' => $managerEmail,
                'password' => Hash::make($managerPassword),
                'role' => OperatorRole::Manager->value,
                'email_verified_at' => now(),
            ]);
            // The installer picked their own password in the wizard, so
            // there's no invite to claim — mark them activated or the
            // login gate (Operator::canSignIn) rejects them.
            $operator->forceFill(['activated_at' => now()]);
            $operator->save();

            // Invalidate SoloTenantFinder's memoised null so the next
            // request in this response cycle picks up the fresh Tenant.
            SoloTenantFinder::forget();

            return $operator;
        });
    }

    /**
     * Kebab-case slugifier that guarantees a non-empty result. Falls
     * back to a short random suffix if the input strips down to
     * nothing (e.g. all-emoji hotel name).
     */
    private function slugify(string $raw): string
    {
        $slug = Str::slug($raw, '-');
        if ($slug === '') {
            $slug = 'property-'.Str::random(6);
        }
        return $slug;
    }
}
