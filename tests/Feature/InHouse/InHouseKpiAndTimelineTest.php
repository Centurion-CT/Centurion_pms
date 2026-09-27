<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\InHouse\Index as InHouseIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * KPI drill-down buckets (server-hydrated ids the client uses to
 * filter into a drawer) + timeline pins (positioned relative to the
 * property's advertised check-out time).
 */
beforeEach(function (): void {
    Carbon::setTestNow('2027-05-15 09:00:00');

    $this->tenant = Tenant::factory()->create(['slug' => 'ih-kpi']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'house_rules' => ['check_out_to' => '11:00'],
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($this->operator, 'web');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('buckets the in-house guests into today/tomorrow/overdue for KPI drill-downs', function (): void {
    // Overdue — checkout was 2 days ago.
    $overdue = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(5),
        'checkout_date' => Carbon::today()->subDays(2),
    ]);
    // Today.
    $today = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(2),
        'checkout_date' => Carbon::today(),
    ]);
    // Tomorrow.
    $tomorrow = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    // Later — not in any date-specific bucket.
    $later = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDays(4),
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertViewHas('bucketIds', function (array $b) use ($overdue, $today, $tomorrow, $later): bool {
            return $b['overdue'] === [$overdue->id]
                && $b['today'] === [$today->id]
                && $b['tomorrow'] === [$tomorrow->id]
                && in_array($later->id, $b['in_house'], true)
                && count($b['in_house']) === 4;
        });
});

it('timeline pins include today\'s check-outs and overdue guests, ordered by minute', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Overdue Guy',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDays(3),
        'checkout_date' => Carbon::today()->subDay(),
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Today Guy',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
    ]);
    // A guest leaving TOMORROW should NOT appear on today's timeline.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Tomorrow Guy',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertViewHas('timelinePins', function (array $pins): bool {
            $names = array_map(fn ($p) => $p['guest'], $pins);
            return count($pins) === 2
                && in_array('Overdue', $names, true)
                && in_array('Today', $names, true);
        });
});

it('timeline pin for today\'s check-out sits at the property\'s check_out_to minute', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'On Time',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertViewHas('timelinePins', function (array $pins): bool {
            $onTime = collect($pins)->firstWhere('guest', 'On');
            // check_out_to = 11:00 → 11 × 60 = 660 minutes.
            return $onTime && (int) $onTime['minute'] === 660 && $onTime['is_overdue'] === false;
        })
        ->assertViewHas('checkoutTimeStr', '11:00');
});

it('timeline defaults check-out time to 11:00 when the property has no house rules', function (): void {
    $this->property->update(['house_rules' => null]);

    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
    ]);

    Livewire::test(InHouseIndex::class)
        ->assertViewHas('checkoutTimeStr', '11:00');
});
