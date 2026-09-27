<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The observer keeps `customers.lifetime_value` + `total_bookings`
 * truthful on EVERY booking save — no code path can leave them
 * stale. Covers paths that don't go through CustomerLinker
 * (webhooks, scheduled cron, ad-hoc admin edits).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'agg-obs']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->customer = Customer::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Repeat Guest',
        'phone' => '+2348011111111',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('recomputes when a booking with customer_id is inserted', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000,
    ]);

    expect((int) $this->customer->fresh()->lifetime_value)->toBe(40_000);
    expect((int) $this->customer->fresh()->total_bookings)->toBe(1);
});

it('recomputes when total_amount changes on an existing linked booking', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000,
    ]);
    expect((int) $this->customer->fresh()->lifetime_value)->toBe(40_000);

    // Direct save — bypasses CustomerLinker deliberately, observer
    // should still catch it.
    $b->forceFill(['total_amount' => 55_000])->save();

    expect((int) $this->customer->fresh()->lifetime_value)->toBe(55_000);
});

it('recomputes when a booking status flips from Pending to CheckedIn', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::Pending,
        'total_amount' => 40_000,
    ]);
    // Pending doesn't count for LTV.
    expect((int) $this->customer->fresh()->lifetime_value)->toBe(0);

    $b->forceFill(['booking_status' => BookingStatus::CheckedIn->value])->save();

    expect((int) $this->customer->fresh()->lifetime_value)->toBe(40_000);
});

it('recomputes when a booking is Cancelled (drops from LTV)', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000,
    ]);
    expect((int) $this->customer->fresh()->lifetime_value)->toBe(40_000);

    $b->forceFill(['booking_status' => BookingStatus::Cancelled->value])->save();

    expect((int) $this->customer->fresh()->lifetime_value)->toBe(0);
    expect((int) $this->customer->fresh()->total_bookings)->toBe(0);
});

it('sums multiple linked bookings correctly', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 120_000,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 60_000,
    ]);

    // The exact Glory scenario — LTV should sum both.
    expect((int) $this->customer->fresh()->lifetime_value)->toBe(180_000);
    expect((int) $this->customer->fresh()->total_bookings)->toBe(2);
});

it('re-runs on the PRIOR customer when customer_id changes', function (): void {
    $other = Customer::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Not Guest',
        'phone' => '+2348022222222',
        'lifecycle' => CustomerLifecycle::Active,
    ]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000,
    ]);
    expect((int) $this->customer->fresh()->lifetime_value)->toBe(40_000);
    expect((int) $other->fresh()->lifetime_value)->toBe(0);

    // Re-link to the other customer.
    $b->forceFill(['customer_id' => $other->id])->save();

    // Prior loses the sum; new gains it.
    expect((int) $this->customer->fresh()->lifetime_value)->toBe(0);
    expect((int) $other->fresh()->lifetime_value)->toBe(40_000);
});

it('no-ops when a save touches only unrelated columns', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'customer_id' => $this->customer->id,
        'booking_status' => BookingStatus::CheckedIn,
        'total_amount' => 40_000,
        'special_requests' => 'first note',
    ]);
    $original = $this->customer->fresh()->updated_at;

    // Editing special_requests is not on the observer's affecting
    // column list — the recompute query should NOT fire.
    Carbon::setTestNow(now()->addMinutes(2));
    $b->forceFill(['special_requests' => 'new note'])->save();

    // updated_at unchanged (no touch to customer row).
    expect($this->customer->fresh()->updated_at->equalTo($original))->toBeTrue();
});
