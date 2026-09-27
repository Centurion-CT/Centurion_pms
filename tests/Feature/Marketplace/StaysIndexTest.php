<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

/**
 * /stays - the guest-facing marketplace search index. Cross-tenant
 * read that intentionally bypasses TenantScope, so every guard
 * (opt-out, no-rooms, wrong-city, guest-count) is exercised
 * explicitly here to make sure the leak-safe defaults hold.
 */
function seedListedProperty(string $tenantSlug, array $overrides = []): Property
{
    $tenant = Tenant::factory()->create(['slug' => $tenantSlug]);
    $tenant->makeCurrent();
    // City is enough; state defaults to whatever city was passed so
    // ?q= filters don't false-match on residual state strings.
    $city = $overrides['city'] ?? 'Lagos';
    $property = Property::factory()->for($tenant)->create(array_merge([
        'city'  => $city,
        'state' => $city,
        'is_listed_publicly' => true,
    ], $overrides));
    $category = RoomCategory::factory()->for($tenant)->for($property)->create([
        'base_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($tenant)->for($property)->for($category, 'category')->create([
        'status' => RoomStatus::Active,
    ]);
    Tenant::forgetCurrent();

    return $property;
}

afterEach(fn () => Tenant::forgetCurrent());

it('lists every property that opted into the marketplace', function (): void {
    $a = seedListedProperty('one', ['name' => 'Lagos One Suites']);
    $b = seedListedProperty('two', ['name' => 'Abuja Palace', 'city' => 'Abuja']);

    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('Lagos One Suites')
        ->assertSee('Abuja Palace')
        ->assertSee('2 stays');
});

it('hides properties that opted out of the marketplace', function (): void {
    seedListedProperty('mkt', ['name' => 'Listed Property']);
    seedListedProperty('offmkt', [
        'name' => 'Off Market Property',
        'is_listed_publicly' => false,
    ]);

    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('Listed Property')
        ->assertDontSee('Off Market Property');
});

it('hides properties with zero active rooms', function (): void {
    seedListedProperty('busy', ['name' => 'Has Rooms']);
    // Property with no room at all.
    $tenant = Tenant::factory()->create(['slug' => 'empty']);
    $tenant->makeCurrent();
    Property::factory()->for($tenant)->create([
        'name' => 'No Rooms Yet',
        'is_listed_publicly' => true,
    ]);
    Tenant::forgetCurrent();

    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('Has Rooms')
        ->assertDontSee('No Rooms Yet');
});

it('filters by ?q= against name, city and state', function (): void {
    seedListedProperty('a', ['name' => 'Lagos Palm Suites', 'city' => 'Lagos']);
    seedListedProperty('b', ['name' => 'Abuja Skyline', 'city' => 'Abuja']);

    $this->get(route('marketplace.stays.index', ['q' => 'lagos']))
        ->assertOk()
        ->assertSee('Lagos Palm Suites')
        ->assertDontSee('Abuja Skyline');
});

it('filters by guest count against room capacity', function (): void {
    // Small room, adults=2 capacity.
    seedListedProperty('small', ['name' => 'Studio Only']);
    // Big room, adults=4 capacity.
    $tenant = Tenant::factory()->create(['slug' => 'big']);
    $tenant->makeCurrent();
    $bigProp = Property::factory()->for($tenant)->create([
        'name' => 'Family Suites',
        'is_listed_publicly' => true,
    ]);
    $bigCat = RoomCategory::factory()->for($tenant)->for($bigProp)->create([
        'base_rate' => 90_000,
        'capacity_adults' => 4, 'capacity_children' => 0,
    ]);
    Room::factory()->for($tenant)->for($bigProp)->for($bigCat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();

    $this->get(route('marketplace.stays.index', ['adults' => 4]))
        ->assertOk()
        ->assertSee('Family Suites')
        ->assertDontSee('Studio Only');
});

it('renders the empty state when nothing matches', function (): void {
    $this->get(route('marketplace.stays.index', ['q' => 'nowhere-that-exists']))
        ->assertOk()
        ->assertSee('No stays')
        ->assertSee('List your property');
});

it('redirects /search to /stays preserving query params', function (): void {
    $response = $this->get('/search?q=lagos&from=2026-10-01&adults=2');
    $response->assertStatus(301);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/stays?');
    expect($location)->toContain('q=lagos');
    expect($location)->toContain('from=2026-10-01');
    expect($location)->toContain('adults=2');
});

it('shows from-price = cheapest active room', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'price-test']);
    $tenant->makeCurrent();
    $prop = Property::factory()->for($tenant)->create([
        'name' => 'Multi Room Property', 'is_listed_publicly' => true,
    ]);
    $catCheap = RoomCategory::factory()->for($tenant)->for($prop)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $catPricey = RoomCategory::factory()->for($tenant)->for($prop)->create([
        'base_rate' => 90_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($tenant)->for($prop)->for($catCheap, 'category')->create(['status' => RoomStatus::Active]);
    Room::factory()->for($tenant)->for($prop)->for($catPricey, 'category')->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();

    $this->get(route('marketplace.stays.index'))
        ->assertOk()
        ->assertSee('30,000');
});
