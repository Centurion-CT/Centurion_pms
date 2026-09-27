<?php

declare(strict_types=1);

use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\InHouse\Index as InHouseIndex;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The in-house guest panel's Extend / Change room / Add charge
 * actions used to navigate to /reservations/{ref}. They now open
 * inline modals on the in-house page.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'ih-inline']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 40_000,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->cat, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    $this->booking = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'guest_name' => 'Ifeanyi In-house',
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
            'room_category_id' => $this->cat->id,
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

it('opens the change-room modal + renders cross-category candidates for a CheckedIn guest', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openChangeRoom', $this->booking->id)
        ->assertSee('data-testid="change-room-card"', false)
        ->assertSee('Room 102')
        ->assertSee('Move Ifeanyi In-house');
});

it('shows a positive-delta preview when moving to a pricier category', function (): void {
    // Add a Suite category ₦20k pricier than the current Deluxe.
    $suite = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 60_000,
    ]);
    $suiteRoom = Room::factory()->for($this->tenant)->for($this->property)
        ->for($suite, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '201']);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openChangeRoom', $this->booking->id)
        ->set('changeRoomTargetRoomId', $suiteRoom->id)
        ->html();

    // Guest booked 2 nights, spent 1 (Sep 8), 1 remaining night at
    // the new rate. Delta = (60k - 40k) × 1 = +₦20,000 to collect.
    expect($html)->toContain('data-testid="change-room-preview"');
    expect($html)->toContain('+₦20,000 to collect');
    expect($html)->toContain('Room 201');
    expect($html)->toContain('Suite');
});

it('shows a negative-delta preview when moving to a cheaper category', function (): void {
    // Add a Standard category ₦10k cheaper.
    $standard = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 30_000,
    ]);
    $standardRoom = Room::factory()->for($this->tenant)->for($this->property)
        ->for($standard, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '301']);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openChangeRoom', $this->booking->id)
        ->set('changeRoomTargetRoomId', $standardRoom->id)
        ->html();

    // Delta = (30k - 40k) × 1 = -₦10,000 owed back.
    expect($html)->toContain('-₦10,000 owed back');
});

it('shows "No rate change" for a same-category move', function (): void {
    $html = Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openChangeRoom', $this->booking->id)
        ->set('changeRoomTargetRoomId', $this->roomB->id)   // same category as roomA
        ->html();

    expect($html)->toContain('No rate change');
});

it('changing room fires ReassignRoom + dispatches a notify', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openChangeRoom', $this->booking->id)
        ->set('changeRoomTargetRoomId', $this->roomB->id)
        ->call('submitChangeRoom')
        ->assertDispatched('notify');

    expect($this->booking->refresh()->room_id)->toBe($this->roomB->id);
});

it('opens the add-charge modal + creates a BookingCharge on submit', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openAddCharge', $this->booking->id)
        ->assertSee('data-testid="add-charge-card"', false)
        ->set('addChargeType', 'food_beverage')
        ->set('addChargeAmount', 8_500)
        ->set('addChargeDescription', '2x dinner')
        ->call('submitAddCharge')
        ->assertDispatched('notify');

    $charge = BookingCharge::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect($charge)->not->toBeNull();
    expect($charge->type)->toEqual(BookingChargeType::FoodBeverage);
    expect((int) $charge->amount)->toBe(8_500);
    expect($charge->description)->toBe('2x dinner');
});

it('Waive toggle marks the charge as settled with settled_method=waived', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openAddCharge', $this->booking->id)
        ->set('addChargeType', 'damage')
        ->set('addChargeAmount', 12_000)
        ->set('addChargeWaive', true)
        ->call('submitAddCharge');

    $charge = BookingCharge::query()->where('booking_id', $this->booking->id)->latest('id')->first();
    expect((bool) $charge->settled)->toBeTrue();
    expect($charge->settled_method)->toBe('waived');
});

it('Extend stay opens the shared modal via openExtendModal (trait)', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(InHouseIndex::class)
        ->call('openExtendModal', $this->booking->id)
        ->assertSet('extendOpen', true)
        ->assertSet('extendBookingId', $this->booking->id);
});
