<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\ManualCreate;
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
 * Category-only toggle on the manual-reservation form. When ON, the
 * operator picks a category instead of a specific room; the resulting
 * Booking lands with room_id=NULL and room_category_id set. Front
 * desk assigns a real room at check-in via AssignRoomToBooking.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'manual-cat']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 1,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '201',
        ]);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')->create([
            'status' => RoomStatus::Active, 'room_number' => '202',
        ]);
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('creates an unassigned booking on the category-only path', function (): void {
    $ci = Carbon::tomorrow();
    $co = Carbon::tomorrow()->addDays(2);

    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', true)
        ->set('categoryId', $this->category->id)
        ->set('guest_name', 'Ada Category')
        ->set('guest_email', 'ada.cat@example.test')
        ->set('guest_phone', '+2348011112222')
        ->set('adults', 2)
        ->set('children', 0)
        ->set('checkin', $ci->toDateString())
        ->set('checkout', $co->toDateString())
        ->set('source', BookingSource::Phone->value)
        ->set('payment_settlement', 'pending')   // defer payment
        ->call('save')
        ->assertHasNoErrors();

    /** @var Booking $booking */
    $booking = Booking::query()->latest('id')->first();
    expect($booking)->not->toBeNull();
    expect($booking->room_id)->toBeNull();
    expect($booking->room_category_id)->toBe($this->category->id);
    expect($booking->guest_name)->toBe('Ada Category');
    expect($booking->booking_status)->toEqual(BookingStatus::Pending);
    expect($booking->payment_status)->toEqual(PaymentStatus::Pending);
    // Total = base_rate × nights, no fees on this path.
    expect((int) $booking->total_amount)->toBe(60_000);

    // Availability blocks landed with room_id NULL + category_id set.
    $blocks = AvailabilityBlock::query()->where('booking_id', $booking->id)->get();
    expect($blocks)->toHaveCount(2);
    foreach ($blocks as $b) {
        expect($b->room_id)->toBeNull();
        expect($b->room_category_id)->toBe($this->category->id);
    }
});

it('requires categoryId when categoryOnly is on', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', true)
        // categoryId intentionally missing
        ->set('guest_name', 'X')
        ->set('guest_email', 'x@example.test')
        ->set('guest_phone', '+2348011113333')
        ->set('adults', 1)
        ->set('children', 0)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(1)->toDateString())
        ->call('save')
        ->assertHasErrors(['categoryId']);
});

it('does NOT require roomId when categoryOnly is on', function (): void {
    // Same submission as above but with categoryId filled — should
    // succeed without ever setting roomId.
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', true)
        ->set('categoryId', $this->category->id)
        ->set('guest_name', 'Y')
        ->set('guest_email', 'y@example.test')
        ->set('guest_phone', '+2348011114444')
        ->set('adults', 1)
        ->set('children', 0)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(1)->toDateString())
        ->call('save')
        ->assertHasNoErrors(['roomId']);
});

it('still requires roomId when categoryOnly is off (existing behaviour)', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', false)
        ->set('guest_name', 'Z')
        ->set('guest_email', 'z@example.test')
        ->set('guest_phone', '+2348011115555')
        ->set('adults', 1)
        ->set('children', 0)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(1)->toDateString())
        ->call('save')
        ->assertHasErrors(['roomId']);
});

it('flipping the toggle clears the stale room pick but preserves the payment settlement', function (): void {
    $c = Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('roomId', $this->roomA->id)
        ->set('payment_settlement', 'succeeded')
        ->set('payment_amount', 12345)
        ->set('categoryOnly', true);

    // Room clears; payment settlement + amount ride through so the
    // guest can still pay the full stay or a deposit at reservation
    // time on the category-only path.
    expect($c->get('roomId'))->toBeNull();
    expect($c->get('payment_settlement'))->toBe('succeeded');
    expect($c->get('payment_amount'))->toBe(12345);
});

it('collects a full payment at manual creation on the category-only path', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', true)
        ->set('categoryId', $this->category->id)
        ->set('guest_name', 'Ada Full')
        ->set('guest_email', 'ada.full@example.test')
        ->set('guest_phone', '+2348011119911')
        ->set('adults', 1)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDay()->toDateString())
        ->set('payment_settlement', 'succeeded')
        ->set('payment_method', 'cash')
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::query()->latest('id')->first();
    expect($booking->room_id)->toBeNull();
    expect($booking->booking_status)->toEqual(BookingStatus::Confirmed);
    expect($booking->payment_status)->toEqual(PaymentStatus::Succeeded);
    expect((int) $booking->balance_due)->toBe(0);

    $payment = \App\Models\Payment::query()->where('booking_id', $booking->id)->latest('id')->first();
    expect((int) $payment->amount)->toBe(30_000);
    expect($payment->method)->toBe('cash');
});

it('collects a partial deposit at manual creation and lands OnHold', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', true)
        ->set('categoryId', $this->category->id)
        ->set('guest_name', 'Ada Deposit')
        ->set('guest_email', 'ada.dep@example.test')
        ->set('guest_phone', '+2348011119922')
        ->set('adults', 1)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDay()->toDateString())
        ->set('payment_settlement', 'on_hold')
        ->set('payment_amount', 10_000)
        ->set('payment_method', 'transfer')
        ->call('save')
        ->assertHasNoErrors();

    $booking = Booking::query()->latest('id')->first();
    expect($booking->booking_status)->toEqual(BookingStatus::OnHold);
    expect((int) $booking->balance_due)->toBe(20_000);
    expect((int) $booking->deposit_amount)->toBe(10_000);

    $payment = \App\Models\Payment::query()->where('booking_id', $booking->id)->latest('id')->first();
    expect((int) $payment->amount)->toBe(10_000);
    expect($payment->method)->toBe('transfer');
});

it('rejects a category submission when every unit is already booked for those dates', function (): void {
    // Block both rooms in the category for the requested night to
    // force the categoryFreeUnits guard to trip.
    $ci = Carbon::tomorrow();
    $co = Carbon::tomorrow()->addDays(1);

    foreach ([$this->roomA, $this->roomB] as $r) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $r->id,
            'room_category_id' => $this->category->id,
            'date' => $ci->format('Y-m-d H:i:s'),
            'type' => \App\Enums\AvailabilityBlockType::Booked->value,
            'booking_id' => null,
        ]);
    }

    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('categoryOnly', true)
        ->set('categoryId', $this->category->id)
        ->set('guest_name', 'Full House')
        ->set('guest_email', 'full@example.test')
        ->set('guest_phone', '+2348011116666')
        ->set('adults', 1)
        ->set('children', 0)
        ->set('checkin', $ci->toDateString())
        ->set('checkout', $co->toDateString())
        ->call('save')
        ->assertHasErrors(['checkin']);
});
