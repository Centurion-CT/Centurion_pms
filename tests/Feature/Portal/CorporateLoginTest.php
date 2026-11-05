<?php

declare(strict_types=1);

use App\Enums\CorporateMemberRole;
use App\Enums\CorporateMemberStatus;
use App\Livewire\Portal\Corporate\Auth\Login;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    RateLimiter::clear('corporate-login:admin@acme.test|127.0.0.1');

    $this->tenant = Tenant::factory()->create(['slug' => 'corp-login']);
    $this->tenant->makeCurrent();

    $corporate = Corporate::query()->create([
        'slug' => 'acme', 'name' => 'Acme', 'status' => 'active',
        'primary_email' => 'ops@acme.test', 'country' => 'NG', 'kyc_status' => 'pending',
    ]);

    $this->admin = new CorporateMember;
    $this->admin->forceFill([
        'corporate_id' => $corporate->id, 'name' => 'Acme Admin', 'email' => 'admin@acme.test',
        'password' => Hash::make('correct-password'),
        'role' => CorporateMemberRole::Admin, 'status' => CorporateMemberStatus::Active,
    ])->save();
});

afterEach(fn () => Tenant::forgetCurrent());

function corporateSignIn(string $email, string $password): mixed
{
    return Livewire::test(Login::class)->set('email', $email)->set('password', $password)->call('authenticate');
}

it('signs a company admin in', function (): void {
    corporateSignIn('admin@acme.test', 'correct-password')->assertHasNoErrors();

    expect(Auth::guard('corporate')->id())->toBe($this->admin->id);
});

it('locks further tries after five wrong passwords, even with the right one', function (): void {
    foreach (range(1, 5) as $try) {
        corporateSignIn('admin@acme.test', 'wrong-password-'.$try)->assertHasErrors('email');
    }

    corporateSignIn('admin@acme.test', 'correct-password')
        ->assertHasErrors('email')
        ->assertSee('Too many login attempts');

    expect(Auth::guard('corporate')->check())->toBeFalse();
});

it('forgets earlier wrong tries after a good sign-in', function (): void {
    foreach (range(1, 4) as $try) {
        corporateSignIn('admin@acme.test', 'wrong-password-'.$try);
    }

    corporateSignIn('admin@acme.test', 'correct-password')->assertHasNoErrors();

    expect(RateLimiter::attempts('corporate-login:admin@acme.test|127.0.0.1'))->toBe(0);
});

it('shows the company letter in the portal, not an old product mark', function (): void {
    $this->actingAs($this->admin, 'corporate')
        ->get('http://corp-login.keverna.test/corporates')
        ->assertOk()
        ->assertDontSee('M3 1.5v9M3 6L9 1.5M3 6l6 4.5', escape: false);
});
