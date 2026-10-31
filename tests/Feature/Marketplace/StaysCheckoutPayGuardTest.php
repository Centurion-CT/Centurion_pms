<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Livewire\Marketplace\StaysBookCheckout;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Pay button on /stays/{slug}/book stays disabled until the guest
 * has entered contact info good enough to pass the server-side
 * Livewire validation. Prevents the "guest clicks Pay, gets a
 * Paystack init, redirects, then bounces back to a validation
 * error" round-trip.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pay-guard-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true, 'name' => 'Pay Guard Suites',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

it('disables the Pay button + shows a nudge label when guest details are blank', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', '')
        ->set('guestEmail', '')
        ->set('guestPhone', '')
        ->assertSee('Fill your details to continue')
        ->assertSet('paymentPreference', 'full')
        ->tap(fn ($c) => expect($c->instance()->isContactComplete())->toBeFalse());
});

it('keeps the Pay button disabled when the email is not shaped like an email', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N.')
        ->set('guestEmail', 'not-an-email')
        ->set('guestPhone', '+2348030000000')
        ->tap(fn ($c) => expect($c->instance()->isContactComplete())->toBeFalse())
        ->assertSee('Fill your details to continue');
});

it('keeps the Pay button disabled when the phone digit-count is under 8', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N.')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '1234567')  // only 7 digits
        ->tap(fn ($c) => expect($c->instance()->isContactComplete())->toBeFalse());
});

it('accepts common phone formatting (spaces, + prefix) when counting digits', function (): void {
    // "+234 803 000 0000" has 13 digits after stripping - passes.
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('guestName', 'Ada N.')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+234 803 000 0000')
        ->tap(fn ($c) => expect($c->instance()->isContactComplete())->toBeTrue());
});

it('enables the Pay button + swaps to "Pay ₦X" label when all guards pass', function (): void {
    $c = Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(3)->toDateString())
        ->set('checkout', Carbon::today()->addDays(4)->toDateString())
        ->set('guestName', 'Ada N.')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+2348030000000');

    expect($c->instance()->isContactComplete())->toBeTrue();
    $bd = $c->instance()->breakdown();
    $c->assertSee(number_format($bd['due_now']))
      ->assertDontSee('Fill your details to continue');
});

it('shows "Pick your dates" ahead of the contact guard when no valid range', function (): void {
    // Contact guard comes AFTER the date guard - so a guest with
    // no dates + no contact info sees the date prompt (bigger
    // decision first). Once they set dates, they'll see the
    // contact nudge.
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', '')
        ->set('checkout', '')
        ->set('guestName', '')
        ->set('guestEmail', '')
        ->set('guestPhone', '')
        ->assertSee('Pick your dates')
        ->assertDontSee('Fill your details to continue');
});
