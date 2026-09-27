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
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Dashboard "Rooms needing attention" widget — replaced the mini
 * heatmap. Shows every open inspection with X/3 progress and
 * flags Failed inspections separately so the manager sees what's
 * still blocking check-ins.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'attention-widget']);
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
    $this->r103 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'room_number' => '103',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $checklist = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_category_id' => $this->category->id,
        'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id,
        'housekeeping_checklist_id' => $checklist->id,
        'label' => 'Bed made', 'sort_order' => 0,
    ]);

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->porter = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('reports 0/3 for a Pending inspection', function (): void {
    app(OpenRoomInspection::class)->handle($this->r101);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewHas('attentionRows', function ($rows) {
            $row = $rows->first();

            return $rows->count() === 1
                && $row['signed'] === 0
                && $row['total'] === 3
                && $row['waiting_on'] === 'housekeeper'
                && $row['is_failed'] === false;
        });
});

it('reports 1/3 once the housekeeper submits', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r101);
    $ins->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewHas('attentionRows', function ($rows) {
            return $rows->first()['signed'] === 1
                && $rows->first()['waiting_on'] === 'porter';
        });
});

it('reports 2/3 once the porter verifies', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r101);
    $ins->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($ins->refresh(), $this->porter);

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewHas('attentionRows', function ($rows) {
            return $rows->first()['signed'] === 2
                && $rows->first()['waiting_on'] === 'supervisor';
        });
});

it('excludes Passed inspections from the queue', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r101);
    $ins->forceFill(['status' => RoomInspectionStatus::Passed])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewHas('attentionRows', fn ($rows) => $rows->isEmpty());
});

it('surfaces Failed inspections with the is_failed flag set', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r101);
    $ins->forceFill(['status' => RoomInspectionStatus::Failed])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewHas('attentionRows', function ($rows) {
            $row = $rows->first();

            return $rows->count() === 1
                && $row['is_failed'] === true
                && $row['signed'] === 0;
        });
});

it('sorts Failed → PorterVerified → CleaningDone → Pending', function (): void {
    // One room per status.
    $pending = app(OpenRoomInspection::class)->handle($this->r101);

    $cleaningDone = app(OpenRoomInspection::class)->handle($this->r102);
    $cleaningDone->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($cleaningDone, $this->housekeeper);

    $failed = app(OpenRoomInspection::class)->handle($this->r103);
    $failed->forceFill(['status' => RoomInspectionStatus::Failed])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertViewHas('attentionRows', function ($rows) use ($failed, $cleaningDone, $pending) {
            $order = $rows->pluck('inspection.id')->all();

            return $order === [$failed->id, $cleaningDone->id, $pending->id];
        });
});

it('renders the empty-state copy when nothing is open', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->assertSee('All rooms are cleared.');
});

it('renders a progress hint including the "X/Y" text for a mid-flight inspection', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r101);
    $ins->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(Home::class)
        ->html();

    // The row-level progress span for this inspection should render 1/3.
    expect($html)->toContain('data-testid="attention-progress-'.$ins->id.'"');
    // Widget as a whole is present.
    expect($html)->toContain('data-testid="widget-attention"');
});
