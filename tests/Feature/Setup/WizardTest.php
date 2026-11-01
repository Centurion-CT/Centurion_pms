<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\TenantStatus;
use App\Livewire\Setup\Wizard;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * /setup fires once per install. It's the only surface accessible on
 * a fresh DB, and every other route bounces here via the
 * NoCurrentTenant handler in bootstrap/app.php.
 *
 * These tests cover: (1) the wizard renders on empty DB,
 * (2) submitting the form creates Tenant + Property + Operator
 * atomically and logs the operator in, (3) an already-installed
 * install 404s the wizard route, and (4) invalid input surfaces
 * field-level errors without seeding partial state.
 */
beforeEach(function (): void {
    // Every scenario starts with a completely empty DB; this is
    // the fresh-install path. No tenant, no anything.
});

afterEach(function (): void {
    // Tenant::forgetCurrent() isn't strictly needed (HostTenantFinder
    // memoises on the request boundary and each test builds a fresh
    // Livewire request) but the SaaS-era test conventions did this
    // explicitly, so keep the pattern.
    Tenant::forgetCurrent();
});

it('renders on a fresh install', function (): void {
    // assertSee escapes the apostrophe by default; disable escaping
    // so the literal HTML "Let's set your hotel up." matches.
    Livewire::test(Wizard::class)
        ->assertOk()
        ->assertSee("Let's set your hotel up.", escape: false)
        ->assertSee('setup-hotel-name', escape: false);
});

it('aborts 404 when the install is already complete', function (): void {
    Tenant::query()->create([
        'name' => 'Existing Hotel',
        'slug' => 'existing-hotel',
        'email' => 'owner@existing.test',
        'status' => TenantStatus::Active->value,
    ]);

    Livewire::test(Wizard::class)
        ->assertStatus(404);
});

it('seeds Tenant + Property + Operator on submit + logs the operator in', function (): void {
    Livewire::test(Wizard::class)
        ->set('hotelName', 'Citistay Apartments')
        ->set('propertyName', 'Main Building')
        ->set('propertyType', 'hotel')
        ->set('propertyAddress', 'Plot 12, Adetokunbo Ademola Crescent')
        ->set('propertyCity', 'Abuja')
        ->set('propertyState', 'FCT')
        ->set('managerName', 'Ada Manager')
        ->set('managerEmail', 'ada@citistay.example')
        ->set('managerPassword', 'correct-horse-battery')
        ->set('managerPasswordConfirmation', 'correct-horse-battery')
        ->call('submit')
        ->assertRedirect(route('tenant.dashboard'));

    // Exactly one row of each table + all owned by the singleton.
    expect(Tenant::query()->count())->toBe(1);
    expect(Property::query()->withoutGlobalScopes()->count())->toBe(1);
    expect(Operator::query()->withoutGlobalScopes()->count())->toBe(1);

    $tenant = Tenant::query()->first();
    expect($tenant->name)->toBe('Citistay Apartments');
    expect($tenant->slug)->toBe('citistay-apartments');
    expect($tenant->status)->toBe(TenantStatus::Active);
    expect($tenant->email)->toBe('ada@citistay.example');

    $property = Property::query()->withoutGlobalScopes()->first();
    expect($property->tenant_id)->toBe($tenant->id);
    expect($property->name)->toBe('Main Building');
    expect($property->address)->toBe('Plot 12, Adetokunbo Ademola Crescent');
    expect($property->city)->toBe('Abuja');
    expect($property->state)->toBe('FCT');

    $operator = Operator::query()->withoutGlobalScopes()->first();
    expect($operator->tenant_id)->toBe($tenant->id);
    expect($operator->name)->toBe('Ada Manager');
    expect($operator->email)->toBe('ada@citistay.example');
    expect($operator->role)->toBe(OperatorRole::Manager);
    expect($operator->email_verified_at)->not->toBeNull();
    // Activated up front — otherwise the login gate rejects them after logout.
    expect($operator->activated_at)->not->toBeNull();
    expect($operator->canSignIn())->toBeTrue();

    // Password stored hashed, not plaintext.
    expect(\Illuminate\Support\Facades\Hash::check('correct-horse-battery', $operator->password))->toBeTrue();

    // Operator was logged in via the web guard.
    expect(auth('web')->id())->toBe($operator->id);
});

it('refuses partial input + does not touch the DB when validation fails', function (): void {
    Livewire::test(Wizard::class)
        ->set('hotelName', '')  // required
        ->set('managerEmail', 'not-an-email')  // invalid
        ->set('managerPassword', 'short')  // too short
        ->call('submit')
        ->assertHasErrors([
            'hotelName' => 'required',
            'propertyName' => 'required',
            'propertyAddress' => 'required',
            'managerName' => 'required',
            'managerEmail' => 'email',
            'managerPassword' => 'min',
        ]);

    expect(Tenant::query()->count())->toBe(0);
    expect(Property::query()->withoutGlobalScopes()->count())->toBe(0);
    expect(Operator::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('requires the password confirmation to match', function (): void {
    Livewire::test(Wizard::class)
        ->set('hotelName', 'Test Hotel')
        ->set('propertyName', 'Main')
        ->set('propertyType', 'hotel')
        ->set('propertyAddress', 'Somewhere')
        ->set('managerName', 'Someone')
        ->set('managerEmail', 'a@b.test')
        ->set('managerPassword', 'correct-horse-battery')
        ->set('managerPasswordConfirmation', 'DIFFERENT-battery')
        ->call('submit')
        ->assertHasErrors(['managerPassword' => 'same']);

    expect(Tenant::query()->count())->toBe(0);
});
