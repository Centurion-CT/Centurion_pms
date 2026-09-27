<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateCategoryReservation;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Front-desk operational flow for a category-only (unassigned)
 * reservation on the reservations/show page. Prior to this the page
 * showed "No room assigned" with no way to pick a room, swap the
 * category, take payment, or check the guest in. This suite covers
 * the three fill-in actions + the check-in that unlocks after.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'unassigned-ops']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->categoryA = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 30_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->categoryB = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 45_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->categoryA, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->categoryA, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);
    $this->roomC = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->categoryB, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '201']);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    // Category-only booking with today as checkin.
    $this->booking = app(CreateCategoryReservation::class)->handle(
        category: $this->categoryA,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Ifeanyi Amadi',
        guestEmail: '',
        guestPhone: '+2348011117777',
        adults: 1, children: 0,
    );
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('renders the unassigned panel with assign-room + change-category', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertSee('data-testid="unassigned-panel"', false)
        ->assertSee('data-testid="assign-room-select"', false)
        ->assertSee('data-testid="switch-category-select"', false)
        ->assertSee('Room 101')
        ->assertSee('Room 102')
        ->assertSee('Suite');
});

it('assigns a room from the current category via the picker', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('assignRoomId', $this->roomA->id)
        ->call('assignRoom')
        ->assertDispatched('notify');

    $b = $this->booking->refresh();
    expect($b->room_id)->toBe($this->roomA->id);
});

it('swaps the category + reprices total_amount, balance_due UNCHANGED for a Pending reservation', function (): void {
    // Deluxe (₦30k) × 1 night = ₦30k. Swap to Suite (₦45k) → total = ₦45k.
    // Booking is still Pending (nothing paid), so balance_due stays 0.
    // The Confirm & take payment flow reads total_amount when it
    // fires - adding the delta here would give the operator a
    // phantom "₦15k owed" against nothing collected yet.
    expect((int) $this->booking->total_amount)->toBe(30_000);
    expect((int) $this->booking->balance_due)->toBe(0);
    expect($this->booking->payment_status)->toEqual(PaymentStatus::Pending);

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('switchCategoryId', $this->categoryB->id)
        ->call('changeCategory')
        ->assertDispatched('notify');

    $b = $this->booking->refresh();
    expect($b->room_category_id)->toBe($this->categoryB->id);
    expect((int) $b->total_amount)->toBe(45_000);
    expect((int) $b->room_total)->toBe(45_000);
    expect((int) $b->balance_due)->toBe(0);
    // Room stays unassigned until the operator picks one from the
    // new category via assignRoom.
    expect($b->room_id)->toBeNull();
});

it('swaps the category on a settled booking: balance_due picks up the rate delta', function (): void {
    // Guest paid a ₦20k deposit; balance_due was ₦10k on the original
    // ₦30k total. Category swap to ₦45k: new balance_due = 10k + 15k.
    $this->booking->forceFill([
        'total_amount'   => 30_000,
        'balance_due'    => 10_000,
        'deposit_amount' => 20_000,
        'payment_status' => PaymentStatus::Succeeded,
    ])->save();

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('switchCategoryId', $this->categoryB->id)
        ->call('changeCategory');

    $b = $this->booking->refresh();
    expect((int) $b->total_amount)->toBe(45_000);
    expect((int) $b->balance_due)->toBe(25_000);   // 10k + 15k delta
});

it('logs a category_changed activity with old + new rate + delta', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('switchCategoryId', $this->categoryB->id)
        ->call('changeCategory');

    $stamp = \App\Models\Activity::query()
        ->where('subject_id', $this->booking->id)
        ->where('event', 'category_changed')
        ->latest('id')->first();
    expect($stamp)->not->toBeNull();
    $props = $stamp->properties ?? collect();
    $props = is_array($props) ? $props : $props->toArray();
    expect((int) $props['old_nightly_rate'])->toBe(30_000);
    expect((int) $props['new_nightly_rate'])->toBe(45_000);
    expect((int) $props['rate_delta'])->toBe(15_000);
    expect($props['new_category'])->toBe('Suite');
});

it('lower-rate category swap: balance_due floors at 0 + total drops', function (): void {
    // Set up a deposit-paid state: guest paid ₦30k, balance = 0.
    $this->booking->forceFill([
        'total_amount' => 30_000, 'balance_due' => 0, 'deposit_amount' => 30_000,
    ])->save();

    // Add a cheaper category.
    $cheap = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 20_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('switchCategoryId', $cheap->id)
        ->call('changeCategory');

    $b = $this->booking->refresh();
    expect((int) $b->total_amount)->toBe(20_000);
    // balance_due can't go negative; the ₦10k overpayment stays a
    // reconciliation task for the operator (wallet credit / coupon).
    expect((int) $b->balance_due)->toBe(0);
});

it('confirmAndPay writes a Payment row + flips the booking to Confirmed', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('toggleConfirmPay')
        ->set('confirmPayAmount', 30_000)
        ->set('confirmPayMethod', 'cash')
        ->call('confirmAndPay')
        ->assertDispatched('notify');

    $b = $this->booking->refresh();
    expect($b->booking_status)->toEqual(BookingStatus::Confirmed);
    expect($b->payment_status)->toEqual(PaymentStatus::Succeeded);
    expect((int) $b->balance_due)->toBe(0);

    $payment = Payment::query()->where('booking_id', $b->id)->latest('id')->first();
    expect($payment)->not->toBeNull();
    expect((int) $payment->amount)->toBe(30_000);
    expect($payment->method)->toBe('cash');
});

it('confirmAndPay with a partial deposit lands the booking On-hold with remainder due', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('toggleConfirmPay')
        ->set('confirmPayAmount', 15_000)
        ->set('confirmPayMethod', 'transfer')
        ->call('confirmAndPay');

    $b = $this->booking->refresh();
    expect($b->booking_status)->toEqual(BookingStatus::OnHold);
    expect((int) $b->balance_due)->toBe(15_000);
});

it('hides the check-in button until a room is assigned', function (): void {
    // Pending + unassigned → no check-in button (canCheckIn requires
    // Confirmed/OnHold + room pinned via MarkCheckedIn's own guard).
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertDontSee('data-testid="action-checkin"', false);
});

it('surfaces the check-in button after Confirm + Assign (today is checkin)', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->call('toggleConfirmPay')
        ->set('confirmPayAmount', 30_000)
        ->set('confirmPayMethod', 'cash')
        ->call('confirmAndPay')
        ->set('assignRoomId', $this->roomA->id)
        ->call('assignRoom')
        ->assertSee('data-testid="action-checkin"', false);
});

it('refuses changeCategory once a room is already assigned', function (): void {
    // Pin a room first.
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('assignRoomId', $this->roomA->id)
        ->call('assignRoom')
        ->set('switchCategoryId', $this->categoryB->id)
        ->call('changeCategory')
        ->assertDispatched('notify');

    // Category unchanged; the notify dispatched is the error path.
    expect($this->booking->refresh()->room_category_id)->toBe($this->categoryA->id);
});
