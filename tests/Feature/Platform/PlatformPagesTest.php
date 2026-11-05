<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\TenantStatus;
use App\Livewire\Platform\FindBusiness;
use App\Livewire\Platform\SignUp;
use App\Mail\Operators\OperatorWelcomeMail;
use App\Mail\Platform\LoginLinksMail;
use App\Mail\Platform\SignUpCodeMail;
use App\Models\Operator;
use App\Models\Property;
use App\Models\SlugRedirect;
use App\Models\Tenant;
use App\Platform\BusinessAddress;
use App\Platform\SignUpCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Platform pages on the central domain (docs/MULTITENANCY.md, step 3).
 * phpunit: TENANCY_MODE=domains, CENTRAL_DOMAIN=keverna.test.
 */

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/** Walk the sign-up to the code step and return the emailed code. */
function signUpToCode(array $business = [], array $account = []): array
{
    $component = Livewire::test(SignUp::class)
        ->set('name', $business['name'] ?? 'Sunrise Court')
        ->set('slug', $business['slug'] ?? 'sunrise-court')
        ->set('kind', $business['kind'] ?? 'serviced')
        ->set('city', $business['city'] ?? 'Abuja')
        ->set('size', '11–30')
        ->call('submitBusiness')
        ->assertHasNoErrors()
        ->set('managerName', $account['name'] ?? 'Chidi Okeke')
        ->set('email', $account['email'] ?? 'chidi@sunrisecourt.ng')
        ->set('phone', '0803 111 2222')
        ->set('password', 'a-long-password')
        ->call('submitAccount')
        ->assertHasNoErrors()
        ->assertSet('step', 'code')
        ->assertSet('password', '');

    $code = null;
    Mail::assertSent(SignUpCodeMail::class, function (SignUpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return [$component, $code];
}

it('serves the platform home with the address picker', function (): void {
    $this->get('http://keverna.test/')
        ->assertOk()
        ->assertSee('Run your apartments from one desk')
        ->assertSee('Choose your address to start')
        ->assertSee('address-check');
});

it('says whether an address is free, taken or reserved', function (): void {
    Tenant::factory()->create(['slug' => 'grandpalace']);

    $this->getJson('http://keverna.test/address-check?slug=sunrise')
        ->assertOk()->assertJson(['available' => true, 'message' => 'sunrise.keverna.test is free.']);

    $taken = $this->getJson('http://keverna.test/address-check?slug=grandpalace')->assertOk()->json();
    expect($taken['available'])->toBeFalse()
        ->and($taken['suggestions'])->toBe(['grandpalace-abuja', 'grandpalace-lagos'])
        ->and($taken['message'])->toContain('is taken. Try grandpalace-abuja or grandpalace-lagos.');

    $this->getJson('http://keverna.test/address-check?slug=console')->assertJson(['available' => false]);
    $this->getJson('http://keverna.test/address-check?slug=-bad-')->assertJson(['available' => false]);
    $this->getJson('http://keverna.test/address-check?slug=ab')->assertJson(['available' => false]);
});

it("keeps a deleted business's address and a recent rename's old address taken", function (): void {
    Tenant::factory()->create(['slug' => 'closed'])->delete();
    $renamed = Tenant::factory()->create(['slug' => 'new-name']);
    SlugRedirect::query()->create(['from_slug' => 'old-name', 'to_tenant_id' => $renamed->id, 'expires_at' => now()->addDays(30)]);

    expect(app(BusinessAddress::class)->taken('closed'))->toBeTrue()
        ->and(app(BusinessAddress::class)->taken('old-name'))->toBeTrue();
});

it('suggests an address from the business name until one is typed', function (): void {
    Livewire::test(SignUp::class)
        ->set('name', 'The Grand Palace Suites Ltd')
        ->assertSet('slug', 'grand-palace-suites')
        ->set('slug', 'gps')
        ->set('name', 'Something else')
        ->assertSet('slug', 'gps');
});

it('creates the business, its first branch and its manager once the code is right', function (): void {
    [$component, $code] = signUpToCode();

    $component->set('code', $code)->call('confirm')
        ->assertSet('step', 'done')
        ->assertSet('host', 'sunrise-court.keverna.test')
        ->assertSee('https://sunrise-court.keverna.test');

    $tenant = Tenant::query()->where('slug', 'sunrise-court')->firstOrFail();
    expect($tenant->name)->toBe('Sunrise Court')
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->expected_units)->toBe(30);

    $branch = Property::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($branch->city)->toBe('Abuja')->and($branch->type->value)->toBe('serviced');

    $manager = Operator::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
    expect($manager->role)->toBe(OperatorRole::Manager)
        ->and($manager->email)->toBe('chidi@sunrisecourt.ng')
        ->and(Hash::check('a-long-password', $manager->password))->toBeTrue()
        ->and($manager->email_verified_at)->not->toBeNull()
        ->and($manager->activated_at)->not->toBeNull();

    Mail::assertQueued(OperatorWelcomeMail::class);
    expect(session('signup.password_hash'))->toBeNull();
});

it('creates nothing on a wrong code, and locks the code after five wrong tries', function (): void {
    [$component, $code] = signUpToCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    $component->set('code', $wrong)->call('confirm')->assertSet('step', 'code');
    expect($component->get('codeError'))->toContain("doesn't match");
    foreach (range(1, 4) as $_) {
        $component->set('code', $wrong)->call('confirm');
    }
    $component->set('code', $code)->call('confirm')->assertSet('step', 'code');
    expect($component->get('codeError'))->toContain('Too many wrong codes');

    expect(Tenant::query()->where('slug', 'sunrise-court')->exists())->toBeFalse();
});

it('refuses an address that is taken or reserved', function (): void {
    Tenant::factory()->create(['slug' => 'grandpalace']);

    Livewire::test(SignUp::class)
        ->set('name', 'Grand Palace')->set('slug', 'grandpalace')->set('city', 'Abuja')
        ->call('submitBusiness')
        ->assertHasErrors('slug')->assertSet('step', 'business');

    Livewire::test(SignUp::class)
        ->set('name', 'Console')->set('slug', 'console')->set('city', 'Abuja')
        ->call('submitBusiness')
        ->assertHasErrors('slug');
});

it('sends the person back to choose again if the address went while they read their email', function (): void {
    [$component, $code] = signUpToCode();
    Tenant::factory()->create(['slug' => 'sunrise-court']);

    $component->set('code', $code)->call('confirm')->assertSet('step', 'business')->assertHasErrors('slug');
    expect(Tenant::query()->where('slug', 'sunrise-court')->count())->toBe(1);
});

it('waits a minute before sending another code', function (): void {
    [$component] = signUpToCode();

    $component->call('resend');
    expect($component->get('codeError'))->toContain('You can ask for another in');
    Mail::assertSent(SignUpCodeMail::class, 1);

    $this->travel(SignUpCode::RESEND_SECONDS + 1)->seconds();
    $component->call('resend');
    Mail::assertSent(SignUpCodeMail::class, 2);
});

it('never submits sign-up without what was just typed', function (): void {
    // A debounced wire:model holds the value back from a quick submit, and a
    // button disabled by any request swallows the click.
    $this->get('http://keverna.test/signup')
        ->assertSee('wire:model="name" wire:input.debounce.300ms="$refresh"', escape: false)
        ->assertSee('wire:loading.attr="disabled" wire:target="submitBusiness"', escape: false);
});

it('hands the new manager to their own address, signed in', function (): void {
    [$component, $code] = signUpToCode();
    $component->set('code', $code)->call('confirm');
    $manager = Operator::query()->withoutGlobalScopes()->where('email', 'chidi@sunrisecourt.ng')->sole();

    $link = $this->withSession(['signup.operator_id' => $manager->id])
        ->get('http://keverna.test/signup/open')
        ->assertRedirect()
        ->headers->get('Location');

    expect($link)->toStartWith('http://sunrise-court.keverna.test/auth/post-registration?token=');

    $this->get($link)->assertRedirect('http://sunrise-court.keverna.test/onboarding');
    $this->assertAuthenticatedAs($manager, 'web');

    // Sign-up confirmed the email, so nothing stands before the dashboard.
    $this->get('http://sunrise-court.keverna.test/onboarding')->assertRedirect(route('tenant.dashboard'));
    Mail::assertQueued(OperatorWelcomeMail::class, 1);
});

it('hands over once, so nobody can get back in after logging out', function (): void {
    [$component, $code] = signUpToCode();
    $component->set('code', $code)->call('confirm');
    $manager = Operator::query()->withoutGlobalScopes()->where('email', 'chidi@sunrisecourt.ng')->sole();

    $this->withSession(['signup.operator_id' => $manager->id])
        ->get('http://keverna.test/signup/open')
        ->assertRedirectContains('/auth/post-registration');

    expect(session()->has('signup.operator_id'))->toBeFalse();

    $this->get('http://keverna.test/signup/open')->assertRedirect(route('platform.login'));
});

/** A staff account at a new business. */
function centralStaff(string $slug, array $attrs = []): Operator
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)] + array_intersect_key($attrs, ['status' => 1]));

    return Operator::factory()->for($tenant)->create(array_diff_key($attrs, ['status' => 1]) + [
        'email' => 'tobi@example.ng', 'password' => 'a-long-password', 'role' => OperatorRole::Manager, 'activated_at' => now(),
    ]);
}

