<?php

declare(strict_types=1);

use App\Actions\Bookings\IssueBookingCoupon;
use App\Actions\Bookings\ReassignRoom;
use App\Actions\Bookings\RecordBookingRefund;
use App\Enums\DiscountType;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Livewire\Dashboard\Reservations\Show as ReservationsShow;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'reassign-recon']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->deluxeCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe', 'base_rate' => 40_000,
    ]);
    $this->suiteCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 60_000,
    ]);
    $this->economyCat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Economy', 'base_rate' => 25_000,
    ]);
    $this->deluxe = Room::factory()->for($this->tenant)->for($this->property)->for($this->deluxeCat, 'category')->create([
        'room_number' => '101',
    ]);
    $this->suite = Room::factory()->for($this->tenant)->for($this->property)->for($this->suiteCat, 'category')->create([
        'room_number' => '201',
    ]);
    $this->economy = Room::factory()->for($this->tenant)->for($this->property)->for($this->economyCat, 'category')->create([
        'room_number' => '099',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── ReassignRoom action ──────────────────────────────────────────── */

it('reassigns across categories and reports a positive rate delta on upgrade', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(3)->toDateString(),
        'nights' => 2,
        'total_amount' => 80_000,      // 2 nights × ₦40k
        'room_total' => 80_000,
        'balance_due' => 80_000,
    ]);

    $result = app(ReassignRoom::class)->handle($booking, $this->suite, $this->manager);

    expect($result->rateDelta)->toBe(40_000);   // (60k − 40k) × 2
    expect($result->oldNightlyRate)->toBe(40_000);
    expect($result->newNightlyRate)->toBe(60_000);
    expect($result->nights)->toBe(2);

    $fresh = $booking->fresh();
    expect($fresh->room_id)->toBe($this->suite->id);
    expect($fresh->total_amount)->toBe(120_000);      // 80k + 40k
    expect($fresh->balance_due)->toBe(120_000);       // still owed
    // Cross-category reassign updates room_category_id too so the
    // header + arrivals table + isUnassigned() all reflect reality.
    expect($fresh->room_category_id)->toBe($this->suite->room_category_id);
});

it('reports a negative rate delta on downgrade and floors balance_due at 0', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(3)->toDateString(),
        'nights' => 2,
        'total_amount' => 80_000,
        'room_total' => 80_000,
        'balance_due' => 0,          // guest already paid in full
    ]);

    $result = app(ReassignRoom::class)->handle($booking, $this->economy, $this->manager);

    expect($result->rateDelta)->toBe(-30_000);        // (25k − 40k) × 2

    $fresh = $booking->fresh();
    expect($fresh->room_id)->toBe($this->economy->id);
    expect($fresh->total_amount)->toBe(50_000);       // 80k − 30k
    expect($fresh->balance_due)->toBe(0);             // floored — refund handled separately
});

it('pro-rata delta on a CheckedIn booking only covers remaining nights', function (): void {
    // 3-night stay, guest already 1 night in. Swapping to a
    // ₦60k suite means 1 night stays at ₦40k (already spent),
    // 2 remaining nights swap to ₦60k → delta (60k-40k) × 2 = 40k,
    // NOT the (60k-40k) × 3 = 60k the old formula returned.
    \Illuminate\Support\Carbon::setTestNow('2027-05-06 10:00');
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->create([
        'booking_status' => \App\Enums\BookingStatus::CheckedIn,
        'checkin_date' => '2027-05-05',
        'checkout_date' => '2027-05-08',
        'nights' => 3,
        'total_amount' => 120_000,      // 3 × ₦40k
        'room_total' => 120_000,
        'balance_due' => 0,
    ]);

    $result = app(ReassignRoom::class)->handle($booking, $this->suite, $this->manager);

    expect($result->rateDelta)->toBe(40_000); // (60k − 40k) × 2 remaining
    expect($booking->fresh()->total_amount)->toBe(160_000); // 120k + 40k

    \Illuminate\Support\Carbon::setTestNow();
});

it('same-rate reassignment does not touch booking totals', function (): void {
    $sameRateRoom = Room::factory()->for($this->tenant)->for($this->property)->for($this->deluxeCat, 'category')->create([
        'room_number' => '102',
    ]);
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(2)->toDateString(),
        'nights' => 1,
        'total_amount' => 40_000,
        'balance_due' => 40_000,
    ]);

    $result = app(ReassignRoom::class)->handle($booking, $sameRateRoom, $this->manager);

    expect($result->rateDelta)->toBe(0);
    expect($booking->fresh()->total_amount)->toBe(40_000);
    expect($booking->fresh()->balance_due)->toBe(40_000);
});

/* ── RecordBookingRefund ─────────────────────────────────────────── */

