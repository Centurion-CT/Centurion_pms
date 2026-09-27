<?php

declare(strict_types=1);

use App\Enums\CustomerLifecycle;
use App\Models\Customer;
use App\Models\IdentityAccount;
use App\Models\IdentityGuestLink;
use App\Models\Tenant;
use App\Services\Identity\CrossTenantCustomerMerger;
use Illuminate\Support\Facades\Hash;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

function makeCustomerForMerger(Tenant $tenant, string $email): Customer
{
    $tenant->makeCurrent();
    $c = Customer::create([
        'name' => 'Guest',
        'email' => $email,
        'lifecycle' => CustomerLifecycle::Prospect,
    ]);
    Tenant::forgetCurrent();

    return $c;
}

it('merges customers from every tenant sharing the identity email', function (): void {
    $tenantA = Tenant::factory()->create(['slug' => 'merge-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'merge-b']);
    $tenantC = Tenant::factory()->create(['slug' => 'merge-c']);

    makeCustomerForMerger($tenantA, 'guest@x.test');
    makeCustomerForMerger($tenantB, 'guest@x.test');
    // Unrelated customer on tenant C — same tenant, different email.
    makeCustomerForMerger($tenantC, 'someone-else@x.test');

    $identity = IdentityAccount::create([
        'email' => 'guest@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Guest',
    ]);

    $created = (new CrossTenantCustomerMerger)->mergeFor($identity);

    expect($created)->toBe(2);
    expect(IdentityGuestLink::count())->toBe(2);
    expect($identity->guestLinks()->pluck('tenant_id')->sort()->values()->all())
        ->toBe([$tenantA->id, $tenantB->id]);
});

it('is idempotent — re-running does not duplicate links', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'merge-idem']);
    makeCustomerForMerger($tenant, 'idem@x.test');

    $identity = IdentityAccount::create([
        'email' => 'idem@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Idem',
    ]);

    $merger = new CrossTenantCustomerMerger;
    $merger->mergeFor($identity);
    $created = $merger->mergeFor($identity);

    expect($created)->toBe(0);
    expect(IdentityGuestLink::count())->toBe(1);
});

it('matches emails case-insensitively', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'merge-case']);
    // Customer stored with mixed case on tenant.
    makeCustomerForMerger($tenant, 'MixEd@Case.TEST');

    // Identity email normalised at construction time.
    $identity = IdentityAccount::create([
        'email' => IdentityAccount::normaliseEmail('mixed@case.test'),
        'password' => Hash::make('pw'),
        'name' => 'Case',
    ]);

    $created = (new CrossTenantCustomerMerger)->mergeFor($identity);

    expect($created)->toBe(1);
});