it('signs staff in from the central login and lands them on their business, ready to use', function (): void {
    $operator = centralStaff('grandpalace');

    $component = Livewire::test(FindBusiness::class)
        ->set('email', 'Tobi@Example.ng')->set('password', 'a-long-password')->call('signIn')
        ->assertHasNoErrors();

    $link = $component->effects['redirect'];
    expect($link)->toStartWith('http://grandpalace.keverna.test/auth/post-registration?token=');

    $this->get($link)->assertRedirect();
    $this->assertAuthenticatedAs($operator, 'web');

    auth()->guard('web')->logout();
    $this->get($link)->assertForbidden();
});

it('shows no sample business on the shared login', function (): void {
    $this->get('http://keverna.test/login')->assertOk()->assertDontSee('grandpalace')->assertSee('Work email');
});

it('never keeps the password in the page', function (): void {
    centralStaff('grandpalace');

    Livewire::test(FindBusiness::class)
        ->set('email', 'tobi@example.ng')->set('password', 'wrong-password')->call('signIn')
        ->assertSet('password', '')
        ->assertDontSee('wrong-password');
});

it('refuses a wrong password and an unknown email with the same words', function (): void {
    centralStaff('grandpalace');

    Livewire::test(FindBusiness::class)->set('email', 'tobi@example.ng')->set('password', 'nope')->call('signIn')
        ->assertHasErrors('signIn')->assertNoRedirect()->assertSee("That email and password don&#039;t match", false);
    Livewire::test(FindBusiness::class)->set('email', 'stranger@example.ng')->set('password', 'nope')->call('signIn')
        ->assertHasErrors('signIn')->assertNoRedirect()->assertSee("That email and password don&#039;t match", false);
});

