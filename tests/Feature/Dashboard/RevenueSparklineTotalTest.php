<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

/**
 * Regression: the "Revenue · last 30 days" sparkline used to sum
 * the CLAMPED-to-zero daily points, so a day where refunds exceeded
 * new cash was swallowed from the header total. Fixed to sum the
 * raw daily nets (then max-with-0) — same pattern as monthRevenueBars.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'spark-total']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('sparkline total is NET (deducts refund-only days) not the sum of clamped points', function (): void {
    Carbon::setTestNow('2027-05-15 09:00:00');

    // Day A: +100k succeeded.
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $this->booking->id,
        'amount' => 100_000,
        'type' => PaymentType::Full,
        'status' => PaymentStatus::Succeeded,
        'paid_at' => Carbon::parse('2027-05-13 10:00:00'),
    ]);
    // Day B (later): -50k refund only (no new cash that day).
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $this->booking->id,
        'amount' => 50_000,
        'type' => PaymentType::Full,
        'status' => PaymentStatus::Refunded,
        'paid_at' => Carbon::parse('2027-05-14 10:00:00'),
    ]);

    $data = app(DashboardSnapshot::class)->build(bypass: true);

    // Net across the window: 100k received minus 50k refunded = 50k.
    // Pre-fix bug: sum of clamped points would be 100k (day B clamped
    // to 0 before summing → 50k refund vanished from the total).
    expect((int) $data['spark']['total'])->toBe(50_000);

    // Chart points DO clamp the refund-only day to 0 for display —
    // negative values would drop the line under the x-axis.
    $points = $data['spark']['points'];
    // Every point is >= 0.
    foreach ($points as $p) {
        expect((int) $p)->toBeGreaterThanOrEqual(0);
    }
});

it('sparkline total floors at zero when refunds exceed all cash in the window', function (): void {
    Carbon::setTestNow('2027-05-15 09:00:00');

    // Only a refund in the window — nothing else.
    Payment::factory()->for($this->tenant)->create([
        'booking_id' => $this->booking->id,
        'amount' => 30_000,
        'type' => PaymentType::Full,
        'status' => PaymentStatus::Refunded,
        'paid_at' => Carbon::parse('2027-05-14 10:00:00'),
    ]);

    $data = app(DashboardSnapshot::class)->build(bypass: true);
    // Never dips below zero.
    expect((int) $data['spark']['total'])->toBe(0);
});
