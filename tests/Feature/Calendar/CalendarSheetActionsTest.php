<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Calendar\PropertyView;
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
 * Calendar's booking sheet (the right-side panel when you click a
 * booked cell) must offer Extend + Change room in addition to Check
 * in / Check out. Previously it only surfaced check-in/out + cancel
 * + no-show; extending the stay or moving the guest across rooms
 * required a hop to the full front-desk console.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'cal-sheet']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->deluxe = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 40_000,
    ]);
    $this->suite = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 60_000,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->deluxe, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->suite, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '201']);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    // CheckedIn guest on roomA with 1 remaining night.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'guest_name' => 'Ifeanyi',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::create(2026, 9, 8),
        'checkout_date' => Carbon::create(2026, 9, 10),
        'nights' => 2,
        'total_amount' => 80_000, 'room_total' => 80_000,
        'balance_due' => 0,
    ]);
    foreach ([Carbon::create(2026, 9, 8), Carbon::create(2026, 9, 9)] as $d) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->roomA->id,
            'room_category_id' => $this->deluxe->id,
            'date' => $d->format('Y-m-d H:i:s'),
            'type' => \App\Enums\AvailabilityBlockType::Booked->value,
            'booking_id' => $this->booking->id,
        ]);
    }
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('renders Extend + Change room + Check out on the calendar booking sheet', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->call('openBookingSheet', $this->booking->id)
        ->assertSee('data-testid="booking-sheet-extend"', false)
        ->assertSee('data-testid="booking-sheet-action-change-room"', false)
        ->assertSee('data-testid="booking-sheet-checkout"', false);
});

it('lists cross-category rooms in the Change-room picker', function (): void {
    // Deluxe is roomA (current), Suite roomB (different category).
    // Prior scoping was same-category-only, so roomB would have been
    // hidden entirely.
    $html = Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->call('openBookingSheet', $this->booking->id)
        ->call('toggleReassign')
        ->html();
    expect($html)->toContain('Suite');
    expect($html)->toContain('Room 201');
});

it('candidate pool for CheckedIn ignores past-night conflicts on the target room', function (): void {
    // Block roomB for Sep 8 (past night for our guest, whose remaining
    // window is Sep 9 -> Sep 10). Old scope required roomB free for
    // the whole original range -> it dropped out. New scope only
    // checks Sep 9 -> Sep 10, so roomB stays.
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->roomB->id,
        'room_category_id' => $this->suite->id,
        'date' => Carbon::create(2026, 9, 8)->format('Y-m-d H:i:s'),
        'type' => \App\Enums\AvailabilityBlockType::Booked->value,
        'booking_id' => null,
    ]);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(PropertyView::class)
        ->call('openBookingSheet', $this->booking->id)
        ->call('toggleReassign')
        ->html();
    expect($html)->toContain('Room 201');
});
