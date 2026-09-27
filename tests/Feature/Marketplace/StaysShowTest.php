<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

/**
 * /stays/{public_slug} - the property detail page. Pins the four
 * things a guest actually acts on:
 *   - the page shows only listed + has-rooms properties (else 404)
 *   - rooms + prices are rendered
 *   - published reviews + rating aggregate render
 *   - the Reserve CTA carries dates + guests into the (Packet 4)
 *     booking URL so the guest doesn't retype
 */
function seedShowProperty(string $tenantSlug, array $overrides = []): Property
{
    $tenant = Tenant::factory()->create(['slug' => $tenantSlug]);
    $tenant->makeCurrent();
    $city = $overrides['city'] ?? 'Lagos';
    $property = Property::factory()->for($tenant)->create(array_merge([
        'name'  => 'Stays Show Property',
        'city'  => $city,
        'state' => $city,
        'is_listed_publicly' => true,
        'description' => 'A calm apartment near the beach.',
        'amenities' => ['Wi-Fi', 'Pool', 'Air conditioning'],
    ], $overrides));
    $cat = RoomCategory::factory()->for($tenant)->for($property)->create([
        'base_rate' => 55_000, 'capacity_adults' => 2, 'capacity_children' => 1,
    ]);
    Room::factory()->for($tenant)->for($property)->for($cat, 'category')->create([
        'status' => RoomStatus::Active,
        'room_number' => '101',
    ]);
    Tenant::forgetCurrent();

    return $property;
}

afterEach(fn () => Tenant::forgetCurrent());

it('renders the property detail page for a listed slug', function (): void {
    $property = seedShowProperty('show-listed', ['name' => 'Palm Suites Lagos']);

    $this->get(route('marketplace.stays.show', ['slug' => $property->public_slug]))
        ->assertOk()
        ->assertSee('Palm Suites Lagos')
        ->assertSee('Lagos')
        ->assertSee('55,000')
        ->assertSee('A calm apartment near the beach')
        ->assertSee('Wi-Fi')
        ->assertSee('Reserve');
});

it('404s for an off-market property even if the slug exists', function (): void {
    $property = seedShowProperty('show-off', [
        'name' => 'Hidden Palace',
        'is_listed_publicly' => false,
    ]);

    $this->get(route('marketplace.stays.show', ['slug' => $property->public_slug]))
        ->assertNotFound();
});

it('404s for a property with no active rooms', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'no-rooms']);
    $tenant->makeCurrent();
    $prop = Property::factory()->for($tenant)->create([
        'name' => 'Empty Property',
        'is_listed_publicly' => true,
    ]);
    Tenant::forgetCurrent();

    $this->get(route('marketplace.stays.show', ['slug' => $prop->public_slug]))
        ->assertNotFound();
});

it('404s for a slug that does not exist', function (): void {
    $this->get(route('marketplace.stays.show', ['slug' => 'nowhere-xyz-123']))
        ->assertNotFound();
});

it('renders displayed reviews + hides undisplayed reviews', function (): void {
    $property = seedShowProperty('show-reviews');
    // Two displayed reviews + one hidden.
    Tenant::find($property->tenant_id)?->makeCurrent();
    Review::factory()->create([
        'tenant_id' => $property->tenant_id,
        'property_id' => $property->id,
        'guest_name' => 'Ada N.',
        'body' => 'Loved my stay here.',
        'rating' => 5,
        'is_displayed' => true,
    ]);
    Review::factory()->create([
        'tenant_id' => $property->tenant_id,
        'property_id' => $property->id,
        'guest_name' => 'Kingsley O.',
        'body' => 'Good value and clean.',
        'rating' => 4,
        'is_displayed' => true,
    ]);
    Review::factory()->create([
        'tenant_id' => $property->tenant_id,
        'property_id' => $property->id,
        'guest_name' => 'Spam Bot',
        'body' => 'Not published.',
        'rating' => 1,
        'is_displayed' => false,
    ]);
    Tenant::forgetCurrent();

    $this->get(route('marketplace.stays.show', ['slug' => $property->public_slug]))
        ->assertOk()
        ->assertSee('Ada N.')
        ->assertSee('Kingsley O.')
        ->assertSee('Loved my stay here.')
        ->assertDontSee('Spam Bot')
        ->assertSee('2 reviews');
});

it('carries dates + guest count into the Reserve CTA', function (): void {
    $property = seedShowProperty('show-reserve');

    $response = $this->get(route('marketplace.stays.show', [
        'slug' => $property->public_slug,
        'from' => '2026-10-14',
        'to' => '2026-10-17',
        'adults' => 2,
    ]));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->toContain('from=2026-10-14')
        ->and($html)->toContain('to=2026-10-17')
        ->and($html)->toContain('adults=2');
});
