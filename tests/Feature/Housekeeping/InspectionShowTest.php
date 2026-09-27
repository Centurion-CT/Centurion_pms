<?php

declare(strict_types=1);

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Housekeeping\InspectionShow;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * InspectionShow Livewire component — the per-inspection screen
 * where housekeeper / porter / supervisor act. Uses the underlying
 * actions covered by the workflow suite; these tests exercise the
 * role-based UI gating + toggle plumbing.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'inspection-ui']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
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
    foreach (['Bed made', 'Towels x2'] as $i => $label) {
        HousekeepingChecklistItem::query()->create([
            'tenant_id' => $this->tenant->id,
            'housekeeping_checklist_id' => $checklist->id,
            'label' => $label,
            'sort_order' => $i,
        ]);
    }

    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->porter = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter]);
    $this->supervisor = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Supervisor]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('housekeeper can tick items + submit; porter+supervisor buttons stay hidden', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $items = $inspection->items()->orderBy('id')->get();

    Livewire::actingAs($this->housekeeper, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection])
        ->assertViewHas('canTick', true)
        ->assertViewHas('canSubmit', true)
        ->assertViewHas('canPorter', false)
        ->assertViewHas('canSupervisor', false)
        ->call('toggleItem', $items[0]->id)
        ->call('toggleItem', $items[1]->id)
        ->call('submitCleaning')
        // Submit fires the global notify modal instead of an inline flash.
        ->assertDispatched('notify', function (string $name, array $params) {
            return ($params['kind'] ?? null) === 'ok'
                && ($params['title'] ?? null) === 'Cleaning submitted';
        });

    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::CleaningDone);
    expect($inspection->readiness_score)->toBe(100);
});

it('porter sees the Verify button once cleaning is submitted', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $inspection->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);

    Livewire::actingAs($this->porter, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection->refresh()])
        ->assertViewHas('canTick', false)     // checklist is locked
        ->assertViewHas('canPorter', true)
        ->assertViewHas('canSupervisor', false)
        ->call('porterVerify', true);

    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::PorterVerified);
});

it('supervisor signs off and unlocks check-in state', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $inspection->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($inspection->refresh(), $this->porter);

    Livewire::actingAs($this->supervisor, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection->refresh()])
        ->assertViewHas('canSupervisor', true)
        ->call('supervisorSignOff', true);

    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::Passed);
});

it('a porter cannot tick items — checklist is locked at that stage', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $inspection->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);

    $anItem = $inspection->items()->first();
    Livewire::actingAs($this->porter, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection->refresh()])
        ->call('toggleItem', $anItem->id);   // no-op with a flash error

    // Item stays checked (locked from housekeeper's submit).
    expect($anItem->fresh()->checked)->toBeTrue();
});

it('a rejection sets the summary_note from the operator input', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $inspection->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);

    Livewire::actingAs($this->porter, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection->refresh()])
        ->set('summaryNote', 'Bathroom still wet — redo.')
        ->call('porterVerify', false);

    $fresh = $inspection->refresh();
    expect($fresh->status)->toBe(RoomInspectionStatus::Failed);
    expect($fresh->summary_note)->toBe('Bathroom still wet — redo.');
});