it('shows a chooser of only the businesses that password opens', function (): void {
    $one = centralStaff('grandpalace');
    $two = centralStaff('sunrise');
    $other = centralStaff('elsewhere', ['password' => 'a-different-password']);

    $component = Livewire::test(FindBusiness::class)
        ->set('email', 'tobi@example.ng')->set('password', 'a-long-password')->call('signIn')
        ->assertNoRedirect()
        ->assertSee('Choose a business')
        ->assertSee('Grandpalace')->assertSee('Sunrise')->assertDontSee('Elsewhere')
        ->assertSet('password', '');

    $component->call('open', $two->id);
    expect($component->effects['redirect'])->toStartWith('http://sunrise.keverna.test/auth/post-registration');
});

it('keeps the page working after the password, with a new session id but the same form token', function (): void {
    centralStaff('grandpalace');
    centralStaff('sunrise');
    session()->start();
    $id = session()->getId();
    $token = session()->token();

    Livewire::test(FindBusiness::class)
        ->set('email', 'tobi@example.ng')->set('password', 'a-long-password')->call('signIn')
        ->assertSee('Choose a business');

    expect(session()->getId())->not->toBe($id)
        ->and(session()->token())->toBe($token);
});

it("won't open an account the password didn't open, whatever the browser sends", function (): void {
    centralStaff('grandpalace');
    centralStaff('sunrise');
    $other = centralStaff('elsewhere', ['password' => 'a-different-password']);

    Livewire::test(FindBusiness::class)
        ->set('email', 'tobi@example.ng')->set('password', 'a-long-password')->call('signIn')
        ->call('open', $other->id)
        ->assertNoRedirect()
        ->assertHasErrors('signIn');
});

it('explains a suspended account, a revoked one and a paused business, but only after the right password', function (): void {
    centralStaff('suspended', ['suspended_at' => now()]);
    Livewire::test(FindBusiness::class)->set('email', 'tobi@example.ng')->set('password', 'a-long-password')->call('signIn')
        ->assertNoRedirect()->assertSee('temporarily suspended');
    Livewire::test(FindBusiness::class)->set('email', 'tobi@example.ng')->set('password', 'wrong')->call('signIn')
        ->assertDontSee('temporarily suspended');

    centralStaff('paused', ['email' => 'ada@example.ng', 'status' => TenantStatus::Locked]);
    Livewire::test(FindBusiness::class)->set('email', 'ada@example.ng')->set('password', 'a-long-password')->call('signIn')
        ->assertNoRedirect()->assertSee("isn&#039;t open", false);

    centralStaff('invited', ['email' => 'new@example.ng', 'activated_at' => null]);
    Livewire::test(FindBusiness::class)->set('email', 'new@example.ng')->set('password', 'a-long-password')->call('signIn')
        ->assertNoRedirect()->assertSee('activated');
});

