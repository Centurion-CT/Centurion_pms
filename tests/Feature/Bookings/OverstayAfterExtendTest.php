<?php

declare(strict_types=1);

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
 * A guest who was billed for overstay pre-extend and THEN goes
 * overdue again post-extend used to show as "OVERDUE" on the
 * in-house table with no accrued fee. Root cause:
 * overstayNightsCharged() counted every historical Overstay
 * BookingCharge (including the ones that predate the extension), so
 * unchargedOverstayNights = max(0, projected - already-charged) went
 * to 0.
 *
 * Fix: filter Overstay charges by created_at >= the most recent
 * `extended` activity - anything older was absorbed by the extension.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'overstay-after-extend']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 60_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->cat, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('does NOT count pre-extend settled Overstay charges toward overstayNightsCharged', function (): void {
    // Glory: original checkout Sep 5. Auto-billed 2 nights (Sep 5→6,
    // Sep 6→7) as settled Overstay. Then operator extended stay to
    // Sep 8; those old Overstay rows stayed on the ledger for audit
    // but their nights are now inside the extended stay.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Glory Igiemwin',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::create(2026, 9, 4),
        'checkout_date' => Carbon::create(2026, 9, 8),   // POST-extension checkout
        'nights' => 4,
    ]);

    // Two Overstay charges from BEFORE the extension - both settled.
    Carbon::setTestNow(Carbon::create(2026, 9, 6, 7, 45, 0));
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id,
        'type' => BookingChargeType::Overstay->value,
        'description' => '1 night overstay · Sep 5 → Sep 6',
        'amount' => 60_000, 'settled' => true, 'settled_method' => 'cash', 'settled_at' => Carbon::now(),
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 7, 11, 54, 0));
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id,
        'type' => BookingChargeType::Overstay->value,
        'description' => '1 night overstay · Sep 6 → Sep 7',
        'amount' => 60_000, 'settled' => true, 'settled_method' => 'cash', 'settled_at' => Carbon::now(),
    ]);

    // The `extended` activity is dated AFTER both Overstay charges.
    Carbon::setTestNow(Carbon::create(2026, 9, 8, 15, 37, 0));
    activity('booking')->causedBy($this->operator)->performedOn($b)->event('extended')
        ->withProperties(['extension_full_value' => 240_000])
        ->log('extended');

    // Back to "today" (Sep 9) - guest is 1 night overdue.
    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    expect($b->projectedOverstayNights())->toBe(1);
    // The two pre-extend Overstay rows should NOT count now.
    expect($b->overstayNightsCharged())->toBe(0);
    expect($b->unchargedOverstayNights())->toBe(1);
    expect($b->unchargedOverstayAmount())->toBe(60_000);
    expect($b->outstandingTotal())->toBe(60_000);
});

it('counts Overstay charges created AFTER the extension (new overstay cycle)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::create(2026, 9, 4),
        'checkout_date' => Carbon::create(2026, 9, 8),
        'nights' => 4,
    ]);

    // Extension activity FIRST (pre-existing pattern).
    Carbon::setTestNow(Carbon::create(2026, 9, 8, 10, 0, 0));
    activity('booking')->causedBy($this->operator)->performedOn($b)->event('extended')
        ->log('extended');

    // Then Sep 9: auto-cron writes a new Overstay charge - THIS one
    // should count because it's newer than the extension.
    Carbon::setTestNow(Carbon::create(2026, 9, 9, 7, 45, 0));
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id,
        'type' => BookingChargeType::Overstay->value,
        'description' => '1 night overstay · Sep 8 → Sep 9',
        'amount' => 60_000, 'settled' => false,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    expect($b->projectedOverstayNights())->toBe(1);
    expect($b->overstayNightsCharged())->toBe(1);
    expect($b->unchargedOverstayNights())->toBe(0);
    // Ledger picks up the unsettled charge instead - outstanding = 60k.
    expect($b->outstandingTotal())->toBe(60_000);
});

it('counts every historical Overstay charge when the booking was NEVER extended', function (): void {
    // No extension → the old count-all behaviour still applies, so
    // guests without an extend history reconcile the same way.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::create(2026, 9, 6),
        'checkout_date' => Carbon::create(2026, 9, 7),
        'nights' => 1,
    ]);
    BookingCharge::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id,
        'type' => BookingChargeType::Overstay->value,
        'description' => '1 night overstay',
        'amount' => 60_000, 'settled' => true, 'settled_method' => 'cash', 'settled_at' => Carbon::now(),
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    expect($b->projectedOverstayNights())->toBe(2);
    expect($b->overstayNightsCharged())->toBe(1);
    expect($b->unchargedOverstayNights())->toBe(1);
    expect($b->unchargedOverstayAmount())->toBe(60_000);
});
