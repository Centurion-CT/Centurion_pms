<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateCategoryReservation;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\Show as BookingShow;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Front-desk workflow for category-level reservations: booking-show
 * renders an Assign-a-room dropdown of free rooms in the booking's
 * category; picking one + submitting swaps the category-level
 * availability blocks onto that room and repoints the booking. Only
 * after this can MarkCheckedIn run.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'assign-flow']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
        'capacity_adults' => 2, 'capacity_children' => 1,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '401',
        ]);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '402',
        ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();

    // Pin dates to today so check-in gates line up.
    Carbon::setTestNow(Carbon::create(2026, 9, 8, 10, 0, 0));

    // Build a category-level (unassigned) booking that starts today.
    $this->booking = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDays(2),
        guestName: 'Ada Assign',
        guestEmail: 'assign@example.test',
        guestPhone: '+2348011117777',
        adults: 1, children: 0,
    );
    // Flip Pending → Confirmed so MarkCheckedIn's state guard clears
    // once we've assigned a room; the assign-room flow itself doesn't
    // care about booking status.
    $this->booking->forceFill([
        'booking_status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Succeeded,
    ])->save();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('booking-show renders the Unassigned pill + the assign-room picker', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertSee('data-testid="booking-show-unassigned"', false)
        ->assertSee('data-testid="assign-room-block"', false)
        ->assertSee('data-testid="assign-room-select"', false)
        ->assertSee('Room 401')
        ->assertSee('Room 402');
});

it('assignRoom swaps the category-level blocks to the picked room + repoints the booking', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('assignRoomId', $this->roomA->id)
        ->call('assignRoom')
        ->assertDispatched('notify');

    $this->booking->refresh();
    expect($this->booking->room_id)->toBe($this->roomA->id);
    expect($this->booking->room_category_id)->toBe($this->category->id);

    // Blocks should now carry room_id, not NULL.
    $blocks = AvailabilityBlock::query()->where('booking_id', $this->booking->id)->get();
    expect($blocks)->not->toBeEmpty();
    foreach ($blocks as $b) {
        expect($b->room_id)->toBe($this->roomA->id);
    }
});

it('drops rooms already booked for the range out of the assignable list', function (): void {
    // Block roomA for one night in the middle of the booking - it
    // should disappear from the assignable list. roomB stays.
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->roomA->id,
        'room_category_id' => $this->category->id,
        'date' => Carbon::today()->format('Y-m-d H:i:s'),
        'type' => \App\Enums\AvailabilityBlockType::Booked->value,
        'booking_id' => null,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertDontSee('Room 401')
        ->assertSee('Room 402');
});

it('shows the empty-state notice when no rooms in the category are free', function (): void {
    // Block both rooms for both nights.
    foreach ([$this->roomA, $this->roomB] as $r) {
        foreach ([Carbon::today(), Carbon::today()->addDay()] as $d) {
            AvailabilityBlock::query()->create([
                'tenant_id' => $this->tenant->id,
                'room_id' => $r->id,
                'room_category_id' => $this->category->id,
                'date' => $d->format('Y-m-d H:i:s'),
                'type' => \App\Enums\AvailabilityBlockType::Booked->value,
                'booking_id' => null,
            ]);
        }
    }

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertSee('data-testid="assign-room-empty"', false)
        ->assertDontSee('data-testid="assign-room-select"', false);
});

it('hides the check-in button until a room is assigned', function (): void {
    // Unassigned booking today - normally canCheckIn would be true
    // (Confirmed + today's date), but the view swaps the button for
    // the assign-room dropdown.
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertDontSee('data-testid="action-checkin"', false)
        ->assertSee('data-testid="assign-room-block"', false);
});

it('surfaces the check-in button after a room is assigned', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('assignRoomId', $this->roomA->id)
        ->call('assignRoom')
        ->assertSee('data-testid="action-checkin"', false)
        ->assertDontSee('data-testid="assign-room-block"', false);
});

it('MarkCheckedIn refuses to run on an unassigned booking via Show::checkIn', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('checkIn')
        ->assertDispatched('notify');

    // Booking status must not have flipped.
    $this->booking->refresh();
    expect($this->booking->booking_status)->toEqual(BookingStatus::Confirmed);
});