it('skips a paused business and goes straight to the open one', function (): void {
    centralStaff('paused', ['status' => TenantStatus::Locked]);
    centralStaff('grandpalace');

    $component = Livewire::test(FindBusiness::class)->set('email', 'tobi@example.ng')->set('password', 'a-long-password')->call('signIn');

    expect($component->effects['redirect'])->toStartWith('http://grandpalace.keverna.test/');
});

it('stops after five wrong passwords, even with the right one', function (): void {
    centralStaff('grandpalace');
    $component = Livewire::test(FindBusiness::class)->set('email', 'tobi@example.ng');
    foreach (range(1, 5) as $_) {
        $component->set('password', 'wrong')->call('signIn');
    }

    $component->set('password', 'a-long-password')->call('signIn')->assertNoRedirect()->assertHasErrors('signIn');
});

it('refuses a handover link once the account can no longer sign in', function (): void {
    $operator = centralStaff('grandpalace');
    $link = app(App\Services\PostRegistrationLoginLink::class)->generate($operator->tenant, $operator);
    $operator->forceFill(['suspended_at' => now()])->save();

    $this->get($link)->assertForbidden();
    $this->assertGuest('web');
});

it('sends someone to their business login by address', function (): void {
    Tenant::factory()->create(['slug' => 'grandpalace']);

    Livewire::test(FindBusiness::class)->set('slug', 'grandpalace')->call('go')
        ->assertRedirect('http://grandpalace.keverna.test/login');

    Livewire::test(FindBusiness::class)->set('slug', 'sunrise')->call('go')
        ->assertHasErrors('slug')->assertNoRedirect();
});

it('emails one link per business the address works at, and nothing for strangers', function (): void {
    $alpha = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Suites']);
    $bravo = Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Homes']);
    $paused = Tenant::factory()->create(['slug' => 'paused', 'status' => TenantStatus::Locked]);
    foreach ([$alpha, $bravo, $paused] as $tenant) {
        Operator::factory()->for($tenant)->create(['email' => 'tobi@example.ng', 'activated_at' => now()]);
    }

    Livewire::test(FindBusiness::class)->set('email', 'Tobi@Example.ng')->call('sendLinks')
        ->assertSet('sent', true);

    Mail::assertSent(LoginLinksMail::class, function (LoginLinksMail $mail): bool {
        $urls = array_column($mail->businesses, 'url');
        sort($urls);

        return $mail->hasTo('tobi@example.ng') && $urls === [
            'http://alpha.keverna.test/login?email=tobi%40example.ng',
            'http://bravo.keverna.test/login?email=tobi%40example.ng',
        ];
    });

    Livewire::test(FindBusiness::class)->set('email', 'nobody@example.ng')->call('sendLinks')
        ->assertSet('sent', true);
    Mail::assertSent(LoginLinksMail::class, 1);
});

it('prefills the staff login from a login link', function (): void {
    Tenant::factory()->create(['slug' => 'alpha']);

    $this->get('http://alpha.keverna.test/login?email=tobi%40example.ng')
        ->assertOk()->assertSee('tobi@example.ng');
});

it('turns sign-up away while it is closed', function (): void {
    config()->set('keverna.signup_open', false);

    $this->get('http://keverna.test/signup?slug=sunrise')
        ->assertOk()
        ->assertSee("Sign-up isn't open yet", false)
        ->assertSee('mailto:hello@keverna.test', false);

    $this->get('http://keverna.test/')
        ->assertOk()
        ->assertSee('Sign-up opens soon')
        ->assertDontSee('Choose your address to start')
        ->assertDontSee('http://keverna.test/signup', false);
});

it('creates nothing from a sign-up page left open after sign-up closes', function (): void {
    [$component, $code] = signUpToCode();
    config()->set('keverna.signup_open', false);

    $component->set('code', $code)->call('confirm')->assertSet('step', 'code');

    expect(Tenant::query()->where('slug', 'sunrise-court')->exists())->toBeFalse();
});
