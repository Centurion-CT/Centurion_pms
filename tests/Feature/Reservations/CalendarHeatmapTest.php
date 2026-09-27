<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Models\AvailabilityBlock;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Dashboard\CalendarHeatmap;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'heat']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
});

afterEach(fn () => Tenant::forgetCurrent());

it('classifies days as free / partial / full by occupancy percentage', function (): void {
    // 4 active rooms.
    $rooms = Room::factory()->count(4)->for($this->tenant)->for($this->property)->create();

    $day = Carbon::today()->addDays(2);

    // Book 3 of the 4 rooms for the day (= 75% → partial).
    foreach ($rooms->take(3) as $r) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id'   => $r->id,
            'date'      => $day->toDateString(),
            'type'      => AvailabilityBlockType::Booked->value,
        ]);
    }

    // Full-day the next day (4/4 booked → 100% → full).
    $day2 = $day->copy()->addDay();
    foreach ($rooms as $r) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id'   => $r->id,
            'date'      => $day2->toDateString(),
            'type'      => AvailabilityBlockType::Booked->value,
        ]);
    }

    $heat = app(CalendarHeatmap::class)->build(
        Carbon::today(),
        Carbon::today()->addDays(3),
    );

    expect($heat['total_rooms'])->toBe(4);

    $free = $heat['days'][Carbon::today()->toDateString()];
    expect($free['booked'])->toBe(0);
    expect($free['tier'])->toBe('free');

    $partial = $heat['days'][$day->toDateString()];
    expect($partial['booked'])->toBe(3);
    expect($partial['pct'])->toBe(75);
    expect($partial['tier'])->toBe('partial');

    $full = $heat['days'][$day2->toDateString()];
    expect($full['booked'])->toBe(4);
    expect($full['pct'])->toBe(100);
    expect($full['tier'])->toBe('full');
});

it('counts Blocked (maintenance) rows alongside Booked ones', function (): void {
    $roomA = Room::factory()->for($this->tenant)->for($this->property)->create();
    $roomB = Room::factory()->for($this->tenant)->for($this->property)->create();  // total = 2

    $day = Carbon::today()->addDays(5);

    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $roomA->id,
        'date' => $day->toDateString(), 'type' => AvailabilityBlockType::Booked->value,
    ]);
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id, 'room_id' => $roomB->id,
        'date' => $day->toDateString(), 'type' => AvailabilityBlockType::Blocked->value,
        'reason' => 'Maintenance',
    ]);

    $heat = app(CalendarHeatmap::class)->build(Carbon::today(), Carbon::today()->addDays(6));

    expect($heat['days'][$day->toDateString()]['booked'])->toBe(2);    // both rooms unavailable
    expect($heat['days'][$day->toDateString()]['pct'])->toBe(100);
    expect($heat['days'][$day->toDateString()]['tier'])->toBe('full');
});
