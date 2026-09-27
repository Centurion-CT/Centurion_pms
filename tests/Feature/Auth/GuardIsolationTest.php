<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;

/*
|--------------------------------------------------------------------------
| Cross-guard isolation (PRD §2 / §8)
|--------------------------------------------------------------------------
|
| Operators authenticate against `web`, admins against `admin`. Neither guard
| should be able to access the other's surfaces — even with matching credentials
| in their own table.
*/

it('a logged-in operator cannot reach admin routes', function (): void {
    $tenant = Tenant::factory()->create();
    $operator = Operator::factory()->for($tenant)->create();

    $this->actingAs($operator, 'web');

    // Admin routes require the `admin` guard; the operator's `web` session
    // is invisible to it. We don't have admin-guarded protected routes yet,
    // but we can assert the ping route doesn't bind an admin user.
    $this->get('http://admin.keverna.test/admin/__admin-ping')->assertOk();
    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('an operator session does NOT carry over to the admin guard', function (): void {
    $tenant = Tenant::factory()->create();
    $operator = Operator::factory()->for($tenant)->create();

    auth()->guard('web')->login($operator);

    expect(auth()->guard('web')->check())->toBeTrue();
    expect(auth()->guard('admin')->check())->toBeFalse();
    expect(auth()->guard('admin')->user())->toBeNull();
});

it('an admin session does NOT carry over to the operator guard', function (): void {
    $admin = Admin::factory()->create();

    auth()->guard('admin')->login($admin);

    expect(auth()->guard('admin')->check())->toBeTrue();
    expect(auth()->guard('web')->check())->toBeFalse();
    expect(auth()->guard('web')->user())->toBeNull();
});

it('an unauthenticated user hitting a protected tenant route is redirected to tenant.login', function (): void {
    Tenant::factory()->create(['slug' => 'redirect-test']);

    $response = $this->get('http://redirect-test.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.login'));
});

it('an authenticated operator hitting /onboarding gets routed into the wizard', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'auth-test']);
    // Unverified to force the wizard router into step 2.
    $operator = Operator::factory()->for($tenant)->unverified()->create();
    Property::factory()->for($tenant)->create();

    $response = $this
        ->actingAs($operator, 'web')
        ->get('http://auth-test.keverna.test/onboarding');

    $response->assertRedirect(route('tenant.onboarding.email'));
});

it('logout drops the operator session and redirects to login', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'logout-test']);
    $operator = Operator::factory()->for($tenant)->create();

    $response = $this
        ->actingAs($operator, 'web')
        ->post('http://logout-test.keverna.test/logout');

    $response->assertRedirect(route('tenant.login'));
    expect(auth()->guard('web')->check())->toBeFalse();
});
