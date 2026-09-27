<?php

declare(strict_types=1);

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Housekeeping\Index;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'bundle-test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'activated_at' => now(),
    ]);

    $this->standard = RoomCategory::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Standard',
        'type' => 'room',
        'base_rate' => 15000,
        'capacity_adults' => 2,
        'capacity_children' => 0,
        'min_nights' => 1,
        'weekly_discount_percent' => 0,
        'monthly_discount_percent' => 0,
        'deposit_type' => 'none',
        'deposit_value' => 0,
        'booking_type' => 'short_let',
        'status' => 'active',
    ]);
    $this->suite = RoomCategory::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'name' => 'Suite',
        'type' => 'room',
        'base_rate' => 40000,
        'capacity_adults' => 2,
        'capacity_children' => 0,
        'min_nights' => 1,
        'weekly_discount_percent' => 0,
        'monthly_discount_percent' => 0,
        'deposit_type' => 'none',
        'deposit_value' => 0,
        'booking_type' => 'short_let',
        'status' => 'active',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── Bundle CRUD ─────────────────────────────────────────────── */

it('manager can create a bundle from the housekeeping page', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('newBundleName', 'Standard cleaning')
        ->call('createBundle')
        ->assertSet('newBundleName', '');

    $bundle = HousekeepingChecklist::query()->where('name', 'Standard cleaning')->first();
    expect($bundle)->not->toBeNull()
        ->and((bool) $bundle->active)->toBeTrue();
});

it('manager can rename + delete a bundle', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old name',
        'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('renameBundle', $bundle->id, 'New name');
    expect($bundle->fresh()->name)->toBe('New name');

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('deleteBundle', $bundle->id);
    expect(HousekeepingChecklist::query()->find($bundle->id))->toBeNull();
});

it('non-manager cannot mutate bundles', function (): void {
    $frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'activated_at' => now(),
    ]);

    Livewire::actingAs($frontdesk, 'web')
        ->test(Index::class)
        ->set('newBundleName', 'nope')
        ->call('createBundle');

    expect(HousekeepingChecklist::query()->where('name', 'nope')->exists())->toBeFalse();
});

/* ── Attach / detach ─────────────────────────────────────────── */

it('bundle attaches + detaches from a category, syncing the pivot', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Turnover checks',
        'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('attachBundleToCategory', $bundle->id, $this->standard->id)
        ->call('attachBundleToCategory', $bundle->id, $this->suite->id);

    expect($bundle->fresh()->categories()->pluck('room_categories.id')->all())
        ->toContain($this->standard->id, $this->suite->id);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->call('detachBundleFromCategory', $bundle->id, $this->standard->id);

    expect($bundle->fresh()->categories()->pluck('room_categories.id')->all())
        ->toBe([$this->suite->id]);
});

/* ── OpenRoomInspection unions attached bundles ──────────────── */

it('snapshots items from every attached bundle, deduped by label', function (): void {
    $room = Room::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id,
        'room_number' => 101,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);

    $bundleA = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Standard cleaning', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleA->id,
        'label' => 'Make bed', 'sort_order' => 0,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleA->id,
        'label' => 'Sweep floor', 'sort_order' => 1,
    ]);

    $bundleB = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Deep clean', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleB->id,
        'label' => 'Sweep floor', 'sort_order' => 0,  // duplicate of bundleA's item
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleB->id,
        'label' => 'Steam curtains', 'sort_order' => 1,
    ]);

    // Attach both bundles to the room's category.
    $this->standard->checklists()->sync([$bundleA->id, $bundleB->id]);

    $inspection = app(OpenRoomInspection::class)->handle($room);

    // Union deduped by case-insensitive label — 3 distinct items,
    // not 4. First-seen ordering preserved (Make bed, Sweep floor,
    // Steam curtains).
    $labels = $inspection->items()->orderBy('sort_order')->pluck('label')->all();
    expect($labels)->toBe(['Make bed', 'Sweep floor', 'Steam curtains']);
});

it('opens an empty inspection when no bundles are attached', function (): void {
    $room = Room::query()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id,
        'room_number' => 102,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);

    $inspection = app(OpenRoomInspection::class)->handle($room);

    expect($inspection->items()->count())->toBe(0);
});

/* ── Item authoring on a bundle ──────────────────────────────── */

/* ── Auto-sync on attach + on view ───────────────────────────── */

