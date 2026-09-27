<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Reports\RevenueForecast;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'fin-forecast']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('surfaces on-books revenue for confirmed forward bookings', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-01 09:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-11', // today + 10
        'total_amount' => 200_000,
    ]);

    $series = app(RevenueForecast::class)->project(30);

    $day = collect($series)->firstWhere('date', '2030-06-11');

    expect($day)->not->toBeNull();
    expect($day['on_books'])->toBe(200_000);
});

it('projects trailing-4-week same-weekday average for open dates', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-01 09:00:00')); // 2030-06-01 is a Saturday.

    // Seed the last 4 Mondays with 100k each.
    foreach (['2030-05-06', '2030-05-13', '2030-05-20', '2030-05-27'] as $mondayDate) {
        Booking::factory()->for($this->tenant)->for($this->room)->create([
            'booking_status' => BookingStatus::CheckedOut,
            'checkin_date'   => $mondayDate,
            'total_amount'   => 100_000,
        ]);
    }

    $series = app(RevenueForecast::class)->project(30);

    // Next Monday after 2030-06-01 is 2030-06-03.
    $nextMonday = collect($series)->firstWhere('date', '2030-06-03');

    expect($nextMonday)->not->toBeNull();
    expect($nextMonday['on_books'])->toBe(0);
    expect($nextMonday['projected'])->toBe(100_000);
    expect($nextMonday['is_estimate'])->toBeTrue();
});

it('does not project on top of an already-booked date', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-01 09:00:00'));

    // Historical Mondays average 100k.
    foreach (['2030-05-06', '2030-05-13', '2030-05-20', '2030-05-27'] as $mondayDate) {
        Booking::factory()->for($this->tenant)->for($this->room)->create([
            'booking_status' => BookingStatus::CheckedOut,
            'checkin_date'   => $mondayDate,
            'total_amount'   => 100_000,
        ]);
    }
    // Next Monday already has a 250k confirmed booking — the historical
    // average is lower, so projected should be zero (we trust the on-books
    // number rather than piling an "estimate" on top of it).
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-06-03',
        'total_amount' => 250_000,
    ]);

    $series = app(RevenueForecast::class)->project(30);
    $day = collect($series)->firstWhere('date', '2030-06-03');

    expect($day['on_books'])->toBe(250_000);
    expect($day['projected'])->toBe(0);
    expect($day['is_estimate'])->toBeFalse();
});

it('clamps horizon between 1 and 90 days', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-01'));

    $small = app(RevenueForecast::class)->project(0);
    $big = app(RevenueForecast::class)->project(500);

    expect($small)->toHaveCount(1);
    expect($big)->toHaveCount(90);
});

it('excludes cancelled and no-show bookings from history', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-06-01 09:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create([
        'checkin_date' => '2030-05-06',
        'total_amount' => 100_000,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::NoShow,
        'checkin_date'   => '2030-05-13',
        'total_amount'   => 100_000,
    ]);

    $series = app(RevenueForecast::class)->project(30);
    $nextMonday = collect($series)->firstWhere('date', '2030-06-03');

    expect($nextMonday['projected'])->toBe(0);
});
