<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'maint']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->operator, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

it('creates Blocked availability rows for every night in the range', function (): void {
    $from = Carbon::today()->addDays(2);
    $to = Carbon::today()->addDays(5);   // exclusive → 3 nights blocked

    Livewire::test(ReservationsIndex::class)
        ->call('resetMaintenanceForm')
        ->set('maintenanceRoomId', $this->room->id)
        ->set('maintenanceFrom', $from->toDateString())
        ->set('maintenanceTo', $to->toDateString())
        ->set('maintenanceReason', 'Deep clean')
        ->call('submitMaintenance')
        ->assertHasNoErrors();

    $blocks = AvailabilityBlock::query()
        ->where('room_id', $this->room->id)
        ->where('type', AvailabilityBlockType::Blocked->value)
        ->orderBy('date')
        ->get();

    expect($blocks)->toHaveCount(3);
    expect($blocks->first()->date->toDateString())->toBe($from->toDateString());
    expect($blocks->last()->date->toDateString())->toBe($to->copy()->subDay()->toDateString());
    expect($blocks->first()->reason)->toBe('Deep clean');
});

it('rejects a range that overlaps an existing booking', function (): void {
    $day = Carbon::today()->addDays(3);
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id'   => $this->room->id,
        'date'      => $day->toDateString(),
        'type'      => AvailabilityBlockType::Booked->value,
    ]);

    Livewire::test(ReservationsIndex::class)
        ->call('resetMaintenanceForm')
        ->set('maintenanceRoomId', $this->room->id)
        ->set('maintenanceFrom', $day->copy()->subDay()->toDateString())
        ->set('maintenanceTo', $day->copy()->addDay()->toDateString())
        ->call('submitMaintenance')
        ->assertHasErrors('maintenanceRoomId');

    expect(AvailabilityBlock::query()
        ->where('type', AvailabilityBlockType::Blocked->value)
        ->count())->toBe(0);
});

it('validation blocks a to-date not after from-date', function (): void {
    $day = Carbon::today()->addDays(1);

    Livewire::test(ReservationsIndex::class)
        ->call('resetMaintenanceForm')
        ->set('maintenanceRoomId', $this->room->id)
        ->set('maintenanceFrom', $day->toDateString())
        ->set('maintenanceTo', $day->toDateString())
        ->call('submitMaintenance')
        ->assertHasErrors('maintenanceTo');
});
