<?php

declare(strict_types=1);

namespace App\Actions\Setup;

use App\Actions\Platform\CreateBusiness;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-shot install action. Fired from the /setup wizard when the
 * operator submits the form on a fresh install. Creates the first
 * business, its branch and its manager through CreateBusiness (one
 * transaction, so a failure leaves the DB usable and the wizard can be
 * retried).
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

        return app(CreateBusiness::class)->handle([
            'name' => (string) $input['hotel_name'],
            'slug' => $this->slugify(trim((string) $input['hotel_name'])),
            'branch_name' => (string) $input['property_name'],
            'branch_address' => (string) $input['property_address'],
            'city' => $input['property_city'] ?? null,
            'state' => $input['property_state'] ?? null,
            'type' => $input['property_type'] ?? 'hotel',
            'manager_name' => (string) $input['manager_name'],
            'manager_email' => (string) $input['manager_email'],
            'manager_password_hash' => Hash::make((string) $input['manager_password']),
        ]);
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
