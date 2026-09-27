<?php

declare(strict_types=1);

use App\Actions\Bookings\AssignRoomToBooking;
use App\Actions\Bookings\BookingValidationException;
use App\Actions\Bookings\CreateCategoryReservation;
use App\Actions\Bookings\MarkCheckedIn;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Support\Carbon;

/**
 * Category-level reservations - the front-desk can hold a room
 * category slot for a guest without pinning a specific room until
 * check-in. Every safeguard the feature promises has its own
 * test: overselling protection, backfilled room_category_id on
 * pre-existing bookings, room assignment before check-in, and
 * the availability count pivoting between specific-room + category
 * blocks.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cat-res']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    // Three Active rooms in the category - total unit ceiling is 3.
    $this->rooms = collect(range(1, 3))->map(fn ($n) => Room::factory()
        ->for($this->tenant)->for($this->property)->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => 'R'.$n]));
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

// ── AvailabilityService::categoryFreeUnits ────────────────

it('reports all units free when no bookings exist', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);

    expect(app(AvailabilityService::class)->categoryFreeUnits($this->category, $ci, $co))->toBe(3);
});

it('subtracts specific-room blocks from the free count', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);
    // Block one room across the whole range.
    app(AvailabilityService::class)->blockRange($this->rooms[0], $ci, $co, 'maintenance');

    expect(app(AvailabilityService::class)->categoryFreeUnits($this->category, $ci, $co))->toBe(2);
});

it('subtracts category-level blocks (room_id NULL) too', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);
    // Real booking whose blocks land as category-level (room_id NULL).
    app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'A', guestEmail: 'a@ex.test', guestPhone: '+234800',
        adults: 1, children: 0,
    );

    expect(app(AvailabilityService::class)->categoryFreeUnits($this->category, $ci, $co))->toBe(2);
});

it('returns the SMALLEST surplus across the range (single-night bottleneck)', function (): void {
    // Only middle night collides on 2 rooms; edge nights collide on 1.
    // Free count on middle night = 1, other nights = 2 -> min = 1.
    $ci = Carbon::today()->addDays(3);
    $mid = Carbon::today()->addDays(4);
    $co = Carbon::today()->addDays(5);

    app(AvailabilityService::class)->blockRange($this->rooms[0], $mid, $mid->copy()->addDay(), 'maintenance');
    app(AvailabilityService::class)->blockRange($this->rooms[1], $mid, $mid->copy()->addDay(), 'maintenance');

    expect(app(AvailabilityService::class)->categoryFreeUnits($this->category, $ci, $co))->toBe(1);
});

// ── CreateCategoryReservation ────────────────────────────

it('creates a booking with room_id NULL + room_category_id set', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);

    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Ada N', guestEmail: 'ada@example.test', guestPhone: '+2348030000000',
        adults: 2, children: 0,
    );

    expect($booking->room_id)->toBeNull();
    expect($booking->room_category_id)->toBe($this->category->id);
    expect($booking->isUnassigned())->toBeTrue();
    expect($booking->nights)->toBe(2);
    expect((int) $booking->room_total)->toBe(80_000);   // 2 × ₦40k
});

it('inserts category-level AvailabilityBlocks for each night', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);

    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Ada N', guestEmail: 'ada@example.test', guestPhone: '+2348030000000',
        adults: 2, children: 0,
    );

    $blocks = AvailabilityBlock::query()
        ->where('booking_id', $booking->id)
        ->get();
    expect($blocks)->toHaveCount(2);
    foreach ($blocks as $b) {
        expect($b->room_id)->toBeNull();
        expect($b->room_category_id)->toBe($this->category->id);
        expect($b->type)->toEqual(AvailabilityBlockType::Booked);
    }
});

it('rejects when the category is fully booked for those dates', function (): void {
    // Fill the category with three category-level bookings.
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(4);
    for ($i = 0; $i < 3; $i++) {
        app(CreateCategoryReservation::class)->handle(
            category: $this->category, checkin: $ci, checkout: $co,
            guestName: 'G'.$i, guestEmail: 'g'.$i.'@ex.test', guestPhone: '+234800000000'.$i,
            adults: 1, children: 0,
        );
    }

    expect(fn () => app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Extra', guestEmail: 'extra@example.test', guestPhone: '+2348030000010',
        adults: 1, children: 0,
    ))->toThrow(RangeNotAvailableException::class);
});

it('rejects a category-level booking when specific rooms already fill the ceiling', function (): void {
    // Block all 3 specific rooms; then a category-level request must fail.
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(4);
    foreach ($this->rooms as $r) {
        app(AvailabilityService::class)->blockRange($r, $ci, $co, 'maintenance');
    }

    expect(fn () => app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Overflow', guestEmail: 'o@ex.test', guestPhone: '+2348030000000',
        adults: 1, children: 0,
    ))->toThrow(RangeNotAvailableException::class);
});

it('rejects an adults+children total that exceeds the category max occupancy', function (): void {
    // Capacity_adults=2, capacity_children=0 -> maxOccupancy=2.
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);

    expect(fn () => app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Big Party', guestEmail: 'p@ex.test', guestPhone: '+234800',
        adults: 3, children: 0,
    ))->toThrow(BookingValidationException::class, 'sleeps up to');
});

// ── AssignRoomToBooking ──────────────────────────────────

it('assigns a room to an unassigned booking + repoints its blocks', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);
    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Ada', guestEmail: 'ada@ex.test', guestPhone: '+234800',
        adults: 1, children: 0,
    );

    $assigned = app(AssignRoomToBooking::class)->handle($booking, $this->rooms[0], $this->manager);

    expect($assigned->room_id)->toBe($this->rooms[0]->id);
    expect($assigned->isUnassigned())->toBeFalse();

    // Blocks now point at the specific room.
    $blocks = AvailabilityBlock::query()
        ->where('booking_id', $booking->id)
        ->get();
    foreach ($blocks as $b) {
        expect($b->room_id)->toBe($this->rooms[0]->id);
    }
});

it('refuses to assign a room from a different category', function (): void {
    $otherCategory = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 20_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $otherRoom = Room::factory()->for($this->tenant)->for($this->property)
        ->for($otherCategory, 'category')->create(['status' => RoomStatus::Active]);

    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today()->addDays(3),
        checkout: Carbon::today()->addDays(5),
        guestName: 'G', guestEmail: 'g@ex.test', guestPhone: '+234800',
        adults: 1, children: 0,
    );

    expect(fn () => app(AssignRoomToBooking::class)->handle($booking, $otherRoom, $this->manager))
        ->toThrow(BookingValidationException::class, 'different category');
});

it('refuses to assign a room that is booked on any night in the range', function (): void {
    $ci = Carbon::today()->addDays(3);
    $co = Carbon::today()->addDays(5);
    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'G', guestEmail: 'g@ex.test', guestPhone: '+234800',
        adults: 1, children: 0,
    );
    // Take the target room out via a specific-room block.
    app(AvailabilityService::class)->blockRange($this->rooms[0], $ci, $co, 'maintenance');

    expect(fn () => app(AssignRoomToBooking::class)->handle($booking, $this->rooms[0], $this->manager))
        ->toThrow(RangeNotAvailableException::class);
});

// ── Check-in gate ────────────────────────────────────────

it('MarkCheckedIn refuses an unassigned booking with a friendly error', function (): void {
    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'G', guestEmail: 'g@ex.test', guestPhone: '+234800',
        adults: 1, children: 0,
    );
    $booking->forceFill(['booking_status' => BookingStatus::Confirmed])->save();

    expect(fn () => app(MarkCheckedIn::class)->handle($booking->fresh(), $this->manager))
        ->toThrow(BookingValidationException::class, 'Pick a room from the category');
});

it('lets check-in succeed once a room has been assigned', function (): void {
    $booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'G', guestEmail: 'g@ex.test', guestPhone: '+234800',
        adults: 1, children: 0,
    );
    $booking->forceFill(['booking_status' => BookingStatus::Confirmed])->save();
    app(AssignRoomToBooking::class)->handle($booking->fresh(), $this->rooms[0], $this->manager);

    $checkedIn = app(MarkCheckedIn::class)->handle($booking->fresh(), $this->manager);
    expect($checkedIn->booking_status)->toEqual(BookingStatus::CheckedIn);
});
