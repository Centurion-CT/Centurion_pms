<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Marketplace\StaysListingQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'stays_online_hold_mode' => 'category',
    ]);
    $this->category = RoomCategory::factory()
        ->for($this->tenant)->for($this->property)
        ->create(['base_rate' => 30000]);
    // 3 units in the category so we can watch the count go down.
    Room::factory()->count(3)->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active->value]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('creates an unassigned booking when the property is in category-hold mode', function (): void {
    $checkin = Carbon::today()->addDays(10);
    $checkout = $checkin->copy()->addDays(2);

    $creator = app(\App\Actions\Bookings\CreateCategoryReservation::class);
    $b = $creator->handle(
        $this->category, $checkin, $checkout,
        'Test Guest', 'guest@example.com', '+2348012345678',
        1, 0, null, BookingSource::Online, 'test',
        marketplaceRequest: Request::create('/', 'GET'),
    );

    expect($b->room_id)->toBeNull();
    expect($b->room_category_id)->toBe($this->category->id);
    expect($b->nights)->toBe(2);
    // 2 × 30k with no rate rules active.
    expect((int) $b->room_total)->toBe(60000);
});

it('reduces the available-units count as category holds accumulate', function (): void {
    $checkin = Carbon::today()->addDays(20);
    $checkout = $checkin->copy()->addDays(1);

    $listing = app(StaysListingQuery::class);
    $before = $listing->categoryAvailability(
        $this->property->id, $checkin, $checkout, Request::create('/', 'GET'),
    )->firstWhere('category.id', $this->category->id);
    expect($before['available_units'])->toBe(3);

    // Two guests hold the category.
    $creator = app(\App\Actions\Bookings\CreateCategoryReservation::class);
    $creator->handle($this->category, $checkin, $checkout,
        'A', 'a@x.test', '+234800', 1, 0, null, BookingSource::Online, 'x');
    $creator->handle($this->category, $checkin, $checkout,
        'B', 'b@x.test', '+234801', 1, 0, null, BookingSource::Online, 'x');

    $after = $listing->categoryAvailability(
        $this->property->id, $checkin, $checkout, Request::create('/', 'GET'),
    )->firstWhere('category.id', $this->category->id);
    // 3 total - 2 category holds = 1 unit left, front-desk sees the same.
    expect($after['available_units'])->toBe(1);
});
