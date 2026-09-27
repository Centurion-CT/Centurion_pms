<?php

declare(strict_types=1);

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

/**
 * /api/mobile/v1/inspections
 *   - index?filter=queue → role-filtered list (Pending / CleaningDone /
 *     PorterVerified per role, all-open for manager)
 *   - show/{id} → auto-syncs the checklist bundle then returns full items
 *   - submit → applies ticks + damage, runs SubmitHousekeeperCleaning,
 *     Idempotency-Key is REQUIRED; retry with same key returns cached
 *     response (Idempotent-Replay: 1 header) without re-running the action
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'mobile-ins']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    $checklist = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard',
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
});

afterEach(fn () => Tenant::forgetCurrent());

function bearerFor(Operator $op): array
{
    $device = MobileDevice::query()->create([
        'tenant_id' => $op->tenant_id,
        'operator_id' => $op->id,
        'platform' => 'ios',
        'device_name' => 'T',
        'app_version' => '1.0.0',
        'paired_at' => now(),
        'last_seen_at' => now(),
    ]);
    $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function mobileUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').$path;
}

it('housekeeper queue returns only Pending inspections', function (): void {
    // Two inspections — one Pending (housekeeper's queue), one advanced
    // to CleaningDone (should NOT appear for the housekeeper).
    $pending = app(OpenRoomInspection::class)->handle($this->room);

    $r2 = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
    $done = app(OpenRoomInspection::class)->handle($r2);
    $done->forceFill(['status' => RoomInspectionStatus::CleaningDone->value])->save();

    $response = $this->getJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections?filter=queue'),
        bearerFor($this->housekeeper),
    );

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$pending->id]);
});

it('porter queue returns only CleaningDone inspections', function (): void {
    $pending = app(OpenRoomInspection::class)->handle($this->room);
    $done = app(OpenRoomInspection::class)->handle(
        Room::factory()->for($this->tenant)->for($this->property)->create([
            'room_category_id' => $this->category->id,
            'status' => RoomStatus::Active->value,
        ]),
    );
    $done->forceFill(['status' => RoomInspectionStatus::CleaningDone->value])->save();

    $response = $this->getJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections?filter=queue'),
        bearerFor($this->porter),
    );

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$done->id]);
});

it('show auto-populates items on view for an inspection that opened before its bundle was attached', function (): void {
    // Simulate the missed-bundle case — create an inspection with 0 items
    // then delete the sync stamp so the checklist has no items.
    $ins = app(OpenRoomInspection::class)->handle($this->room);
    $ins->items()->delete();
    expect($ins->fresh()->items->count())->toBe(0);

    $response = $this->getJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id),
        bearerFor($this->housekeeper),
    );

    $response->assertOk();
    // Auto-sync fires — items now materialised.
    expect($ins->fresh()->items->count())->toBe(2);
    expect(collect($response->json('data.items'))->pluck('label')->sort()->values()->all())
        ->toBe(['Bed made', 'Towels x2']);
});

it('submit applies ticks + damage notes then advances the inspection to CleaningDone', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->room);
    $items = $ins->items()->orderBy('id')->get();

    $response = $this->postJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/submit'),
        [
            'items' => [
                ['id' => $items[0]->id, 'checked' => true],
                ['id' => $items[1]->id, 'checked' => true, 'damage_note' => 'Small stain on towel'],
            ],
            'summary_note' => 'All good.',
        ],
        array_merge(bearerFor($this->housekeeper), ['Idempotency-Key' => 'submit-attempt-1-abc']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    $fresh = $ins->fresh();
    expect($fresh->status)->toBe(RoomInspectionStatus::CleaningDone);
    expect($fresh->items()->where('damage_note', 'Small stain on towel')->exists())->toBeTrue();
});

it('submit without Idempotency-Key returns 400', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->room);

    $this->postJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/submit'),
        ['items' => []],
        bearerFor($this->housekeeper),   // no Idempotency-Key
    )->assertStatus(400)->assertJson(['error' => 'idempotency_key_required']);
});

it('porter can verify a CleaningDone inspection through the API', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->room);
    $ins->items()->update(['checked' => true]);
    app(\App\Actions\Housekeeping\SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);

    $response = $this->postJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/verify'),
        ['accept' => true],
        array_merge(bearerFor($this->porter), ['Idempotency-Key' => 'verify-1-abc']),
    );

    $response->assertOk()->assertJson(['status' => 'ok']);
    expect($ins->fresh()->status)->toBe(RoomInspectionStatus::PorterVerified);
});

it('porter can reject with a summary_note; inspection flips to Failed', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->room);
    $ins->items()->update(['checked' => true]);
    app(\App\Actions\Housekeeping\SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);

    $response = $this->postJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/verify'),
        ['accept' => false, 'summary_note' => 'Bathroom still wet — redo.'],
        array_merge(bearerFor($this->porter), ['Idempotency-Key' => 'verify-rej-abc']),
    );

    $response->assertOk();
    $fresh = $ins->fresh();
    expect($fresh->status)->toBe(RoomInspectionStatus::Failed);
    expect($fresh->summary_note)->toBe('Bathroom still wet — redo.');
});

it('supervisor sign-off flips the inspection to Passed', function (): void {
    $supervisor = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Supervisor]);

    $ins = app(OpenRoomInspection::class)->handle($this->room);
    $ins->items()->update(['checked' => true]);
    app(\App\Actions\Housekeeping\SubmitHousekeeperCleaning::class)->handle($ins, $this->housekeeper);
    app(\App\Actions\Housekeeping\VerifyPorterInspection::class)->handle($ins->fresh(), $this->porter);

    $response = $this->postJson(
        mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/sign-off'),
        ['accept' => true],
        array_merge(bearerFor($supervisor), ['Idempotency-Key' => 'sign-1-abc']),
    );

    $response->assertOk();
    expect($ins->fresh()->status)->toBe(RoomInspectionStatus::Passed);
});

it('room history returns the last 20 inspections newest-first', function (): void {
    // Three inspections on the same room, opened in sequence.
    $ins1 = app(OpenRoomInspection::class)->handle($this->room);
    $ins1->forceFill(['status' => RoomInspectionStatus::Passed->value])->save();
    $ins2 = app(OpenRoomInspection::class)->handle($this->room);
    $ins2->forceFill(['status' => RoomInspectionStatus::Failed->value])->save();
    $ins3 = app(OpenRoomInspection::class)->handle($this->room);

    $response = $this->getJson(
        mobileUrl($this->tenant, '/api/mobile/v1/rooms/'.$this->room->id.'/history'),
        bearerFor($this->housekeeper),
    );

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$ins3->id, $ins2->id, $ins1->id]);
});

it('replaying a submit with the same Idempotency-Key returns the cached response', function (): void {
    $ins = app(OpenRoomInspection::class)->handle($this->room);
    $items = $ins->items()->orderBy('id')->get();

    $headers = array_merge(bearerFor($this->housekeeper), ['Idempotency-Key' => 'replay-key-xyz']);
    $body = [
        'items' => [
            ['id' => $items[0]->id, 'checked' => true],
            ['id' => $items[1]->id, 'checked' => true],
        ],
    ];

    $first = $this->postJson(mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/submit'), $body, $headers);
    $first->assertOk();
    $firstBody = $first->getContent();

    // Second call replays — same status + body, plus Idempotent-Replay
    // header so the client can tell it wasn't a fresh execution.
    $second = $this->postJson(mobileUrl($this->tenant, '/api/mobile/v1/inspections/'.$ins->id.'/submit'), $body, $headers);
    $second->assertOk();
    expect($second->headers->get('Idempotent-Replay'))->toBe('1');
    expect($second->getContent())->toBe($firstBody);

    // Inspection was NOT advanced twice — SubmitHousekeeperCleaning
    // would 422 on a second submit of an already-Done inspection, so
    // the fact that we see 200 both times proves the cache short-
    // circuited the action.
    expect($ins->fresh()->status)->toBe(RoomInspectionStatus::CleaningDone);
});
