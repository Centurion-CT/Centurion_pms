<?php

declare(strict_types=1);

use App\Actions\Housekeeping\OpenRoomInspection;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Enums\StockRequestStatus;
use App\Models\Booking;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\StockRequest;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Staff app API (/api/mobile/v1/staff/*) for housekeepers and porters.
 * Contract: docs/staff-app/PLAN.md.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-23 09:00:00', 'UTC')); // 10:00 in Lagos

    $this->tenant = Tenant::factory()->create(['slug' => 'staff-app']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe King', 'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
    ]);
    $checklist = HousekeepingChecklist::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Turnover', 'active' => true,
    ]);
    $checklist->categories()->attach($this->category->id);
    foreach (['Toilet cleaned', '2 fresh towels', 'Fresh sheets and pillowcases', 'Floor mopped', '2 water bottles'] as $i => $label) {
        HousekeepingChecklistItem::query()->create([
            'tenant_id' => $this->tenant->id, 'housekeeping_checklist_id' => $checklist->id,
            'label' => $label, 'sort_order' => $i,
        ]);
    }

    $this->cleaner = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner, 'name' => 'Blessing Okafor']);
    $this->otherCleaner = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner, 'name' => 'Chika Obi']);
    $this->porter = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter, 'name' => 'Musa Bello']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function staffRoom(object $t, string $number): Room
{
    return Room::factory()->for($t->tenant)->for($t->property)->create([
        'room_category_id' => $t->category->id,
        'room_number' => $number,
        'status' => RoomStatus::Active->value,
        'housekeeping_status' => HousekeepingStatus::Dirty->value,
    ]);
}

function staffHeaders(Operator $op, ?string $key = null): array
{
    // Tests reuse one app instance; drop the user Sanctum resolved for
    // the previous request so this token is read fresh.
    app('auth')->forgetGuards();

    $device = MobileDevice::query()->create([
        'tenant_id' => $op->tenant_id, 'operator_id' => $op->id, 'platform' => 'android',
        'device_name' => 'Pixel', 'app_version' => '2.0.0', 'paired_at' => now(), 'last_seen_at' => now(),
    ]);
    $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

    return array_filter([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Idempotency-Key' => $key ?? (string) Str::ulid(),
    ]);
}

function staffUrl(Tenant $tenant, string $path): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/staff/'.ltrim($path, '/');
}

it('refuses roles other than housekeeper and porter', function (): void {
    $this->getJson(staffUrl($this->tenant, 'session'), staffHeaders($this->frontdesk))
        ->assertStatus(403)
        ->assertJsonPath('error', 'staff_only');
});

it('returns the session greeting data', function (): void {
    $this->getJson(staffUrl($this->tenant, 'session'), staffHeaders($this->cleaner))
        ->assertOk()
        ->assertJsonPath('data.operator.first_name', 'Blessing')
        ->assertJsonPath('data.operator.initials', 'BO')
        ->assertJsonPath('data.operator.role', 'house_cleaner')
        ->assertJsonPath('data.shift_label', 'Morning shift')
        ->assertJsonPath('data.date_label', 'Friday 23 Oct');
});

it('lists my rooms with the arriving room first and someone else\'s room hidden', function (): void {
    $old = app(OpenRoomInspection::class)->handle(staffRoom($this, '112'));
    $old->forceFill(['created_at' => now()->subHours(3)])->save();
    $arriving = app(OpenRoomInspection::class)->handle($room204 = staffRoom($this, '204'));
    $taken = app(OpenRoomInspection::class)->handle(staffRoom($this, '305'));
    $taken->forceFill(['assigned_operator_id' => $this->otherCleaner->id])->save();

    Booking::factory()->for($this->tenant)->for($room204)->create([
        'booking_status' => BookingStatus::Confirmed->value,
        'checkin_date' => Carbon::today(), 'checkout_date' => Carbon::today()->addDays(2),
    ]);

    $res = $this->getJson(staffUrl($this->tenant, 'rooms'), staffHeaders($this->cleaner))->assertOk();

    expect(collect($res->json('data.rooms'))->pluck('number')->all())->toBe(['204', '112'])
        ->and($res->json('data.rooms.0.do_first'))->toBeTrue()
        ->and($res->json('data.rooms.0.arriving_today'))->toBeTrue()
        ->and($res->json('data.rooms.0.state'))->toBe('to_clean')
        ->and($res->json('data.rooms.0.category'))->toBe('Deluxe King')
        ->and($res->json('data.rooms.0.progress'))->toBe(['done' => 0, 'total' => 5])
        ->and($res->json('data.rooms.1.do_first'))->toBeFalse()
        ->and($res->json('data.counts.to_clean'))->toBe(2);
});

it('groups the checklist with pictures', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '204'));

    $res = $this->getJson(staffUrl($this->tenant, "rooms/{$i->id}"), staffHeaders($this->cleaner))->assertOk();

    expect(collect($res->json('data.groups'))->pluck('key')->all())->toBe(['bathroom', 'bedroom', 'extras'])
        ->and(collect($res->json('data.groups.0.items'))->pluck('icon')->all())->toBe(['toilet', 'towel'])
        ->and(collect($res->json('data.groups.1.items'))->pluck('icon')->all())->toBe(['sheets', 'floor'])
        ->and($res->json('data.groups.2.items.0.icon'))->toBe('water');
});

it('claims a room on the first tick and blocks another cleaner', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '204'));
    $item = $i->items()->orderBy('sort_order')->first();

    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/items/{$item->id}"), ['checked' => true], staffHeaders($this->cleaner))
        ->assertOk()
        ->assertJsonPath('item.checked', true);

    $i->refresh();
    expect($i->assigned_operator_id)->toBe($this->cleaner->id)
        ->and($i->started_at)->not->toBeNull()
        ->and($item->refresh()->checked)->toBeTrue();

    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/start"), [], staffHeaders($this->otherCleaner))
        ->assertStatus(409)
        ->assertJsonPath('error', 'wrong_state');

    // And it drops off the other cleaner's list.
    $other = $this->getJson(staffUrl($this->tenant, 'rooms'), staffHeaders($this->otherCleaner))->assertOk();
    expect($other->json('data.rooms'))->toBe([]);
});

it('finishes a room and hands it to the porter', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '210'));
    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/start"), [], staffHeaders($this->cleaner))->assertOk();
    Carbon::setTestNow(now()->addMinutes(38));
    $i->items()->update(['checked' => true]);

    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/finish"), ['note' => 'Bedside lamp bulb is out.'], staffHeaders($this->cleaner))
        ->assertOk()
        ->assertJsonPath('room.state', 'with_porter');

    $mine = $this->getJson(staffUrl($this->tenant, 'rooms'), staffHeaders($this->cleaner))->assertOk();
    expect($mine->json('data.counts.with_porter'))->toBe(1)
        ->and($mine->json('data.rooms.0.state'))->toBe('with_porter');

    $checks = $this->getJson(staffUrl($this->tenant, 'checks'), staffHeaders($this->porter))->assertOk();
    expect($checks->json('data.rooms.0.number'))->toBe('210')
        ->and($checks->json('data.rooms.0.cleaned_by.first_name'))->toBe('Blessing')
        ->and($checks->json('data.rooms.0.minutes'))->toBe(38)
        ->and($checks->json('data.rooms.0.score'))->toBe(100)
        ->and($checks->json('data.rooms.0.note'))->toBe('Bedside lamp bulb is out.');

    $detail = $this->getJson(staffUrl($this->tenant, "checks/{$i->id}"), staffHeaders($this->porter))->assertOk();
    expect($detail->json('data.items'))->toHaveCount(5);
});

it('lets the porter mark a room ready', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '210'));
    $i->forceFill(['status' => RoomInspectionStatus::CleaningDone, 'housekeeper_by_operator_id' => $this->cleaner->id, 'housekeeper_at' => now()])->save();

    $this->postJson(staffUrl($this->tenant, "checks/{$i->id}/ready"), [], staffHeaders($this->porter))->assertOk();
    expect($i->refresh()->status)->toBe(RoomInspectionStatus::PorterVerified);

    $this->postJson(staffUrl($this->tenant, "checks/{$i->id}/ready"), [], staffHeaders($this->porter))
        ->assertStatus(409);

    $mine = $this->getJson(staffUrl($this->tenant, 'rooms'), staffHeaders($this->cleaner))->assertOk();
    expect($mine->json('data.rooms.0.state'))->toBe('ready')
        ->and($mine->json('data.progress'))->toBe(['ready' => 1, 'total' => 1]);
});

it('sends a room back to the same cleaner and clears only the matching ticks', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '118'));
    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/start"), [], staffHeaders($this->cleaner))->assertOk();
    $i->items()->update(['checked' => true]);
    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/finish"), [], staffHeaders($this->cleaner))->assertOk();

    $this->postJson(staffUrl($this->tenant, "checks/{$i->id}/send-back"), ['reasons' => ['towels'], 'note' => 'Only one towel'], staffHeaders($this->porter))
        ->assertOk();

    $i->refresh();
    expect($i->status)->toBe(RoomInspectionStatus::Pending)
        ->and($i->assigned_operator_id)->toBe($this->cleaner->id)
        ->and($i->send_back_count)->toBe(1)
        ->and($i->items()->where('checked', false)->pluck('label')->all())->toBe(['2 fresh towels']);

    $mine = $this->getJson(staffUrl($this->tenant, 'rooms'), staffHeaders($this->cleaner))->assertOk();
    expect($mine->json('data.rooms.0.state'))->toBe('sent_back')
        ->and($mine->json('data.rooms.0.sent_back.by'))->toBe('Musa')
        ->and($mine->json('data.rooms.0.sent_back.reasons_label'))->toBe('towels')
        ->and($mine->json('data.rooms.0.sent_back.note'))->toBe('Only one towel');

    // Re-submitting goes back to the porter.
    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/finish"), [], staffHeaders($this->cleaner))
        ->assertOk()
        ->assertJsonPath('room.state', 'with_porter');
});

it('rejects unknown send-back reasons', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '118'));
    $i->forceFill(['status' => RoomInspectionStatus::CleaningDone])->save();

    $this->postJson(staffUrl($this->tenant, "checks/{$i->id}/send-back"), ['reasons' => ['vibes']], staffHeaders($this->porter))
        ->assertStatus(422);
});

it('keeps housekeeper and porter endpoints apart', function (): void {
    $this->getJson(staffUrl($this->tenant, 'checks'), staffHeaders($this->cleaner))->assertStatus(403);
    $this->getJson(staffUrl($this->tenant, 'rooms'), staffHeaders($this->porter))->assertStatus(403);
});

it('asks the housekeeping store for supplies', function (): void {
    $store = Store::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Central store', 'slug' => 'central', 'active' => true,
    ]);
    Outlet::query()->create([
        'tenant_id' => $this->tenant->id, 'property_id' => $this->property->id,
        'name' => 'Housekeeping', 'type' => OutletType::Housekeeping, 'active' => true,
    ]);
    $roll = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'name' => 'Toilet roll',
        'price' => 0, 'cost' => 300, 'track_stock' => true, 'quantity_on_hand' => 100, 'active' => true,
    ]);
    $soap = Product::query()->create([
        'tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'name' => 'Soap bars',
        'price' => 0, 'cost' => 150, 'track_stock' => true, 'quantity_on_hand' => 100, 'active' => true,
    ]);

    $list = $this->getJson(staffUrl($this->tenant, 'supplies'), staffHeaders($this->cleaner))->assertOk();
    expect(collect($list->json('data.products'))->pluck('name')->all())->toBe(['Soap bars', 'Toilet roll'])
        ->and($list->json('data.outlet.name'))->toBe('Housekeeping');

    $res = $this->postJson(staffUrl($this->tenant, 'supplies'), [
        'lines' => [['product_id' => $roll->id, 'qty' => 12], ['product_id' => $soap->id, 'qty' => 20]],
    ], staffHeaders($this->cleaner))->assertOk();

    expect(StockRequest::query()->where('requested_by_operator_id', $this->cleaner->id)->count())->toBe(2)
        ->and(StockRequest::query()->first()->status)->toBe(StockRequestStatus::Pending)
        ->and(collect($res->json('requests'))->pluck('status')->unique()->all())->toBe(['waiting'])
        ->and(collect($res->json('requests'))->pluck('summary')->sort()->values()->all())->toBe(['Soap bars, 20', 'Toilet roll, 12']);
});

it('says when there is no housekeeping store', function (): void {
    $this->getJson(staffUrl($this->tenant, 'supplies'), staffHeaders($this->porter))
        ->assertOk()
        ->assertJsonPath('data.outlet', null);

    $this->postJson(staffUrl($this->tenant, 'supplies'), ['lines' => [['product_id' => 1, 'qty' => 1]]], staffHeaders($this->porter))
        ->assertStatus(422)
        ->assertJsonPath('error', 'no_store');
});

it('reports my week', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '204'));
    $i->forceFill([
        'status' => RoomInspectionStatus::CleaningDone, 'housekeeper_by_operator_id' => $this->cleaner->id,
        'started_at' => now()->subMinutes(40), 'housekeeper_at' => now(),
    ])->save();

    $this->getJson(staffUrl($this->tenant, 'me'), staffHeaders($this->cleaner))
        ->assertOk()
        ->assertJsonPath('data.week.rooms_cleaned', 1)
        ->assertJsonPath('data.week.avg_minutes', 40)
        ->assertJsonPath('data.week.sent_back', 0);
});

it('needs an idempotency key on writes', function (): void {
    $i = app(OpenRoomInspection::class)->handle(staffRoom($this, '204'));
    $headers = staffHeaders($this->cleaner);
    unset($headers['Idempotency-Key']);

    $this->postJson(staffUrl($this->tenant, "rooms/{$i->id}/start"), [], $headers)->assertStatus(400);
    expect(RoomInspection::query()->find($i->id)->started_at)->toBeNull();
});