it('records a refund as a Payment row with status Refunded', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create();

    $payment = app(RecordBookingRefund::class)->handle(
        $booking, $this->manager, amount: 15_000, method: 'transfer', referenceNote: 'Room downgrade',
    );

    expect($payment->status)->toBe(PaymentStatus::Refunded);
    expect($payment->amount)->toBe(15_000);
    expect($payment->method)->toBe('transfer');
    expect($payment->booking_id)->toBe($booking->id);
    expect($payment->reference_note)->toBe('Room downgrade');
});

it('refuses a refund of zero or negative amount', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create();
    app(RecordBookingRefund::class)->handle($booking, $this->manager, amount: 0, method: 'cash');
})->throws(InvalidArgumentException::class);

/* ── IssueBookingCoupon ──────────────────────────────────────────── */

it('issues a unique single-use fixed-value coupon', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create();

    $discount = app(IssueBookingCoupon::class)->handle(
        $booking, $this->manager, amount: 12_500, reason: 'Downgrade refund',
    );

    expect($discount->type)->toBe(DiscountType::Fixed);
    expect($discount->value)->toBe(12_500);
    expect($discount->max_uses)->toBe(1);
    expect($discount->used_count)->toBe(0);
    expect($discount->active)->toBeTrue();
    expect($discount->code)->toStartWith('CREDIT-');
    expect(strlen($discount->code))->toBe(13);  // CREDIT- + 6 chars
    expect($discount->description)->toContain($booking->booking_ref);
    expect($discount->description)->toContain('Downgrade refund');
});

it('issued coupon codes are unique per tenant', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create();

    $a = app(IssueBookingCoupon::class)->handle($booking, $this->manager, 1_000);
    $b = app(IssueBookingCoupon::class)->handle($booking, $this->manager, 2_000);

    expect($a->code)->not->toBe($b->code);
});

/* ── End-to-end via Show component ───────────────────────────────── */

it('reassigning to a pricier room opens the collect-overage reconciliation', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(2)->toDateString(),
        'nights' => 1,
        'total_amount' => 40_000,
        'balance_due' => 40_000,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('reassignTargetRoomId', $this->suite->id)
        ->call('reassignRoom')
        ->assertSet('pendingReconcileDelta', 20_000)   // (60k − 40k) × 1
        ->assertSet('reconcileMode', 'collect')
        ->assertSet('reconcileAmount', 20_000);
});

it('reassigning to a cheaper room defaults to refund but coupon is also selectable', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(2)->toDateString(),
        'nights' => 1,
        'total_amount' => 40_000,
        'balance_due' => 0,
    ]);

    $component = Livewire::actingAs($this->manager, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('reassignTargetRoomId', $this->economy->id)
        ->call('reassignRoom')
        ->assertSet('pendingReconcileDelta', -15_000)
        ->assertSet('reconcileMode', 'refund');

    $component->call('chooseReconcileMode', 'coupon')
        ->assertSet('reconcileMode', 'coupon');
});

it('confirmReconcile in coupon mode issues a discount and exposes the code', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(2)->toDateString(),
        'nights' => 1,
        'total_amount' => 40_000,
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('reassignTargetRoomId', $this->economy->id)
        ->call('reassignRoom')
        ->call('chooseReconcileMode', 'coupon')
        ->call('confirmReconcile')
        ->assertSet('issuedCouponCode', fn ($v) => is_string($v) && str_starts_with($v, 'CREDIT-'));

    expect(Discount::query()->where('value', 15_000)->exists())->toBeTrue();
});

it('confirmReconcile in refund mode writes a Refunded Payment row', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(2)->toDateString(),
        'nights' => 1,
        'total_amount' => 40_000,
        'balance_due' => 0,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('reassignTargetRoomId', $this->economy->id)
        ->call('reassignRoom')
        ->set('reconcileMethod', 'cash')
        ->call('confirmReconcile')
        // Success dispatches a notify ok; no error modal fires.
        ->assertNotDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    $refund = Payment::query()->where('booking_id', $booking->id)
        ->where('status', PaymentStatus::Refunded->value)
        ->first();

    expect($refund)->not->toBeNull();
    expect($refund->amount)->toBe(15_000);
    expect($refund->method)->toBe('cash');
});

it('collect mode on positive delta cannot switch to refund server-side', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->deluxe)->confirmed()->create([
        'checkin_date' => now()->addDay()->toDateString(),
        'checkout_date' => now()->addDays(2)->toDateString(),
        'nights' => 1,
        'total_amount' => 40_000,
        'balance_due' => 40_000,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationsShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('reassignTargetRoomId', $this->suite->id)
        ->call('reassignRoom')
        ->call('chooseReconcileMode', 'refund')   // wrong sign — should be ignored
        ->assertSet('reconcileMode', 'collect');   // stays on collect
});
