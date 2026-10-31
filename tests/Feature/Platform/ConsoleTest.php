<?php

declare(strict_types=1);

use App\Enums\ConsoleRole;
use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureConsoleAdmin;
use App\Livewire\Console\AcceptInvite;
use App\Livewire\Console\Businesses;
use App\Livewire\Console\Login;
use App\Livewire\Console\Setup;
use App\Livewire\Console\Users;
use App\Mail\Platform\ConsoleCodeMail;
use App\Mail\Platform\ConsoleInviteMail;
use App\Models\Activity;
use App\Models\ConsoleUser;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * The platform owner's console on console.{central domain}
 * (docs/MULTITENANCY.md, step 7).
 */

beforeEach(function (): void {
    Mail::fake();
    config()->set('keverna.console_setup_key', 'setup-key-123');

    $this->ready = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Suites', 'paystack_subaccount_code' => 'ACCT_a']);
    $this->ready->makeCurrent();
    $branch = Property::factory()->live()->for($this->ready)->create();
    RoomCategory::factory()->for($this->ready)->for($branch)->create();
    Tenant::forgetCurrent();

    $this->fresh = Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Homes', 'paystack_secret_key' => null]);
});

afterEach(fn () => Tenant::forgetCurrent());

function consoleUser(ConsoleRole $role = ConsoleRole::Owner, string $email = 'owner@centurion.test'): ConsoleUser
{
    return ConsoleUser::query()->create(['name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'password' => 'a-long-password-1', 'role' => $role]);
}

function signedIn(ConsoleUser $user): void
{
    session([EnsureConsoleAdmin::SESSION_KEY => $user->id]);
}

/** The code in the last console code email. */
function lastConsoleCode(): string
{
    $code = '';
    Mail::assertSent(ConsoleCodeMail::class, function (ConsoleCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    return $code;
}

it('creates the first owner with the setup key and an emailed code, then closes setup', function (): void {
    $this->get('http://console.keverna.test/')->assertRedirect(route('console.setup'));

    $setup = Livewire::test(Setup::class)
        ->set('setupKey', 'wrong')->set('name', 'Ifeanyi')->set('email', 'Owner@Centurion.test')
        ->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')
        ->call('submit')->assertHasErrors('setupKey')
        ->set('setupKey', 'setup-key-123')->call('submit')->assertHasNoErrors()->assertSet('step', 'code')
        ->assertSet('password', '');

    $setup->set('code', lastConsoleCode())->call('confirm')->assertRedirect(route('console.businesses'));

    $owner = ConsoleUser::query()->sole();
    expect($owner->email)->toBe('owner@centurion.test')
        ->and($owner->role)->toBe(ConsoleRole::Owner)
        ->and(Hash::check('a-long-password-1', $owner->password))->toBeTrue();

    $this->get('http://console.keverna.test/setup')->assertNotFound();
});

it('serves the console setup page, not the install wizard, on the console address', function (): void {
    $this->get('http://console.keverna.test/setup')->assertOk()->assertSee('Set up the console');
});

it('keeps setup closed without a setup key', function (): void {
    config()->set('keverna.console_setup_key', '');

    $this->get('http://console.keverna.test/setup')->assertNotFound();
});

it('signs in with password and then an emailed code', function (): void {
    $user = consoleUser();

    $login = Livewire::test(Login::class)->set('email', 'owner@centurion.test')->set('password', 'wrong-password')
        ->call('check')->assertSet('error', 'Email or password is wrong.');
    Mail::assertNothingSent();

    $login->set('password', 'a-long-password-1')->call('check')->assertSet('userId', $user->id);
    $login->set('code', lastConsoleCode())->call('verify')->assertRedirect(route('console.businesses'));

    expect(session(EnsureConsoleAdmin::SESSION_KEY))->toBe($user->id)
        ->and($user->refresh()->last_signed_in_at)->not->toBeNull();
});

it('keeps everyone else out of the console', function (): void {
    consoleUser();
    $this->get('http://console.keverna.test/')->assertRedirect(route('console.login'));

    $this->withSession([EnsureConsoleAdmin::SESSION_KEY => 999])
        ->get('http://console.keverna.test/')->assertRedirect(route('console.login'));

    Livewire::test(Businesses::class)->assertForbidden();
});

it('lists every business with its set-up and what needs a look', function (): void {
    $owner = consoleUser();

    $this->withSession([EnsureConsoleAdmin::SESSION_KEY => $owner->id])
        ->get('http://console.keverna.test/')
        ->assertOk()
        ->assertSee('Alpha Suites')->assertSee('alpha.keverna.test')
        ->assertSee('Bravo Homes')->assertSee('No apartment types yet')
        ->assertSee("Payments not set up: paid into the platform's Paystack account");

    signedIn($owner);
    Livewire::test(Businesses::class)->set('show', 'look')
        ->assertSee('Bravo Homes')->assertDontSee('Alpha Suites');
});

it('lets an admin pause and resume a business, and records who did it', function (): void {
    signedIn(consoleUser(ConsoleRole::Admin, 'admin@centurion.test'));

    Livewire::test(Businesses::class)->call('pause', $this->ready->id);
    expect($this->ready->refresh()->status)->toBe(TenantStatus::Locked);
    $this->get('http://alpha.keverna.test/login')->assertStatus(503);

    Livewire::test(Businesses::class)->call('resume', $this->ready->id);
    expect($this->ready->refresh()->status)->toBe(TenantStatus::Active);

    $log = Activity::query()->withoutGlobalScopes()->where('log_name', 'console')->orderBy('id')->get();
    expect($log->pluck('event')->all())->toBe(['paused', 'resumed'])
        ->and($log->first()->tenant_id)->toBe($this->ready->id)
        ->and($log->first()->properties['admin'])->toBe('admin@centurion.test');
});

it('lets an owner invite a user, who sets a password from the link and can sign in', function (): void {
    signedIn(consoleUser());

    Livewire::test(Users::class)->set('name', 'Tobi')->set('email', 'Tobi@Centurion.test')->set('role', 'admin')
        ->call('invite')->assertHasNoErrors();

    $url = null;
    Mail::assertSent(ConsoleInviteMail::class, function (ConsoleInviteMail $m) use (&$url): bool {
        $url = $m->url;

        return $m->hasTo('tobi@centurion.test') && ! $m->reset;
    });
    $tobi = ConsoleUser::query()->where('email', 'tobi@centurion.test')->sole();
    expect($tobi->isActive())->toBeFalse()->and($tobi->role)->toBe(ConsoleRole::Admin);

    $token = basename((string) parse_url($url, PHP_URL_PATH));
    Livewire::test(AcceptInvite::class, ['token' => $token])
        ->set('password', 'tobis-long-password')->set('password_confirmation', 'tobis-long-password')
        ->call('save')->assertRedirect(route('console.login'));

    expect($tobi->refresh()->isActive())->toBeTrue()
        ->and(ConsoleUser::forInvite($token))->toBeNull();
});

it('keeps user management to owners', function (): void {
    $admin = consoleUser(ConsoleRole::Admin, 'admin@centurion.test');

    $this->withSession([EnsureConsoleAdmin::SESSION_KEY => $admin->id])
        ->get('http://console.keverna.test/users')->assertForbidden();
    signedIn($admin);
    Livewire::test(Users::class)->assertForbidden();
});

it('always keeps an owner, and nobody removes or demotes themselves', function (): void {
    $me = consoleUser();
    $other = consoleUser(ConsoleRole::Owner, 'second@centurion.test');
    signedIn($me);

    Livewire::test(Users::class)->call('remove', $me->id)->assertForbidden();
    Livewire::test(Users::class)->call('changeRole', $other->id, 'admin');
    expect($other->refresh()->role)->toBe(ConsoleRole::Admin);

    Livewire::test(Users::class)->call('remove', $other->id);
    expect(ConsoleUser::query()->pluck('email')->all())->toBe(['owner@centurion.test']);
});

it('resets a password: the old one stops working and a link goes out', function (): void {
    $other = consoleUser(ConsoleRole::Admin, 'admin@centurion.test');
    signedIn(consoleUser());

    Livewire::test(Users::class)->call('resetPassword', $other->id);

    expect($other->refresh()->isActive())->toBeFalse();
    Mail::assertSent(ConsoleInviteMail::class, fn (ConsoleInviteMail $m) => $m->hasTo('admin@centurion.test') && $m->reset);
});

it('prints a recovery link for whoever runs the servers', function (): void {
    expect(Artisan::call('console:invite', ['email' => 'rescue@centurion.test', '--owner' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('/invite/');

    expect(ConsoleUser::query()->where('email', 'rescue@centurion.test')->sole()->role)->toBe(ConsoleRole::Owner);
});

it('is not on a business address', function (): void {
    $this->get('http://alpha.keverna.test/users')->assertNotFound();
});