it('attaching a bundle back-fills every open inspection for rooms in the category', function (): void {
    $roomA = Room::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id, 'room_number' => 201,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);
    $roomB = Room::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id, 'room_number' => 202,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);

    // Two inspections opened while the category had no bundles.
    $insA = app(\App\Actions\Housekeeping\OpenRoomInspection::class)->handle($roomA);
    $insB = app(\App\Actions\Housekeeping\OpenRoomInspection::class)->handle($roomB);
    expect($insA->items()->count())->toBe(0)
        ->and($insB->items()->count())->toBe(0);

    // Manager authors a bundle + attaches it via the housekeeping page.
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Standard cleaning', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundle->id,
        'label' => 'Make bed', 'sort_order' => 0,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundle->id,
        'label' => 'Sweep floor', 'sort_order' => 1,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Housekeeping\Index::class)
        ->call('attachBundleToCategory', $bundle->id, $this->standard->id);

    // Both empty inspections now carry the bundle's items —
    // manager didn't touch either inspection directly.
    expect($insA->fresh()->items()->orderBy('sort_order')->pluck('label')->all())
        ->toBe(['Make bed', 'Sweep floor']);
    expect($insB->fresh()->items()->orderBy('sort_order')->pluck('label')->all())
        ->toBe(['Make bed', 'Sweep floor']);
});

it('viewing an empty inspection auto-populates from currently-attached bundles', function (): void {
    $room = Room::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id, 'room_number' => 210,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);

    $inspection = app(\App\Actions\Housekeeping\OpenRoomInspection::class)->handle($room);
    expect($inspection->items()->count())->toBe(0);

    // A bundle attached AFTER the inspection was opened.
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Standard cleaning', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundle->id,
        'label' => 'Make bed', 'sort_order' => 0,
    ]);
    $this->standard->checklists()->attach($bundle->id);

    // Housekeeper just visits the inspection — no button click.
    $housekeeper = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner, 'activated_at' => now(),
    ]);
    Livewire::actingAs($housekeeper, 'web')
        ->test(\App\Livewire\Dashboard\Housekeeping\InspectionShow::class, ['inspection' => $inspection]);

    expect($inspection->fresh()->items()->pluck('label')->all())->toBe(['Make bed']);
});

it('auto-sync preserves ticks + damage notes on already-existing items', function (): void {
    $room = Room::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id, 'room_number' => 220,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);

    $bundleA = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'A', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleA->id,
        'label' => 'Make bed', 'sort_order' => 0,
    ]);
    $this->standard->checklists()->attach($bundleA->id);

    $inspection = app(\App\Actions\Housekeeping\OpenRoomInspection::class)->handle($room);
    $existing = $inspection->items()->first();
    $existing->forceFill(['checked' => true, 'damage_note' => 'stain on sheet'])->save();

    // A second bundle attached with a duplicate + a new item.
    $bundleB = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'B', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleB->id,
        'label' => 'Make bed', 'sort_order' => 0,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundleB->id,
        'label' => 'Steam curtains', 'sort_order' => 1,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Housekeeping\Index::class)
        ->call('attachBundleToCategory', $bundleB->id, $this->standard->id);

    $items = $inspection->fresh()->items()->orderBy('sort_order')->get();
    expect($items->pluck('label')->all())->toBe(['Make bed', 'Steam curtains']);
    $preserved = $items->firstWhere('label', 'Make bed');
    expect($preserved->checked)->toBeTrue()
        ->and($preserved->damage_note)->toBe('stain on sheet');
});

it('auto-sync leaves frozen inspections alone (past housekeeping stage)', function (): void {
    $room = Room::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'room_category_id' => $this->standard->id, 'room_number' => 230,
        'status' => \App\Enums\RoomStatus::Active->value,
    ]);

    // A "passed" inspection — porter + supervisor signed off long ago.
    $frozen = \App\Models\RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $room->id,
        'room_category_id' => $this->standard->id,
        'status' => \App\Enums\RoomInspectionStatus::Passed->value,
        'readiness_score' => 100,
    ]);

    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'B', 'active' => true,
    ]);
    HousekeepingChecklistItem::query()->create([
        'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $bundle->id,
        'label' => 'Make bed', 'sort_order' => 0,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Housekeeping\Index::class)
        ->call('attachBundleToCategory', $bundle->id, $this->standard->id);

    // The frozen inspection stays empty — auto-sync only touches
    // Pending / CleaningDone.
    expect($frozen->fresh()->items()->count())->toBe(0);
});

it('addChecklistItem targets the bundle now, not a category', function (): void {
    $bundle = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Bar cleanup', 'active' => true,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(Index::class)
        ->set('newItemLabels', [$bundle->id => 'Wipe counter'])
        ->call('addChecklistItem', $bundle->id);

    expect($bundle->fresh()->items()->pluck('label')->all())->toBe(['Wipe counter']);
});
