<?php

declare(strict_types=1);

use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Hash;

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('normalises email — lowercases and trims', function (): void {
    expect(IdentityAccount::normaliseEmail('  Foo@Example.COM  '))->toBe('foo@example.com');
});

it('finds an identity for login by normalised email', function (): void {
    IdentityAccount::create([
        'email' => 'ada@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Ada',
    ]);

    expect(IdentityAccount::findForLogin('ADA@x.TEST'))->not->toBeNull();
    expect(IdentityAccount::findForLogin('nope@x.test'))->toBeNull();
});

it('exposes operator links via the relationship', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'rel']);
    $tenant->makeCurrent();
    $operator = Operator::factory()->for($tenant)->create(['email' => 'z@x.test']);
    Tenant::forgetCurrent();

    $identity = IdentityAccount::create([
        'email' => 'z@x.test',
        'password' => Hash::make('pw'),
        'name' => 'Z',
    ]);

    IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant->id,
        'operator_id' => $operator->id,
    ]);

    expect($identity->operatorLinks)->toHaveCount(1);
    expect($identity->operatorLinks->first()->operator->email)->toBe('z@x.test');
    expect($identity->operatorLinks->first()->tenant->slug)->toBe('rel');
});
