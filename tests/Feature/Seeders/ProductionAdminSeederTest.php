<?php

declare(strict_types=1);

use App\Models\Admin;
use Database\Seeders\ProductionAdminSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Env-var helper — putenv() alone is not enough for Laravel's env()
 * helper, which reads the repository, not the process env. We wrap
 * with the app's config repository so the seeder sees our overrides.
 *
 * @param  array<string, string|null>  $overrides
 */
function withEnv(array $overrides, callable $callback): void
{
    $before = [];
    foreach ($overrides as $key => $value) {
        $before[$key] = env($key);
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    try {
        $callback();
    } finally {
        foreach ($before as $key => $value) {
            if ($value === null || $value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

it('creates the first admin from env vars', function (): void {
    withEnv([
        'INITIAL_ADMIN_EMAIL' => 'ops@keverna.test',
        'INITIAL_ADMIN_PASSWORD' => 'first-strong-pass-9',
        'INITIAL_ADMIN_NAME' => 'Ops Team',
        'INITIAL_ADMIN_ROLE' => 'super',
    ], function (): void {
        (new ProductionAdminSeeder)->run();
    });

    $admin = Admin::query()->where('email', 'ops@keverna.test')->firstOrFail();

    expect($admin->name)->toBe('Ops Team');
    expect($admin->role)->toBe('super');
    expect($admin->is_active)->toBeTrue();
    expect(Hash::check('first-strong-pass-9', (string) $admin->password))->toBeTrue();
});

it('is idempotent and does not touch an existing admin\'s password', function (): void {
    $existing = Admin::factory()->super()->create([
        'email' => 'existing@keverna.test',
        'name' => 'Legacy Name',
        'password' => Hash::make('the-old-password-9'),
    ]);

    withEnv([
        'INITIAL_ADMIN_EMAIL' => 'existing@keverna.test',
        'INITIAL_ADMIN_PASSWORD' => 'attempted-new-password-9',
        'INITIAL_ADMIN_NAME' => 'Refreshed Name',
        'INITIAL_ADMIN_ROLE' => 'super',
    ], function (): void {
        (new ProductionAdminSeeder)->run();
    });

    $existing->refresh();

    // Name refreshed; password NOT overwritten.
    expect($existing->name)->toBe('Refreshed Name');
    expect(Hash::check('the-old-password-9', (string) $existing->password))->toBeTrue();
    expect(Hash::check('attempted-new-password-9', (string) $existing->password))->toBeFalse();
    expect(Admin::query()->where('email', 'existing@keverna.test')->count())->toBe(1);
});

it('reactivates an admin whose is_active drifted to false', function (): void {
    Admin::factory()->super()->inactive()->create([
        'email' => 'deactivated@keverna.test',
    ]);

    withEnv([
        'INITIAL_ADMIN_EMAIL' => 'deactivated@keverna.test',
        'INITIAL_ADMIN_PASSWORD' => 'unused-when-existing-9',
        'INITIAL_ADMIN_ROLE' => 'super',
    ], function (): void {
        (new ProductionAdminSeeder)->run();
    });

    expect(Admin::query()->where('email', 'deactivated@keverna.test')->first()->is_active)->toBeTrue();
});

it('refuses to run when INITIAL_ADMIN_EMAIL is missing', function (): void {
    withEnv([
        'INITIAL_ADMIN_EMAIL' => null,
        'INITIAL_ADMIN_PASSWORD' => 'ignored-9-9-9-9',
    ], function (): void {
        expect(fn () => (new ProductionAdminSeeder)->run())
            ->toThrow(RuntimeException::class, 'INITIAL_ADMIN_EMAIL is required');
    });
});

it('refuses to create a new admin when INITIAL_ADMIN_PASSWORD is missing', function (): void {
    withEnv([
        'INITIAL_ADMIN_EMAIL' => 'nopass@keverna.test',
        'INITIAL_ADMIN_PASSWORD' => null,
    ], function (): void {
        expect(fn () => (new ProductionAdminSeeder)->run())
            ->toThrow(RuntimeException::class, 'INITIAL_ADMIN_PASSWORD is required');
    });

    expect(Admin::query()->where('email', 'nopass@keverna.test')->exists())->toBeFalse();
});

it('refuses a short password on first creation', function (): void {
    withEnv([
        'INITIAL_ADMIN_EMAIL' => 'weak@keverna.test',
        'INITIAL_ADMIN_PASSWORD' => 'short',
    ], function (): void {
        expect(fn () => (new ProductionAdminSeeder)->run())
            ->toThrow(RuntimeException::class, 'at least 12 characters');
    });
});

it('refuses an invalid role', function (): void {
    withEnv([
        'INITIAL_ADMIN_EMAIL' => 'badrole@keverna.test',
        'INITIAL_ADMIN_PASSWORD' => 'strong-enough-1234',
        'INITIAL_ADMIN_ROLE' => 'god-mode',
    ], function (): void {
        expect(fn () => (new ProductionAdminSeeder)->run())
            ->toThrow(RuntimeException::class, "must be 'super' or 'support'");
    });
});
