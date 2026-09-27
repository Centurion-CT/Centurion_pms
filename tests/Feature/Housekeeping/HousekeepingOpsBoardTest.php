<?php

declare(strict_types=1);

use App\Actions\Housekeeping\MarkDamageResolved;
use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SignOffSupervisorInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Housekeeping\Index as HousekeepingIndex;
use App\Livewire\Dashboard\Housekeeping\RoomHistory;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\RoomInspectionItem;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Rebuilt housekeeping page — per-room ops board, KPI trends, needs-
 * replacement list, and the per-room drill-in.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'hk-ops']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->r306 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '306',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $this->r307 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'room_number' => '307',
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $checklist = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard cleaning',
        'active' => true,
    ]);
    $checklist->categories()->attach($this->category->id);
    foreach (['Bed made', 'Towels x2', 'Bathroom clean'] as $i => $label) {
        HousekeepingChecklistItem::query()->create([
            'tenant_id' => $this->tenant->id,
            'housekeeping_checklist_id' => $checklist->id,
            'label' => $label, 'sort_order' => $i,
        ]);
    }

    $this->manager     = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Ada Manager']);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner, 'name' => 'Chi Cleaner']);
    $this->porter      = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter, 'name' => 'Ola Porter']);
    $this->supervisor  = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Supervisor, 'name' => 'Ngozi Super']);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Damage resolution action ─────────────────────────────────── */

it('MarkDamageResolved flips the resolved_at + causer', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r306);
    $item = $ins->items()->first();
    $item->forceFill(['damage_note' => 'Torn towel'])->save();

    app(MarkDamageResolved::class)->handle($item, $this->manager);

    $fresh = $item->fresh();
    expect($fresh->resolved_at)->not->toBeNull();
    expect($fresh->resolved_by_operator_id)->toBe($this->manager->id);
    expect($fresh->isOpenDamage())->toBeFalse();
});

it('MarkDamageResolved rejects operators outside Manager / Supervisor', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r306);
    $item = $ins->items()->first();
    $item->forceFill(['damage_note' => 'Broken lamp'])->save();

    expect(fn () => app(MarkDamageResolved::class)->handle($item, $this->housekeeper))
        ->toThrow(RuntimeException::class);
});

/* ─── Index page ───────────────────────────────────────────────── */

it('index exposes per-room card data + kpis + needs-replacement list', function (): void {
    // r306 gets a full inspection: cleaner + porter + supervisor sign,
    // one damage note left open.
    $ins = app(OpenRoomInspection::class)->handle($this->r306);
    $ins->items()->update(['checked' => true]);
    $ins->items()->first()->forceFill(['damage_note' => 'Torn towel'])->save();
    app(SubmitHousekeeperCleaning::class)->handle($ins->refresh(), $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($ins->refresh(), $this->porter);
    app(SignOffSupervisorInspection::class)->handle($ins->refresh(), $this->supervisor);

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->assertViewHas('kpis', function ($k) {
            return $k['issues_7d'] === 1
                && $k['needs_replacement'] === 1;
        })
        ->assertViewHas('roomsBoard', function ($rows) {
            $r306 = $rows->firstWhere('room.room_number', '306');
            $r307 = $rows->firstWhere('room.room_number', '307');

            return $r306->housekeeper === 'Chi Cleaner'
                && $r306->porter === 'Ola Porter'
                && $r306->supervisor === 'Ngozi Super'
                && $r306->openDamage === 1
                && $r307->inspection === null;
        })
        ->assertViewHas('needsReplacement', fn ($list) => $list->count() === 1);
});

it('markDamageResolved on the page removes the item from the queue', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r306);
    $item = $ins->items()->first();
    $item->forceFill(['damage_note' => 'Cracked bedside table'])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->call('markDamageResolved', $item->id)
        ->assertViewHas('needsReplacement', fn ($list) => $list->isEmpty())
        ->assertViewHas('kpis', fn ($k) => $k['needs_replacement'] === 0);
});

it('templates collapse defaults closed and toggles on click', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(HousekeepingIndex::class)
        ->assertSet('templatesOpen', false)
        ->call('toggleTemplates')
        ->assertSet('templatesOpen', true)
        ->call('toggleTemplates')
        ->assertSet('templatesOpen', false);
});

/* ─── Room history drill-in ────────────────────────────────────── */

it('room history shows every past inspection with per-signer + report body', function (): void {
    // Two inspections on r306 — one Passed with a note, one Pending.
    $first = app(OpenRoomInspection::class)->handle($this->r306);
    $first->items()->update(['checked' => true]);
    // Add a damage note on the first item + a good note on all others.
    $first->items()->first()->forceFill(['damage_note' => 'Bulb blown'])->save();
    app(SubmitHousekeeperCleaning::class)->handle($first->refresh(), $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($first->refresh(), $this->porter);
    app(SignOffSupervisorInspection::class)->handle($first->refresh(), $this->supervisor);
    // Second inspection stays Pending.
    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $second = app(OpenRoomInspection::class)->handle($this->r306);
    Carbon::setTestNow();

    Livewire::actingAs($this->manager, 'web')
        ->test(RoomHistory::class, ['room' => $this->r306])
        ->assertViewHas('inspections', function ($inspections) use ($first, $second) {
            $ids = $inspections->pluck('id')->all();
            // Newest first — Pending → Passed.

            return $ids === [$second->id, $first->id];
        })
        ->assertViewHas('summary', function ($s) {
            return $s['total'] === 2
                && $s['issues_7d'] === 1
                && $s['open_damage'] === 1;
        });
});

it('room history renders the operator name against each signer', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->r306);
    $ins->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($ins->refresh(), $this->housekeeper);

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(RoomHistory::class, ['room' => $this->r306])
        ->html();

    // The cleaner's name appears against the Cleaner signer chip.
    expect($html)->toContain('Chi Cleaner');
});
