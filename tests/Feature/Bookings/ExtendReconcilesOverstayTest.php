<?php

declare(strict_types=1);

use App\Actions\Bookings\ExtendBooking;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Extending an overdue guest must reconcile the auto-Overstay
 * charges the system generated for the same nights - otherwise the
 * guest pays twice (once via auto-Overstay, once via the extension).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'reconcile']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create();

    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    // Guest with an original checkout 3 days ago -> 3 overstay
    // nights the auto-Overstay cron would have already billed.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(3),
        'nights' => 2,
        'total_amount' => 100_000, 'room_total' => 100_000,
        'balance_due' => 0,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('waives unsettled Overstay charges when the operator extends', function (): void {
    // Simulate what AutoDeductFromWallet would have written: 3
    // uncharged overstay nights at ₦50,000 each = ₦150,000 unsettled.
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => null,
        'type' => BookingChargeType::Overstay->value,
        'description' => '3 nights overstay (auto)',
        'amount' => 150_000,
        'settled' => false,
    ]);

    // Front desk extends by 5 nights to cover the overstayed period
    // + 2 fresh nights = ₦250,000 total extension.
    $newCheckout = Carbon::today()->addDays(2);
    app(ExtendBooking::class)->handle($this->booking, $newCheckout, $this->operator);

    // Auto-Overstay row is gone.
    expect(BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Overstay->value)
        ->count())->toBe(0);

    // One extension charge for the full ₦250,000 (no wallet-settled
    // overstay to net against here).
    $ext = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    expect((int) $ext->amount)->toBe(250_000);

    // Guest's outstanding is exactly the extension - no phantom
    // overstay left behind.
    expect((int) $this->booking->fresh()->outstandingTotal())->toBe(250_000);
});

it('nets settled Overstay against the extension so the guest is not double-charged', function (): void {
    // Guest had ₦100,000 in wallet, auto-Overstay cron settled two
    // of the three overdue nights (₦100,000). Third night stayed
    // unsettled.
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => null,
        'type' => BookingChargeType::Overstay->value,
        'description' => '2 nights overstay (auto, wallet-settled)',
        'amount' => 100_000,
        'settled' => true,
        'settled_at' => Carbon::now()->subDay(),
        'settled_method' => 'wallet',
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'created_by_operator_id' => null,
        'type' => BookingChargeType::Overstay->value,
        'description' => '1 night overstay (auto, unsettled)',
        'amount' => 50_000,
        'settled' => false,
    ]);

    $newCheckout = Carbon::today()->addDays(2);
    app(ExtendBooking::class)->handle($this->booking, $newCheckout, $this->operator);

    // Unsettled overstay wiped, settled one preserved (money moved).
    $overstayRows = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Overstay->value)
        ->get();
    expect($overstayRows)->toHaveCount(1);
    expect((bool) $overstayRows->first()->settled)->toBeTrue();

    // Extension charge = ₦250,000 (5 nights) minus ₦100,000 wallet-
    // paid overstay = ₦150,000 owed now.
    $ext = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    expect((int) $ext->amount)->toBe(150_000);

    // Outstanding = extension charge only (wallet-settled overstay
    // isn't in outstanding + unsettled overstay was waived).
    expect((int) $this->booking->fresh()->outstandingTotal())->toBe(150_000);
});

it('is a no-op on booking with no prior Overstay charges', function (): void {
    // Baseline sanity: extending a booking without any Overstay
    // charges works exactly as before - full extension charge.
    $newCheckout = Carbon::today()->addDays(2);
    app(ExtendBooking::class)->handle($this->booking, $newCheckout, $this->operator);

    $ext = BookingCharge::query()
        ->where('booking_id', $this->booking->id)
        ->where('type', BookingChargeType::Extension->value)
        ->firstOrFail();
    expect((int) $ext->amount)->toBe(250_000);
});
