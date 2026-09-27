<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Payments\Index as PaymentsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Payments list "Discount" column bug: prior to the fix it showed the
 * booking's manual_discount_amount on EVERY payment row for that
 * booking. That made two payments on the same booking (e.g., initial
 * ₦100k + settled extension charge) both display "−₦30,570 discount"
 * even though the discount was a booking-level attribute, not a per-
 * payment one. Now the column only renders on type=Full rows.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pay-disc-col']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => 210]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    Subscription::factory()->for($this->tenant)->create([
        'expires_at' => Carbon::now()->addYear(),
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('attributes the extension discount to the Extra payment (not the Full one)', function (): void {
    // Real scenario: guest paid ₦100k at booking creation (no
    // discount), then extended a night with a ₦10,190 discount, then
    // settled that extension charge. The discount belongs to the
    // extension payment - the Full payment ran BEFORE the discount
    // existed, so its row should render an em-dash.
    // Two-step create to mirror reality: booking is created without
    // a discount at Sep 7 13:22, extension + discount happens later.
    // Doing it in one factory-with-manual_discount=10190 would trip
    // the auto-logged `created` activity with the wrong initial
    // attributes; the helper reads from that activity to attribute
    // to Full-type payments.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'Ada Guest',
        'nights' => 1, 'room_total' => 100_000,
        'total_amount' => 100_000,
        'manual_discount_amount' => 0,
    ]);
    // Now simulate the extension bumping discount to 10,190.
    $booking->forceFill([
        'nights' => 2, 'room_total' => 200_000,
        'total_amount' => 189_810,
        'manual_discount_amount' => 10_190,
    ])->save();

    $full = Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 100_000, 'method' => 'transfer',
        'type' => PaymentType::Full,
        'paid_at' => Carbon::today()->setTime(9, 0),
        'paystack_reference' => 'manual-'.uniqid(),
    ]);

    // Extension charge with the discount trail our new extend flow
    // stamps + the Payment that SettleCharges wrote for it (paystack_
    // reference is "charge-{id}-{ulid}").
    $charge = \App\Models\BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->manager->id,
        'type' => \App\Enums\BookingChargeType::Extension->value,
        'description' => 'Stay extended to Sep 9, 2026 · 1 extra night · ₦10,190 discount applied',
        'amount' => 89_810,
        'settled' => true, 'settled_method' => 'cash', 'settled_at' => Carbon::today()->setTime(15, 0),
    ]);
    $extra = Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 89_810, 'method' => 'cash',
        'type' => PaymentType::Extra,
        'paid_at' => Carbon::today()->setTime(15, 0),
        'paystack_reference' => 'charge-'.$charge->id.'-'.strtolower(bin2hex(random_bytes(6))),
    ]);

    $r = Livewire::test(PaymentsIndex::class)
        ->set('dateFrom', Carbon::today()->toDateString())
        ->set('dateTo', Carbon::today()->toDateString());
    $html = $r->html();

    // Full row - no discount attributed (booking created without one).
    preg_match(
        '/data-testid="payment-discount-'.$full->id.'"[^>]*>(.*?)<\/td>/s',
        $html, $mFull,
    );
    expect($mFull[1] ?? '')->not->toContain('₦');

    // Extra row - the ₦10,190 discount from the extension charge shows here.
    preg_match(
        '/data-testid="payment-discount-'.$extra->id.'"[^>]*>(.*?)<\/td>/s',
        $html, $mExtra,
    );
    expect($mExtra[1] ?? '')->toContain('10,190');
});

it('shows the initial discount on the Full row when it was applied at booking creation', function (): void {
    // Different scenario: operator gave the guest a discount right
    // at booking creation (e.g., "walk-in special"). That discount
    // WAS baked into the Full payment - show it there.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'nights' => 1, 'room_total' => 100_000,
        'total_amount' => 90_000,
        'manual_discount_amount' => 10_000,
    ]);

    $full = Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 90_000, 'method' => 'cash',
        'type' => PaymentType::Full,
        'paid_at' => Carbon::today(),
        'paystack_reference' => 'manual-'.uniqid(),
    ]);

    $r = Livewire::test(PaymentsIndex::class)
        ->set('dateFrom', Carbon::today()->toDateString())
        ->set('dateTo', Carbon::today()->toDateString());

    preg_match(
        '/data-testid="payment-discount-'.$full->id.'"[^>]*>(.*?)<\/td>/s',
        $r->html(), $m,
    );
    expect($m[1] ?? '')->toContain('10,000');
});

it('renders em-dash on an Extra payment for a charge with no discount trail', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'nights' => 1, 'room_total' => 100_000,
        'total_amount' => 100_000,
        'manual_discount_amount' => 0,
    ]);
    $charge = \App\Models\BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $booking->id,
        'created_by_operator_id' => $this->manager->id,
        'type' => \App\Enums\BookingChargeType::RoomService->value,
        'description' => 'Laundry',        // no discount marker
        'amount' => 5_000,
        'settled' => true, 'settled_method' => 'cash', 'settled_at' => Carbon::today(),
    ]);
    $extra = Payment::factory()->for($this->tenant)->for($booking)->succeeded()->create([
        'amount' => 5_000, 'method' => 'cash',
        'type' => PaymentType::Extra,
        'paid_at' => Carbon::today(),
        'paystack_reference' => 'charge-'.$charge->id.'-'.uniqid(),
    ]);

    $r = Livewire::test(PaymentsIndex::class)
        ->set('dateFrom', Carbon::today()->toDateString())
        ->set('dateTo', Carbon::today()->toDateString());

    preg_match(
        '/data-testid="payment-discount-'.$extra->id.'"[^>]*>(.*?)<\/td>/s',
        $r->html(), $m,
    );
    expect($m[1] ?? '')->not->toContain('₦');
});
