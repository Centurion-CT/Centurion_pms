<?php

declare(strict_types=1);

use App\Enums\ConsoleRole;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Settings\Business;
use App\Models\ConsoleUser;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\BusinessColor;
use Livewire\Livewire;

/**
 * The staff sign-in pages lead with the business: its name, its web address
 * and its colour, with Centurion's used only until it has chosen one.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'auth-pages', 'name' => 'Marina Lodge']);
    $this->tenant->makeCurrent();
    $this->branch = Property::factory()->for($this->tenant)->create();
});

afterEach(fn () => Tenant::forgetCurrent());

it('tells a colour that can carry white text from one that cannot', function (string $color, bool $usable): void {
    expect(BusinessColor::carriesWhiteText($color))->toBe($usable);
})->with([
    'dark teal' => ['#0F6B5C', true],
    'logo blue' => ['#336799', true],
    'pale yellow' => ['#F2D94E', false],
    'white' => ['#FFFFFF', false],
    'not a colour' => ['teal', false],
]);

it('leads with the business, and asks everyone to sign in rather than the front desk', function (): void {
    $this->get('http://auth-pages.keverna.test/login')
        ->assertOk()
        ->assertSee('Sign in to Marina Lodge')
        ->assertSee('auth-pages.keverna.test')
        ->assertSee('Runs on Centurion')
        ->assertDontSee('front desk');
});

it('shows solid bars until the business has a photo', function (): void {
    $this->get('http://auth-pages.keverna.test/login')
        ->assertSee('cx-bars-solid')
        ->assertDontSee('login-default.jpg');
});

it('uses the business colour once it has chosen one', function (): void {
    $this->branch->forceFill(['primary_color' => '#0F6B5C'])->save();

    $this->get('http://auth-pages.keverna.test/login')->assertSee('style="--cx-panel: #0f6b5c; --cx-action: #0f6b5c;"', escape: false);
});

it('keeps Centurion colours for the sign-up default and for pale colours', function (string $stored): void {
    $this->branch->forceFill(['primary_color' => $stored])->save();

    $this->get('http://auth-pages.keverna.test/login')->assertDontSee('style="--cx-panel', escape: false);
})->with([BusinessColor::UNSET, '#F2D94E']);

it('saves the colour for every branch, and clears it back to the default', function (): void {
    $second = Property::factory()->for($this->tenant)->create();
    $manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $page = Livewire::actingAs($manager, 'web')->test(Business::class)
        ->set('brandColor', '#0F6B5C')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->branch->fresh()->primary_color)->toBe('#0f6b5c')
        ->and($second->fresh()->primary_color)->toBe('#0f6b5c');

    $page->set('brandColor', '')->call('save')->assertHasNoErrors();

    expect($this->branch->fresh()->primary_color)->toBe(BusinessColor::UNSET);
});

it('refuses a colour too pale for white text', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    Livewire::actingAs($manager, 'web')->test(Business::class)
        ->set('brandColor', '#F2D94E')
        ->call('save')
        ->assertHasErrors('brandColor');
});

it('gives corporate customers the same sign-in, with no status claims and a way to reach the business', function (): void {
    $this->tenant->forceFill(['phone' => '0803 000 2222'])->save();

    $this->get('http://auth-pages.keverna.test/corporates/login')
        ->assertOk()
        ->assertSee('Sign in to Marina Lodge')
        ->assertSee('cx-login')
        ->assertSee('Contact Marina Lodge on 0803 000 2222')
        ->assertDontSee('All systems operational')
        ->assertDontSee('keverna.com');
});

it('sets the console apart while signed out', function (): void {
    Tenant::forgetCurrent();
    ConsoleUser::query()->create(['name' => 'Owner', 'email' => 'owner@centurion.test', 'password' => 'E2e-Console-Pass-2026', 'role' => ConsoleRole::Owner]);

    $this->get('http://console.keverna.test/login')
        ->assertOk()
        ->assertSee('class="guest"', escape: false)
        ->assertSee('Business staff sign in at their own address');
});
