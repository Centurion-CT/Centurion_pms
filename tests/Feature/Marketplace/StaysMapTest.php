<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

/**
 * Map rendering assertions for the Keverna Stays marketplace.
 * Server-side pins: the /stays index only ships JSON points for
 * properties that carry lat/lng; the /stays/{slug} detail page
 * only renders the "Where you'll be" mini-map when the property
 * has coordinates set.
 */
function seedGeoProperty(string $tenantSlug, ?float $lat, ?float $lng, array $overrides = []): Property
{
    $tenant = Tenant::factory()->create(['slug' => $tenantSlug]);
    $tenant->makeCurrent();
    $city = $overrides['city'] ?? 'Lagos';
    $property = Property::factory()->for($tenant)->create(array_merge([
        'city' => $city, 'state' => $city,
        'is_listed_publicly' => true,
        'latitude' => $lat,
        'longitude' => $lng,
    ], $overrides));
    $cat = RoomCategory::factory()->for($tenant)->for($property)->create([
        'base_rate' => 42_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($tenant)->for($property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();

    return $property;
}

afterEach(fn () => Tenant::forgetCurrent());

it('renders the map canvas + Leaflet script on /stays', function (): void {
    seedGeoProperty('geo-a', 6.4531, 3.3958, ['name' => 'Palm Suites']);

    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('id="stays-map"', false)
        ->assertSee('unpkg.com/leaflet@1.9.4', false);
});

it('only ships JSON pins for properties with coordinates', function (): void {
    seedGeoProperty('geo-yes', 6.5244, 3.3792, ['name' => 'Has Coords']);
    seedGeoProperty('geo-no',  null,   null,    ['name' => 'No Coords']);

    $response = $this->get(route('marketplace.stays.index'));
    $response->assertOk();
    $html = $response->getContent();

    // JSON pin payload sits on data-points attribute of #stays-map
    expect($html)->toContain('Has Coords')
        ->and($html)->toContain('data-points');

    // Extract data-points value + confirm no-coords property isn't in the JSON blob.
    preg_match('/data-points="([^"]+)"/', $html, $m);
    $json = html_entity_decode($m[1] ?? '', ENT_QUOTES);
    expect($json)->toContain('Has Coords')
        ->and($json)->not->toContain('No Coords');
});

it('still renders the map canvas + shows an overlay hint when no listed property carries coordinates', function (): void {
    seedGeoProperty('no-coords', null, null, ['name' => 'Coordless Property']);

    // Map canvas is ALWAYS shipped so the slot never reads as a
    // broken tile background - the JS branch on data-points=[]
    // frames Nigeria as the default view; a small pill overlay
    // reassures the user we know why nothing's pinned yet.
    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('id="stays-map"', false)
        ->assertSee('data-testid="map-empty-hint"', false)
        ->assertSee('No pinned locations yet');
});

it('renders the "Where you\'ll be" section on /stays/{slug} when coordinates are set', function (): void {
    $prop = seedGeoProperty('geo-show', 9.0765, 7.3986, ['name' => 'Abuja Mini Suites', 'city' => 'Abuja']);

    $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]))
        ->assertOk()
        ->assertSee("Where you'll be", false)
        ->assertSee('id="stays-show-map"', false)
        ->assertSee('data-lat="9.0765"', false);
});

it('omits the location section on /stays/{slug} when the property has no coordinates', function (): void {
    $prop = seedGeoProperty('geo-none', null, null, ['name' => 'Locationless']);

    $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]))
        ->assertOk()
        ->assertDontSee("Where you'll be", false)
        ->assertDontSee('id="stays-show-map"', false);
});
