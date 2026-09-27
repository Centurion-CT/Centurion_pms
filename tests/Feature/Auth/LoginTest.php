<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    // Bind a tenant so the Livewire component renders inside its scope.
    $this->tenant = Tenant::factory()->create(['slug' => 'login-tests']);
    $this->tenant->makeCurrent();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    RateLimiter::clear('test@x.test|127.0.0.1');
});

it('renders the login page at /login on the tenant subdomain', function (): void {
    $response = $this->get('http://login-tests.keverna.test/login');

    $response->assertOk();
    $response->assertSeeLivewire(Login::class);
});

it('signs the operator in with valid credentials and redirects to onboarding', function (): void {
    Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
        'password' => Hash::make('correct-horse'),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect(route('tenant.onboarding'));

    expect(Auth::guard('web')->check())->toBeTrue();
});

it('rejects an unknown email', function (): void {
    Livewire::test(Login::class)
        ->set('email', 'ghost@x.test')
        ->set('password', 'anything9')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('rejects a wrong password', function (): void {
    Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
        'password' => Hash::make('correct-horse'),
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@x.test')
        ->set('password', 'wrong-pass')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('updates last_login_at on successful login', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
        'password' => Hash::make('correct-horse'),
        'last_login_at' => null,
    ]);

    Livewire::test(Login::class)
        ->set('email', 'test@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate');

    expect($operator->fresh()->last_login_at)->not->toBeNull();
});

it('locks out after 5 failed attempts within a minute (PRD §2)', function (): void {
    Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
        'password' => Hash::make('correct-horse'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)
            ->set('email', 'test@x.test')
            ->set('password', 'wrong-pass-'.$i)
            ->call('authenticate')
            ->assertHasErrors(['email']);
    }

    // 6th attempt is throttled regardless of credentials.
    Livewire::test(Login::class)
        ->set('email', 'test@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('isolates login across tenants — an operator from tenant A cannot log in on tenant B', function (): void {
    Tenant::forgetCurrent();

    $tenantA = Tenant::factory()->create(['slug' => 'co-a']);
    $tenantB = Tenant::factory()->create(['slug' => 'co-b']);

    // Create operator on tenant A.
    $tenantA->makeCurrent();
    Operator::factory()->for($tenantA)->create([
        'email' => 'shared@x.test',
        'password' => Hash::make('right-pass-9'),
    ]);

    // Switch to tenant B and try the same credentials.
    Tenant::forgetCurrent();
    $tenantB->makeCurrent();

    Livewire::test(Login::class)
        ->set('email', 'shared@x.test')
        ->set('password', 'right-pass-9')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('regenerates the session id on successful login (PRD §10 session fixation)', function (): void {
    Operator::factory()->for($this->tenant)->create([
        'email' => 'test@x.test',
        'password' => Hash::make('correct-horse'),
    ]);

    $oldId = session()->getId();

    Livewire::test(Login::class)
        ->set('email', 'test@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate');

    expect(session()->getId())->not->toBe($oldId);
});
