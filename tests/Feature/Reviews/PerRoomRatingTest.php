<?php

declare(strict_types=1);

use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rate-room']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'A']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'B']);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

it('returns 0 when a room has no reviews', function (): void {
    $summary = $this->roomA->ratingSummary();
    expect($summary['average'])->toBe(0.0);
    expect($summary['count'])->toBe(0);
});

it('aggregates only reviews tagged to that room', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->roomA)->create(['rating' => 5]);
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->roomA)->create(['rating' => 4]);
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->roomB)->create(['rating' => 3]);
    // Property-wide review — must NOT count toward either room
    Review::factory()->for($this->tenant)->for($this->property)->create(['rating' => 1]);

    expect($this->roomA->ratingSummary())->toBe(['average' => 4.5, 'count' => 2]);
    expect($this->roomB->ratingSummary())->toBe(['average' => 3.0, 'count' => 1]);
});

it('ignores hidden reviews', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->roomA)->create(['rating' => 5]);
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->roomA)->hidden()->create(['rating' => 1]);

    expect($this->roomA->ratingSummary())->toBe(['average' => 5.0, 'count' => 1]);
});

it('property-level summary includes both per-room and property-wide reviews', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->forRoom($this->roomA)->create(['rating' => 5]);
    Review::factory()->for($this->tenant)->for($this->property)->create(['rating' => 3]);

    $summary = $this->property->ratingSummary();
    expect($summary['count'])->toBe(2);
    expect($summary['average'])->toBe(4.0);
});

it('falls back to property rating on landing room cards when room has no own reviews', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->create(['rating' => 5]);

    $response = $this->get('http://rate-room.keverna.test/');
    $response->assertOk();
    // Room without per-room reviews still shows the property's 5.0 fallback
    $response->assertSee('5');
});
