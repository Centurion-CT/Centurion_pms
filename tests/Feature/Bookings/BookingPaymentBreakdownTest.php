<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Bookings\Show as BookingShow;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Operator-side booking-detail payment breakdown card. Confirms the
 * new panel shows the fee flow for online-engine bookings
 * (marketplace + tenant-online) - room subtotal - Keverna fee -
 * Paystack processing = operator payout - and stays hidden for
 * walk-in / OTA / phone bookings that carry no fees.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'payment-breakdown']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the payment breakdown card for a marketplace booking with fees', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Succeeded,
        'total_amount' => 53_250,
        'room_total'   => 50_000,
        'keverna_fee'  => 2_500,
        'paystack_fee' => 750,
        'balance_due'  => 0,
        'source_note'  => 'Keverna Stays marketplace',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(BookingShow::class, ['bookingRef' => $booking->booking_ref])
        ->assertOk()
        ->assertSee('data-testid="booking-payment-breakdown"', false)
        ->assertSee('data-testid="pb-room-subtotal"', false)
        ->assertSee('data-testid="pb-keverna-fee"', false)
        ->assertSee('data-testid="pb-paystack-fee"', false)
        ->assertSee('data-testid="pb-guest-paid"', false)
        ->assertSee('data-testid="pb-operator-payout"', false)
        ->assertSee('Keverna Stays')
        ->assertSee('50,000')     // room subtotal
        ->assertSee('2,500')      // Keverna fee
        ->assertSee('750')        // Paystack fee
        ->assertSee('53,250')     // guest paid
        ->assertSee(number_format(53_250 - 2_500 - 750)); // operator payout
});

it('hides the breakdown card for a walk-in booking that carries no fees', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Succeeded,
        'total_amount' => 40_000,
        'room_total'   => 40_000,
        'keverna_fee'  => 0,
        'paystack_fee' => 0,
        'balance_due'  => 0,
        'source_note'  => 'Walk-in',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(BookingShow::class, ['bookingRef' => $booking->booking_ref])
        ->assertOk()
        ->assertDontSee('data-testid="booking-payment-breakdown"', false)
        ->assertDontSee('Keverna service fee');
});

it('adds a deposit + balance note when the booking is a pay-at-property hold', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status'  => BookingStatus::OnHold,
        'payment_status'  => PaymentStatus::Succeeded,
        'total_amount'    => 53_250,
        'room_total'      => 50_000,
        'keverna_fee'     => 2_500,
        'paystack_fee'    => 750,
        'deposit_amount'  => 15_975,   // 30% of 53,250
        'balance_due'     => 53_250 - 15_975,
        'source_note'     => 'Keverna Stays marketplace',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(BookingShow::class, ['bookingRef' => $booking->booking_ref])
        ->assertOk()
        ->assertSee('data-testid="pb-balance-note"', false)
        ->assertSee('collected at property')
        ->assertSee('15,975')  // deposit paid
        ->assertSee(number_format(53_250 - 15_975)); // balance
});

it('marks the breakdown as Keverna Stays only for marketplace bookings', function (): void {
    // Marketplace variant carries the badge.
    $mp = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 42_000, 'room_total' => 40_000,
        'keverna_fee' => 2_000, 'paystack_fee' => 600,
        'source_note' => 'Keverna Stays marketplace',
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(BookingShow::class, ['bookingRef' => $mp->booking_ref])
        ->assertSee('Keverna Stays')
        ->assertSee('settles to your Paystack subaccount');

    // Tenant-online booking has fees but no marketplace badge.
    $tenantOnline = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 42_000, 'room_total' => 40_000,
        'keverna_fee' => 2_000, 'paystack_fee' => 600,
        'source_note' => null,
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(BookingShow::class, ['bookingRef' => $tenantOnline->booking_ref])
        ->assertSee('data-testid="booking-payment-breakdown"', false)
        ->assertDontSee('settles to your Paystack subaccount');
});
