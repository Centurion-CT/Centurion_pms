<?php

declare(strict_types=1);

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SignOffSupervisorInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Housekeeping\Inspections as HousekeepingInspections;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * /dashboard/housekeeping/inspections — the dedicated sidebar page
 * for the sign-off workflow.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'inspections-page']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->r101 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $this->r102 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $checklist = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard cleaning',
        'active' => true,
    ]);
    $checklist->categories()->attach($this->category->id);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id,
        'housekeeping_checklist_id' => $checklist->id,
        'label' => 'Bed made', 'sort_order' => 0,
    ]);

    $this->manager     = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->porter      = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter]);
    $this->supervisor  = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Supervisor]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('splits open inspections into the three lanes', function (): void {
    // Room 101 → Pending. Room 102 → CleaningDone (via housekeeper submit).
    app(OpenRoomInspection::class)->handle($this->r101);
    $second = app(OpenRoomInspection::class)->handle($this->r102);
    $second->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($second, $this->housekeeper);

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingInspections::class)
        ->assertViewHas('lanes', function ($lanes) {
            return $lanes['pending']->count() === 1
                && $lanes['cleaning_done']->count() === 1
                && $lanes['porter_verified']->count() === 0;
        });
});

it('highlights the lane matching the operator role as my queue', function (): void {
    Livewire::actingAs($this->housekeeper, 'web')
        ->test(HousekeepingInspections::class)
        ->assertViewHas('myLane', 'pending');

    Livewire::actingAs($this->porter, 'web')
        ->test(HousekeepingInspections::class)
        ->assertViewHas('myLane', 'cleaning_done');

    Livewire::actingAs($this->supervisor, 'web')
        ->test(HousekeepingInspections::class)
        ->assertViewHas('myLane', 'porter_verified');

    // Manager sees the same board without a personal queue.
    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingInspections::class)
        ->assertViewHas('myLane', null);
});

it('renders empty state when nothing is open', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingInspections::class)
        ->assertSee('All rooms are cleared.')
        ->assertViewHas('lanes', fn ($lanes) => $lanes['pending']->isEmpty()
            && $lanes['cleaning_done']->isEmpty()
            && $lanes['porter_verified']->isEmpty());
});

it('surfaces recent Passed / Failed inspections in the history tail', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r101);
    $ins->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($ins->refresh(), $this->porter);
    app(SignOffSupervisorInspection::class)->handle($ins->refresh(), $this->supervisor);

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingInspections::class)
        ->assertViewHas('recent', fn ($rows) => $rows->count() === 1
            && $rows->first()->id === $ins->id
            && $rows->first()->status->value === 'passed');
});
