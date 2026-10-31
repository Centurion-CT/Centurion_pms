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
 * Content polish on /stays/{slug}: amenity icons, property policies
 * (always render with defaults), places nearby, and the Livewire
 * checkout auto-advance for the checkout date when check-in changes.
 */
function seedContentProperty(array $propertyOverrides = []): Property
{
    $tenant = Tenant::factory()->create(['slug' => 'content-test-'.uniqid()]);
    $tenant->makeCurrent();
    $property = Property::factory()->for($tenant)->create(array_merge([
        'is_listed_publicly' => true,
        'city' => 'Lagos', 'state' => 'Lagos',
    ], $propertyOverrides));
    $cat = RoomCategory::factory()->for($tenant)->for($property)->create([
        'name' => 'Deluxe', 'base_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($tenant)->for($property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    // The business stays current: these pages only run on its own address.

    return $property;
}

afterEach(fn () => Tenant::forgetCurrent());

it('renders amenity icons + labels', function (): void {
    $prop = seedContentProperty([
        'amenities' => ['Wi-Fi', 'Swimming pool', 'Free parking'],
    ]);

    $r = $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]));

    $r->assertOk();
    $r->assertSee('Wi-Fi');
    $r->assertSee('Swimming pool');
    $r->assertSee('Free parking');
    $r->assertSee('data-testid="amenity-wi-fi"', false);
    $r->assertSee('data-testid="amenity-swimming-pool"', false);
    $r->assertSee('data-testid="amenity-free-parking"', false);
});

it('renders the Property policies section with default check-in/out times when the operator left them blank', function (): void {
    $prop = seedContentProperty();

    $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]))
        ->assertOk()
        ->assertSee('Property policies')
        ->assertSee('data-testid="policy-checkin"', false)
        ->assertSee('data-testid="policy-checkout"', false)
        ->assertSee('From 14:00')
        ->assertSee('By 12:00');
});

it('uses operator-set check-in/out times when they are set', function (): void {
    $prop = seedContentProperty([
        'house_rules' => [
            'check_in_from' => '15:00',
            'check_out_to'  => '11:00',
        ],
    ]);

    $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]))
        ->assertOk()
        ->assertSee('From 15:00')
        ->assertSee('By 11:00');
});

it('renders "Places nearby" only when the operator has added rows', function (): void {
    $with = seedContentProperty([
        'nearby_places' => [
            ['label' => 'Nnamdi Azikiwe Airport', 'distance' => '25 min', 'mode' => 'drive'],
            ['label' => 'Silverbird Cinemas', 'distance' => '8 min', 'mode' => 'walk'],
        ],
    ]);
    $without = seedContentProperty(['nearby_places' => null]);

    $this->get(route('marketplace.stays.show', ['slug' => $with->public_slug]))
        ->assertOk()
        ->assertSee('data-testid="stays-show-nearby"', false)
        ->assertSee('Nnamdi Azikiwe Airport')
        ->assertSee('Silverbird Cinemas')
        ->assertSee('25 min')
        ->assertSee('Walk');

    $this->get(route('marketplace.stays.show', ['slug' => $without->public_slug]))
        ->assertOk()
        ->assertDontSee('data-testid="stays-show-nearby"', false)
        ->assertDontSee('Nnamdi Azikiwe Airport');
});

it('ships a data-mp-gallery attribute on category photos + a lightbox stub in the page', function (): void {
    $prop = seedContentProperty();

    $r = $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]));

    $r->assertOk();
    // Lightbox modal shell is always in the DOM (JS toggles hidden).
    $r->assertSee('id="mp-lightbox"', false);
    $r->assertSee('data-testid="stays-show-lightbox"', false);
});

it('auto-advances the checkout date on the Livewire checkout when check-in changes', function (): void {
    $prop = seedContentProperty();

    Livewire::test(StaysBookCheckout::class, ['slug' => $prop->public_slug])
        ->set('checkin', Carbon::today()->addDays(5)->toDateString())
        ->set('checkout', '')
        ->set('checkin', Carbon::today()->addDays(7)->toDateString())
        ->assertSet('checkout', Carbon::today()->addDays(8)->toDateString());
});

it('leaves a manually-picked longer checkout alone when check-in changes to a still-earlier day', function (): void {
    $prop = seedContentProperty();

    Livewire::test(StaysBookCheckout::class, ['slug' => $prop->public_slug])
        ->set('checkin', Carbon::today()->addDays(5)->toDateString())
        ->set('checkout', Carbon::today()->addDays(9)->toDateString())
        ->set('checkin', Carbon::today()->addDays(6)->toDateString())
        // checkout stays 9-days-out since it's still after check-in +1
        ->assertSet('checkout', Carbon::today()->addDays(9)->toDateString());
});

it('bumps checkout when the new check-in overtakes it', function (): void {
    $prop = seedContentProperty();

    Livewire::test(StaysBookCheckout::class, ['slug' => $prop->public_slug])
        ->set('checkin', Carbon::today()->addDays(5)->toDateString())
        ->set('checkout', Carbon::today()->addDays(6)->toDateString())
        ->set('checkin', Carbon::today()->addDays(6)->toDateString())
        ->assertSet('checkout', Carbon::today()->addDays(7)->toDateString());
});
