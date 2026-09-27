<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Category-level availability rendering on /stays/{slug}. The
 * marketplace sells room categories, not door numbers - a category
 * is "available" only when at least one of its rooms is fully free
 * for the entire requested [checkin, checkout) range.
 *
 * Fixture pattern per test:
 *   - 1 tenant, 1 listed property
 *   - N Room rows inside a category, some blocked, some free
 *   - Assert what the /stays/{slug}?from=&to=&adults= page reports
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'avail-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Availability Suites',
        'city' => 'Lagos', 'state' => 'Lagos',
    ]);
    // Two categories: Deluxe (3 rooms) + Standard (2 rooms).
    $this->deluxe = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe',
        'base_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->standard = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard',
        'base_rate' => 25_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);

    $this->deluxeRooms = collect(range(1, 3))->map(fn ($n) => Room::factory()
        ->for($this->tenant)->for($this->property)->for($this->deluxe, 'category')
        ->create(['room_number' => 'D'.$n, 'status' => RoomStatus::Active]));

    $this->standardRooms = collect(range(1, 2))->map(fn ($n) => Room::factory()
        ->for($this->tenant)->for($this->property)->for($this->standard, 'category')
        ->create(['room_number' => 'S'.$n, 'status' => RoomStatus::Active]));

    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

function blockRoomDates(int $roomId, string $from, string $to): void
{
    // Half-open [from, to) - one Booked row per night. Same shape
    // AvailabilityService::reserveRangeForBooking writes. Room +
    // AvailabilityBlock both carry BelongsToTenant so the writes
    // bypass the scope explicitly (test runs in central context).
    $room = Room::query()
        ->withoutGlobalScope(\App\Multitenancy\TenantScope::class)
        ->findOrFail($roomId);
    $cursor = Carbon::parse($from)->startOfDay();
    $stop = Carbon::parse($to)->startOfDay();
    while ($cursor->lessThan($stop)) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $room->tenant_id,
            'room_id' => $roomId,
            'date' => $cursor->toDateString(),
            'type' => AvailabilityBlockType::Booked,
            'booking_id' => null,
        ]);
        $cursor->addDay();
    }
}

it('renders every category with total-units when no dates are provided', function (): void {
    $r = $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]));

    $r->assertOk();
    // Category names + total-units labels render.
    $r->assertSee('Deluxe');
    $r->assertSee('Standard');
    $r->assertSee('3 units total');
    $r->assertSee('2 units total');
    // The old room-per-door pattern MUST NOT show up.
    $r->assertDontSee('Room D1');
    $r->assertDontSee('Room S1');
});

it('shows N-units-left when the request carries a date range with no bookings', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(5)->toDateString();

    $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $from, 'to' => $to, 'adults' => 2,
    ]))
        ->assertOk()
        ->assertSee('3 units left')
        ->assertSee('2 units left');
});

it('subtracts blocked rooms from the available count for the exact range', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(5)->toDateString();

    // Block the first two Deluxe rooms for the guest's window.
    blockRoomDates($this->deluxeRooms[0]->id, $from, $to);
    blockRoomDates($this->deluxeRooms[1]->id, $from, $to);

    $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $from, 'to' => $to, 'adults' => 2,
    ]))
        ->assertOk()
        ->assertSee('1 unit left');
});

it('marks a category sold out + hides Reserve when no room is fully free for the range', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(6)->toDateString();

    // Block every Standard room for the whole window.
    foreach ($this->standardRooms as $r) {
        blockRoomDates($r->id, $from, $to);
    }

    $response = $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $from, 'to' => $to, 'adults' => 2,
    ]));

    $response->assertOk();
    $response->assertSee('data-testid="category-soldout-'.$this->standard->id.'"', false);
    $response->assertSee('Sold out');
    // Reserve CTA for the sold-out category is NOT rendered.
    $response->assertDontSee('data-testid="category-reserve-'.$this->standard->id.'"', false);
    // Deluxe is untouched - Reserve still there.
    $response->assertSee('data-testid="category-reserve-'.$this->deluxe->id.'"', false);
});

it('marks a category unavailable if ANY night in the range collides on every room', function (): void {
    // Guest wants Nov 3 -> Nov 6. Block room D1 for night 3, D2 for
    // night 4, D3 for night 5. No single room is free across the
    // full 3-night window -> category is Sold out.
    $day3 = Carbon::today()->addDays(3);
    $day4 = Carbon::today()->addDays(4);
    $day5 = Carbon::today()->addDays(5);

    blockRoomDates($this->deluxeRooms[0]->id, $day3->toDateString(), $day3->copy()->addDay()->toDateString());
    blockRoomDates($this->deluxeRooms[1]->id, $day4->toDateString(), $day4->copy()->addDay()->toDateString());
    blockRoomDates($this->deluxeRooms[2]->id, $day5->toDateString(), $day5->copy()->addDay()->toDateString());

    $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $day3->toDateString(),
        'to' => $day5->copy()->addDay()->toDateString(),
        'adults' => 2,
    ]))
        ->assertOk()
        ->assertSee('data-testid="category-soldout-'.$this->deluxe->id.'"', false);
});

it('keeps a room available when its block is OUTSIDE the guest range', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(5)->toDateString();

    // Block Deluxe #1 for a range that does NOT overlap the request.
    blockRoomDates(
        $this->deluxeRooms[0]->id,
        Carbon::today()->addDays(10)->toDateString(),
        Carbon::today()->addDays(12)->toDateString(),
    );

    $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $from, 'to' => $to, 'adults' => 2,
    ]))
        ->assertOk()
        ->assertSee('3 units left');
});

it('carries category + first-free-room + dates into the Reserve URL', function (): void {
    $from = Carbon::today()->addDays(3)->toDateString();
    $to = Carbon::today()->addDays(5)->toDateString();

    // Block D1 so D2 is the first-free.
    blockRoomDates($this->deluxeRooms[0]->id, $from, $to);

    $response = $this->get(route('marketplace.stays.show', [
        'slug' => $this->property->public_slug,
        'from' => $from, 'to' => $to, 'adults' => 2,
    ]));

    $response->assertOk();
    $html = $response->getContent();
    // Reserve URL for Deluxe must reference the first-free room id
    // + carry the guest's dates + guest count forward so the
    // checkout page opens in the right state.
    expect($html)->toContain('category='.$this->deluxe->id)
        ->and($html)->toContain('room='.$this->deluxeRooms[1]->id)
        ->and($html)->toContain('from='.$from)
        ->and($html)->toContain('to='.$to)
        ->and($html)->toContain('adults=2');
});

it('renders the sticky availability search bar above the rooms section', function (): void {
    $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]))
        ->assertOk()
        ->assertSee('data-testid="stays-show-availbar"', false)
        ->assertSee('data-testid="availbar-from"', false)
        ->assertSee('data-testid="availbar-to"', false)
        ->assertSee('data-testid="availbar-adults"', false)
        ->assertSee('data-testid="availbar-submit"', false);
});
