<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Enums\TenantStatus;
use App\Livewire\Dashboard\Settings\Business;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The Stays marketplace on the central domain (docs/MULTITENANCY.md).
 */

afterEach(fn () => Tenant::forgetCurrent());

/** A business with one live building and one apartment type. */
function listedBusiness(string $slug, string $name, array $tenant = [], array $property = []): Tenant
{
    $business = Tenant::factory()->create($tenant + ['slug' => $slug, 'name' => $name, 'listed_on_stays_at' => now()]);
    $business->makeCurrent();
    $building = Property::factory()->live()->for($business)->create($property + ['name' => $name.' Court', 'city' => 'Lagos']);
    $type = RoomCategory::factory()->for($business)->for($building)->create([
        'name' => 'Studio', 'base_rate' => 50_000, 'capacity_adults' => 2, 'status' => RoomStatus::Active,
    ]);
    Room::factory()->for($business)->for($building)->for($type, 'category')->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();

    return $business;
}

it('lists businesses that opted in, and nobody else', function (): void {
    listedBusiness('opted-in', 'Opted In');
    listedBusiness('never-asked', 'Never Asked', ['listed_on_stays_at' => null]);

    $this->get('http://keverna.test/stays')
        ->assertOk()
        ->assertSee('Opted In Court')
        ->assertSee('₦50,000')
        ->assertDontSee('Never Asked');
});

it('ignores the old opt-out flag: only the new choice lists a business', function (): void {
    listedBusiness('old-flag', 'Old Flag', ['listed_on_stays_at' => null], ['is_listed_publicly' => true]);

    $this->get('http://keverna.test/stays')->assertDontSee('Old Flag');
});

it('drops paused businesses, unpublished buildings and buildings with nothing to book', function (): void {
    listedBusiness('paused', 'Paused Co', ['status' => TenantStatus::Locked]);
    listedBusiness('unpublished', 'Unpublished Co', [], ['is_live' => false]);
    $empty = listedBusiness('empty', 'Empty Co');
    $empty->makeCurrent();
    Room::query()->update(['status' => RoomStatus::Inactive]);
    Tenant::forgetCurrent();

    $this->get('http://keverna.test/stays')
        ->assertOk()
        ->assertDontSee('Paused Co')
        ->assertDontSee('Unpublished Co')
        ->assertDontSee('Empty Co')
        ->assertSee('Places are being added soon');
});

it("links to the business's own address, carrying the search", function (): void {
    listedBusiness('own-site', 'Own Site');
    $in = Carbon::today()->addDays(5)->toDateString();
    $out = Carbon::today()->addDays(7)->toDateString();

    $this->get("http://keverna.test/stays?in={$in}&out={$out}&adults=2")
        ->assertSee('own-site.keverna.test', false)
        ->assertSee('/apartments?', false)
        ->assertSee('in='.$in, false)
        ->assertSee('Own Site Court');
});

it('finds places whatever the case of the search, and by city', function (): void {
    listedBusiness('lagos-co', 'Lagos Co');
    listedBusiness('abuja-co', 'Abuja Co', [], ['city' => 'Abuja', 'state' => 'FCT']);

    $this->get('http://keverna.test/stays?q=LAGOS')->assertSee('Lagos Co Court')->assertDontSee('Abuja Co Court');
    $this->get('http://keverna.test/stays?city=Abuja')->assertSee('Abuja Co Court')->assertDontSee('Lagos Co Court');
    $this->get('http://keverna.test/stays?q=%25')->assertSee('Nothing matches');
});

it('searches without dates unless the guest chooses some', function (): void {
    $full = listedBusiness('booked-tonight', 'Booked Tonight');
    $full->makeCurrent();
    AvailabilityBlock::query()->create(['tenant_id' => $full->id, 'room_id' => Room::query()->first()->id, 'date' => Carbon::today()->toDateString(), 'type' => App\Enums\AvailabilityBlockType::Blocked]);
    Tenant::forgetCurrent();

    $this->get('http://keverna.test/stays?q=booked&in=&out=&adults=')
        ->assertSee('Booked Tonight Court')
        ->assertSee('Any date')
        ->assertDontSee('fees included');
});

it('leaves out places too small for the party', function (): void {
    listedBusiness('small', 'Small Co');

    $this->get('http://keverna.test/stays?adults=5')->assertDontSee('Small Co Court');
});

it('shows only what is free for the dates, using the business\'s own availability', function (): void {
    $full = listedBusiness('full', 'Full Co');
    listedBusiness('free', 'Free Co');
    $in = Carbon::today()->addDays(5);
    $full->makeCurrent();
    AvailabilityBlock::query()->create(['tenant_id' => $full->id, 'room_id' => Room::query()->first()->id, 'date' => $in->copy()->addDay()->toDateString(), 'type' => App\Enums\AvailabilityBlockType::Blocked]);
    Tenant::forgetCurrent();

    $this->get('http://keverna.test/stays?in='.$in->toDateString().'&out='.$in->copy()->addDays(2)->toDateString())
        ->assertOk()
        ->assertSee('Free Co Court')
        ->assertSee('1 free for', false)
        ->assertSee('fees included')
        ->assertDontSee('Full Co Court');

    expect(Tenant::current())->toBeNull();
});

it('counts displayed reviews only', function (): void {
    $business = listedBusiness('rated', 'Rated Co');
    $business->makeCurrent();
    $building = Property::query()->first();
    Review::factory()->for($business)->for($building)->create(['rating' => 5]);
    Review::factory()->for($business)->for($building)->create(['rating' => 1, 'is_displayed' => false]);
    Tenant::forgetCurrent();

    $this->get('http://keverna.test/stays')->assertSee('5.0 · 1 review');
});

it('lets a manager opt in and out, and starts every business out', function (): void {
    $business = listedBusiness('chooser', 'Chooser', ['listed_on_stays_at' => null]);
    $business->makeCurrent();
    $manager = Operator::factory()->for($business)->create(['role' => OperatorRole::Manager]);

    Livewire::actingAs($manager)->test(Business::class)
        ->assertSet('listedOnStays', false)
        ->set('email', 'owner@chooser.example.com')
        ->set('listedOnStays', true)
        ->call('save');
    expect($business->fresh()->listed_on_stays_at)->not->toBeNull();

    Livewire::actingAs($manager)->test(Business::class)
        ->set('email', 'owner@chooser.example.com')
        ->set('listedOnStays', false)
        ->call('save');
    expect($business->fresh()->listed_on_stays_at)->toBeNull();
});

it("keeps /stays on a business's address going to its own apartments", function (): void {
    listedBusiness('own-stays', 'Own Stays');

    $this->get('http://own-stays.keverna.test/stays')->assertStatus(301)->assertRedirectContains('/apartments');
});

it('links the marketplace from the platform home only once something is listed', function (): void {
    $this->get('http://keverna.test/')->assertDontSee('Find a stay');

    listedBusiness('now-listed', 'Now Listed');

    $this->get('http://keverna.test/')->assertSee('Find a stay');
});
