<?php

declare(strict_types=1);

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Home;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Role-specific dashboard for the housekeeping chain — housekeeper,
 * porter, supervisor. Home::render() branches when the current
 * operator carries one of those roles and returns a tuned view
 * with their queue, done-today, and rejected outstanding.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'hk-dash']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->r101 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => '101',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $this->r102 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => '102',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);

    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->porter = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter]);
    $this->supervisor = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Supervisor]);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('housekeeper sees Pending inspections in their queue', function (): void {
    // Two Pending inspections — housekeeper's queue.
    app(OpenRoomInspection::class)->handle($this->r101);
    app(OpenRoomInspection::class)->handle($this->r102);

    Livewire::actingAs($this->housekeeper, 'web')
        ->test(Home::class)
        ->assertViewIs('livewire.dashboard.housekeeping-home')
        ->assertViewHas('roleLabel', 'Housekeeping')
        ->assertViewHas('kpis', fn (array $k) => $k['queue_count'] === 2
            && $k['done_today'] === 0
            && $k['failed_count'] === 0
            && $k['in_flight_count'] === 2);
});

it('porter sees only CleaningDone inspections in their queue', function (): void {
    // One Pending, one CleaningDone. Porter should only see the second.
    app(OpenRoomInspection::class)->handle($this->r101);
    $submitted = app(OpenRoomInspection::class)->handle($this->r102);
    $submitted->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($submitted, $this->housekeeper);

    Livewire::actingAs($this->porter, 'web')
        ->test(Home::class)
        ->assertViewIs('livewire.dashboard.housekeeping-home')
        ->assertViewHas('roleLabel', 'Porter')
        ->assertViewHas('kpis', fn (array $k) => $k['queue_count'] === 1
            && $k['in_flight_count'] === 2);
});

it('supervisor sees only PorterVerified inspections in their queue', function (): void {
    $advanced = app(OpenRoomInspection::class)->handle($this->r101);
    $advanced->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($advanced, $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($advanced->refresh(), $this->porter);

    // Also one Pending — supervisor should NOT see it in their queue.
    app(OpenRoomInspection::class)->handle($this->r102);

    Livewire::actingAs($this->supervisor, 'web')
        ->test(Home::class)
        ->assertViewIs('livewire.dashboard.housekeeping-home')
        ->assertViewHas('roleLabel', 'Supervisor')
        ->assertViewHas('kpis', fn (array $k) => $k['queue_count'] === 1
            && $k['in_flight_count'] === 2);
});

it('done-today reflects the ops the CURRENT operator did today, not colleagues', function (): void {
    // Housekeeper A submits one; Housekeeper B submits one.
    $housekeeperB = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);

    $insA = app(OpenRoomInspection::class)->handle($this->r101);
    $insA->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($insA, $this->housekeeper);

    $insB = app(OpenRoomInspection::class)->handle($this->r102);
    $insB->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($insB, $housekeeperB);

    // Housekeeper A sees 1 done, not 2.
    Livewire::actingAs($this->housekeeper, 'web')
        ->test(Home::class)
        ->assertViewHas('kpis', fn (array $k) => $k['done_today'] === 1);
});

it('failed inspections surface as a Rejected card visible to every housekeeping role', function (): void {
    $rejected = app(OpenRoomInspection::class)->handle($this->r101);
    $rejected->forceFill(['status' => RoomInspectionStatus::Failed->value])->save();

    foreach ([$this->housekeeper, $this->porter, $this->supervisor] as $op) {
        Livewire::actingAs($op, 'web')
            ->test(Home::class)
            ->assertViewHas('kpis', fn (array $k) => $k['failed_count'] === 1)
            ->assertViewHas('failed', fn ($collection) => $collection->count() === 1);
    }
});

it('manager still sees the standard home dashboard, not the housekeeping one', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewIs('livewire.dashboard.home');
});
