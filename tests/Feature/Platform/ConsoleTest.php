<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\TenantStatus;
use App\Http\Middleware\EnsureConsoleAdmin;
use App\Livewire\Console\Businesses;
use App\Livewire\Console\Login;
use App\Mail\Platform\ConsoleCodeMail;
use App\Models\Activity;
use App\Models\Operator;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * The platform owner's console on console.{central domain}
 * (docs/MULTITENANCY.md, step 7).
 */

beforeEach(function (): void {
    Mail::fake();
    config()->set('keverna.console_admins', 'owner@centurion.test, Second@Centurion.test');

    $this->ready = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Suites', 'paystack_subaccount_code' => 'ACCT_a']);
    $this->ready->makeCurrent();
    $branch = Property::factory()->live()->for($this->ready)->create();
    RoomCategory::factory()->for($this->ready)->for($branch)->create();
    Tenant::forgetCurrent();

    $this->fresh = Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Homes', 'paystack_secret_key' => null]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('emails a sign-in code only to console admins, and says the same to anyone', function (): void {
    Livewire::test(Login::class)->set('email', 'stranger@example.ng')->call('send')->assertSet('sent', true);
    Mail::assertNothingSent();

    Livewire::test(Login::class)->set('email', 'second@centurion.test')->call('send')->assertSet('sent', true);
    Mail::assertSent(ConsoleCodeMail::class, fn (ConsoleCodeMail $m) => $m->hasTo('second@centurion.test'));
});

it('signs an admin in with the emailed code', function (): void {
    $code = null;
    $component = Livewire::test(Login::class)->set('email', 'owner@centurion.test')->call('send');
    Mail::assertSent(ConsoleCodeMail::class, function (ConsoleCodeMail $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    $component->set('code', $code === '000000' ? '111111' : '000000')->call('verify')->assertSet('error', "That code doesn't match.");
    $component->set('code', $code)->call('verify')->assertRedirect(route('console.businesses'));

    expect(session(EnsureConsoleAdmin::SESSION_KEY))->toBe('owner@centurion.test');
});

it('keeps everyone else out of the console', function (): void {
    $this->get('http://console.keverna.test/')->assertRedirect(route('console.login'));

    $this->withSession([EnsureConsoleAdmin::SESSION_KEY => 'removed@centurion.test'])
        ->get('http://console.keverna.test/')->assertRedirect(route('console.login'));

    Livewire::withHeaders([])->test(Businesses::class)->assertForbidden();
});

it('lists every business with its set-up and what needs a look', function (): void {
    $this->withSession([EnsureConsoleAdmin::SESSION_KEY => 'owner@centurion.test'])
        ->get('http://console.keverna.test/')
        ->assertOk()
        ->assertSee('Alpha Suites')->assertSee('alpha.keverna.test')
        ->assertSee('Bravo Homes')->assertSee('No apartment types yet')
        ->assertSee("Payments not set up: paid into the platform's Paystack account");

    session([EnsureConsoleAdmin::SESSION_KEY => 'owner@centurion.test']);
    Livewire::test(Businesses::class)->set('show', 'look')
        ->assertSee('Bravo Homes')->assertDontSee('Alpha Suites');
});

it('pauses and resumes a business, and records who did it', function (): void {
    session([EnsureConsoleAdmin::SESSION_KEY => 'owner@centurion.test']);

    Livewire::test(Businesses::class)->call('pause', $this->ready->id);
    expect($this->ready->refresh()->status)->toBe(TenantStatus::Locked);
    $this->get('http://alpha.keverna.test/login')->assertStatus(503);

    Livewire::test(Businesses::class)->call('resume', $this->ready->id);
    expect($this->ready->refresh()->status)->toBe(TenantStatus::Active);

    $log = Activity::query()->withoutGlobalScopes()->where('log_name', 'console')->orderBy('id')->get();
    expect($log->pluck('event')->all())->toBe(['paused', 'resumed'])
        ->and($log->first()->tenant_id)->toBe($this->ready->id)
        ->and($log->first()->properties['admin'])->toBe('owner@centurion.test');
});

it('is not on a business address', function (): void {
    Operator::factory()->for($this->ready)->create(['role' => OperatorRole::Manager]);

    $this->get('http://alpha.keverna.test/console')->assertNotFound();
});
