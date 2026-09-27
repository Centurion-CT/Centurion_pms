<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\Index as BookingsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Bookings list on the operator dashboard shows category-level
 * reservations distinctly - category name + an "Unassigned" pill in
 * the room column - so front desk knows they still owe a room pick
 * before check-in.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'unassigned-list']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe Suite',
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '301',
        ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('renders the category name + Unassigned pill for category-level bookings', function (): void {
    $unassigned = Booking::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => null,
        'room_category_id' => $this->category->id,
        'booking_ref' => 'KV-UNASSIGN1',
        'guest_name' => 'Category Guest',
        'guest_email' => 'cg@example.test',
        'guest_phone' => '+2348011119999',
        'adults' => 1, 'children' => 0,
        'checkin_date' => Carbon::tomorrow()->toDateString(),
        'checkout_date' => Carbon::tomorrow()->addDays(1)->toDateString(),
        'nights' => 1,
        'room_total' => 25_000,
        'total_amount' => 25_000,
        'deposit_amount' => 0, 'balance_due' => 0,
        'paystack_fee' => 0, 'keverna_fee' => 0,
        'booking_status' => BookingStatus::Pending,
        'payment_status' => PaymentStatus::Pending,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->assertSee('data-testid="booking-unassigned-'.$unassigned->id.'"', false)
        ->assertSee('Deluxe Suite')
        ->assertSee('Unassigned');
});

it('renders the room name for a normal (assigned) booking, no Unassigned pill', function (): void {
    $assigned = Booking::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'room_category_id' => $this->category->id,
        'booking_ref' => 'KV-ASSIGN1',
        'guest_name' => 'Room Guest',
        'guest_email' => 'rg@example.test',
        'guest_phone' => '+2348011118888',
        'adults' => 1, 'children' => 0,
        'checkin_date' => Carbon::tomorrow()->toDateString(),
        'checkout_date' => Carbon::tomorrow()->addDays(1)->toDateString(),
        'nights' => 1,
        'room_total' => 25_000,
        'total_amount' => 25_000,
        'deposit_amount' => 0, 'balance_due' => 0,
        'paystack_fee' => 0, 'keverna_fee' => 0,
        'booking_status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Succeeded,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->assertDontSee('data-testid="booking-unassigned-'.$assigned->id.'"', false);
});
