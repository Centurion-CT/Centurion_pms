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
 * Every date input across the marketplace must:
 *   - reject past dates
 *   - reject same-day checkout (checkout must be > check-in)
 *   - reject checkout < check-in
 * Enforced both client-side (native picker's min attribute) and
 * server-side (controllers + Livewire's pay() validation). Tests
 * here pin the server side + the min-attribute markup.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'dates-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Dates Test Property',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    // The business stays current: these pages only run on its own address.
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders min=today on every check-in input on /stays/{slug}', function (): void {
    $today = Carbon::today()->toDateString();

    $response = $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]));
    $response->assertOk();
    // Sticky availability bar
    $response->assertSee('data-testid="availbar-from"', false);
    $response->assertSee('min="'.$today.'"', false);
    // Sticky book card
    $response->assertSee('data-testid="book-from"', false);
});

it('renders min=check-in+1 on the check-out input when the URL carries a check-in', function (): void {
    $ci = Carbon::today()->addDays(3)->toDateString();
    $expectedCoMin = Carbon::today()->addDays(4)->toDateString();

    $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $ci, 'to' => Carbon::today()->addDays(5)->toDateString(),
    ]))
        ->assertOk()
        ->assertSee('min="'.$expectedCoMin.'"', false);
});

it('drops past-date filters on /stays/{slug} + clears the URL echo', function (): void {
    // Past check-in + past checkout - controller should silently
    // reject + render the form with EMPTY date fields (not the
    // pre-filled invalid range).
    $r = $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => Carbon::today()->subDays(3)->toDateString(),
        'to'   => Carbon::today()->subDay()->toDateString(),
    ]));

    $r->assertOk();
    $r->assertDontSee('value="'.Carbon::today()->subDays(3)->toDateString().'"', false);
});

it('drops same-day-checkout filters on /stays/{slug}', function (): void {
    $day = Carbon::today()->addDays(5)->toDateString();

    $r = $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $day, 'to' => $day,   // same day - illegal
    ]));

    $r->assertOk();
    // Total-units label (no date filter) shows instead of units-left
    // - which is only shown when the date filter actually applied.
    $r->assertSee('total');
});

it('drops reversed-range filters on /stays/{slug}', function (): void {
    $r = $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => Carbon::today()->addDays(6)->toDateString(),
        'to'   => Carbon::today()->addDays(3)->toDateString(),  // before check-in
    ]));

    $r->assertOk();
    $r->assertSee('total');
});

it('accepts a valid future range on /stays/{slug}', function (): void {
    $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => Carbon::today()->addDays(3)->toDateString(),
        'to'   => Carbon::today()->addDays(5)->toDateString(),
    ]))
        ->assertOk()
        ->assertSee('unit'); // "1 unit left" / "N units left" both match
});

it('Livewire checkout snaps a past URL check-in forward to today on mount', function (): void {
    Livewire::withQueryParams([
        'from' => Carbon::today()->subDays(3)->toDateString(),
        'to'   => Carbon::today()->subDay()->toDateString(),
    ])
        ->test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSet('checkin', Carbon::today()->toDateString())
        ->assertSet('checkout', Carbon::today()->addDay()->toDateString());
});

it('Livewire checkout snaps a same-day checkout forward on mount', function (): void {
    $day = Carbon::today()->addDays(5)->toDateString();

    Livewire::withQueryParams(['from' => $day, 'to' => $day])
        ->test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->assertSet('checkin', $day)
        ->assertSet('checkout', Carbon::today()->addDays(6)->toDateString());
});

it('Livewire checkout pay() rejects a past check-in that snuck past mount', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->subDay()->toDateString())
        ->set('checkout', Carbon::today()->addDay()->toDateString())
        ->set('guestName', 'Ada N')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+2348030000000')
        ->call('pay')
        ->assertHasErrors('checkin');
});

it('Livewire checkout pay() rejects same-day checkout', function (): void {
    $day = Carbon::today()->addDays(4)->toDateString();

    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', $day)->set('checkout', $day)
        ->set('guestName', 'Ada N')
        ->set('guestEmail', 'ada@example.test')
        ->set('guestPhone', '+2348030000000')
        ->call('pay')
        ->assertHasErrors('checkout');
});

it('Livewire checkout renders min=today on check-in + min=checkin+1 on check-out', function (): void {
    Livewire::test(StaysBookCheckout::class, ['slug' => $this->property->public_slug])
        ->set('checkin', Carbon::today()->addDays(5)->toDateString())
        ->assertSee('min="'.Carbon::today()->toDateString().'"', false)
        ->assertSee('min="'.Carbon::today()->addDays(6)->toDateString().'"', false);
});
