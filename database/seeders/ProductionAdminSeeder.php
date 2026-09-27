<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates the first platform admin on a fresh production database.
 *
 * Runs safely from `php artisan db:seed --class=ProductionAdminSeeder`
 * on any environment. Deliberately NOT invoked by the default
 * DatabaseSeeder, so a `db:seed` on production without `--class` never
 * pollutes prod with the local dev demo data (Grand Palace Abuja +
 * fake reviews).
 *
 * Idempotent by email — re-running does not create duplicates. If the
 * admin already exists but its role or active flag drifted, this seeder
 * repairs both back to the intended values, but never overwrites the
 * password (so ops can't accidentally reset an admin's password by
 * re-seeding).
 *
 * Required env vars (deploy fails fast if either is missing):
 *   INITIAL_ADMIN_EMAIL — the admin's real email address.
 *   INITIAL_ADMIN_PASSWORD — the password to set on first creation.
 *     Should be a strong one-time value; ops rotates it after first
 *     login via the admin panel.
 *
 * Optional env vars:
 *   INITIAL_ADMIN_NAME — display name (default: "Platform Admin").
 *   INITIAL_ADMIN_ROLE — 'super' or 'support' (default: 'super').
 *
 * The password cast on Admin is 'hashed' — assigning the plaintext
 * password lets Laravel hash it at save time, no double-encoding.
 */
class ProductionAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('INITIAL_ADMIN_EMAIL', ''));
        $password = (string) env('INITIAL_ADMIN_PASSWORD', '');
        $name = trim((string) env('INITIAL_ADMIN_NAME', 'Platform Admin'));
        $role = trim((string) env('INITIAL_ADMIN_ROLE', 'super'));

        if ($email === '') {
            throw new RuntimeException(
                'ProductionAdminSeeder: INITIAL_ADMIN_EMAIL is required.'
            );
        }

        if (! in_array($role, ['super', 'support'], true)) {
            throw new RuntimeException(
                "ProductionAdminSeeder: INITIAL_ADMIN_ROLE must be 'super' or 'support', got '{$role}'."
            );
        }

        $existing = Admin::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Repair role + active flag if they drifted. Never touch
            // the password — rotating it is the admin's job, not
            // this seeder's.
            $existing->forceFill([
                'name' => $name,
                'role' => $role,
                'is_active' => true,
            ])->save();

            $this->command?->info("Existing admin '{$email}' left in place (name/role/active refreshed, password untouched).");

            return;
        }

        if ($password === '') {
            throw new RuntimeException(
                'ProductionAdminSeeder: INITIAL_ADMIN_PASSWORD is required when creating a new admin.'
            );
        }

        if (strlen($password) < 12) {
            throw new RuntimeException(
                'ProductionAdminSeeder: INITIAL_ADMIN_PASSWORD must be at least 12 characters.'
            );
        }

        Admin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);

        $this->command?->info("Platform admin '{$email}' created with role '{$role}'.");
    }
}
