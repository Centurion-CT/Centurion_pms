<?php

declare(strict_types=1);

use App\Livewire\Central\Chooser;
use App\Livewire\Central\Login;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

afterEach(function (): void {
    Tenant::forgetCurrent();
    RateLimiter::clear('cent@x.test|127.0.0.1');
});

/**
 * Create an identity with one operator link. Returns [identity, link_id].
 *
 * @return array{IdentityAccount, int}
 */
function makeIdentityWithOneLink(string $email = 'cent@x.test', string $password = 'correct-horse'): array
{
    $tenant = Tenant::factory()->create(['slug' => 'central-test-'.uniqid()]);
    $tenant->makeCurrent();
    $operator = Operator::factory()->for($tenant)->create();
    Tenant::forgetCurrent();

    $identity = IdentityAccount::create([
        'email' => $email,
        'password' => Hash::make($password),
        'name' => 'Central Test',
    ]);
    $link = IdentityOperatorLink::create([
        'identity_account_id' => $identity->id,
        'tenant_id' => $tenant->id,
        'operator_id' => $operator->id,
    ]);

    return [$identity, $link->id];
}

it('renders the central login page on the central domain', function (): void {
    $response = $this->get('http://keverna.test/login');

    $response->assertOk();
    $response->assertSeeLivewire(Login::class);
});

it('signs the identity in with valid credentials and redirects to handoff for a single link', function (): void {
    [, $linkId] = makeIdentityWithOneLink();

    Livewire::test(Login::class)
        ->set('email', 'cent@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect(route('central.handoff', ['link' => $linkId]));

    expect(Auth::guard('identity')->check())->toBeTrue();
});

it('rejects the same credentials on wrong password with a generic message', function (): void {
    makeIdentityWithOneLink();

    Livewire::test(Login::class)
        ->set('email', 'cent@x.test')
        ->set('password', 'wrong-horse')
        ->call('authenticate')
        ->assertHasErrors(['email'])
        ->assertNoRedirect();

    expect(Auth::guard('identity')->check())->toBeFalse();
});

it('routes an identity with zero links to the chooser', function (): void {
    IdentityAccount::create([
        'email' => 'nolinks@x.test',
        'password' => Hash::make('correct-horse'),
        'name' => 'No Links',
    ]);
    RateLimiter::clear('nolinks@x.test|127.0.0.1');

    Livewire::test(Login::class)
        ->set('email', 'nolinks@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect(route('central.choose'));

    RateLimiter::clear('nolinks@x.test|127.0.0.1');
});

it('normalises email casing at login', function (): void {
    [, $linkId] = makeIdentityWithOneLink('mixed@case.test');
    RateLimiter::clear('MIXED@CASE.TEST|127.0.0.1');

    Livewire::test(Login::class)
        ->set('email', 'MIXED@CASE.TEST')
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect(route('central.handoff', ['link' => $linkId]));

    RateLimiter::clear('mixed@case.test|127.0.0.1');
});

it('throttles after 5 failed attempts', function (): void {
    makeIdentityWithOneLink();

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)
            ->set('email', 'cent@x.test')
            ->set('password', 'wrong')
            ->call('authenticate');
    }

    Livewire::test(Login::class)
        ->set('email', 'cent@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertHasErrors(['email']);

    expect(Auth::guard('identity')->check())->toBeFalse();
});

it('stamps last_login_at on the identity on successful login', function (): void {
    [$identity] = makeIdentityWithOneLink();

    expect($identity->last_login_at)->toBeNull();

    Livewire::test(Login::class)
        ->set('email', 'cent@x.test')
        ->set('password', 'correct-horse')
        ->call('authenticate');

    expect($identity->fresh()->last_login_at)->not->toBeNull();
});

it('redirects an already-authenticated identity away from /login (guest:identity middleware)', function (): void {
    [$identity] = makeIdentityWithOneLink();
    Auth::guard('identity')->login($identity);

    $response = $this->get('http://keverna.test/login');

    // guest:identity redirects authenticated users. Default is /home;
    // we accept any redirect away from /login as proof the middleware fired.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/login');
});
