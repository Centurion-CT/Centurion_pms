<?php

declare(strict_types=1);

use App\Actions\Housekeeping\FlagRoom;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\AvailabilityBlock;
use App\Models\Operator;
use App\Models\Room;
use App\Models\RoomInspection;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

require_once __DIR__.'/HostTestHelpers.php';

/**
 * Host app phase 3: calendar (17) + rooms board (18 / 18b).
 * Ikoyi (current branch, rooms 101-105) + Abuja (room 901, same
 * tenant) + another tenant: Abuja and the other tenant never leak.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));

    $this->tenant = Tenant::factory()->create(['slug' => 'host-cal']);
    $this->tenant->makeCurrent();

    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 5, '1');
    $this->property = $ikoyi['property'];
    $this->rooms = $ikoyi['rooms'];
    $abuja = hostBranch($this->tenant, 'Abuja', 1, '9');
    $this->abuja = $abuja['property'];
    $this->abujaRoom = $abuja['rooms'][0];

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze']);
    $this->manager->forceFill(['current_property_id' => $this->property->id])->save();
    $this->frontdesk->forceFill(['current_property_id' => $this->property->id])->save();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

// ── Calendar ─────────────────────────────────────────────────────

function calSeed(object $t): array
{
    [$r1, $r2, $r3, $r4] = $t->rooms;
    $inHouse = hostBooking($r1, [
        'guest_name' => 'Amaka Bello', 'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-10-21', 'checkout_date' => '2026-10-24', 'nights' => 3,
    ]);
    hostReserveNights($inHouse);
    $overdue = hostBooking($r2, [
        'guest_name' => 'Tunde Ade', 'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-10-18', 'checkout_date' => '2026-10-20', 'nights' => 2,
    ]);
    hostReserveNights($overdue);
    $arriving = hostBooking($r3, [
        'guest_name' => 'Kemi Lawal', 'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => '2026-10-22', 'checkout_date' => '2026-10-24', 'nights' => 2,
        'total_amount' => 60_000, 'balance_due' => 20_000,
    ]);
    hostReserveNights($arriving);
    foreach (['2026-10-23' => 'Maintenance', '2026-10-24' => 'Maintenance', '2026-10-25' => 'Owner use'] as $date => $reason) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $t->tenant->id, 'room_id' => $r4->id, 'date' => $date,
            'type' => AvailabilityBlockType::Blocked->value, 'reason' => $reason,
        ]);
    }
    $abuja = hostBooking($t->abujaRoom, ['guest_name' => 'Abuja Guest', 'checkin_date' => '2026-10-22', 'checkout_date' => '2026-10-23', 'nights' => 1]);
    hostReserveNights($abuja);

    return compact('inHouse', 'overdue', 'arriving');
}

it('returns the week grid with stays, overdue overlay, blocks and occupancy for the branch', function (): void {
    $seed = calSeed($this);

    $res = $this->getJson(hostUrl($this->tenant, 'calendar?from=2026-10-19&days=7'), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.from', '2026-10-19')
        ->assertJsonPath('data.to', '2026-10-26')
        ->assertJsonPath('data.today', '2026-10-22');

    $data = $res->json('data');
    expect(collect($data['rooms'])->pluck('number')->all())->toBe(['101', '102', '103', '104', '105'])
        ->and($data['rooms'][0]['category'])->toBe('Ikoyi Deluxe')
        ->and($data['rooms'][0]['category_short'])->toBe('ID');

    $stays = collect($data['stays'])->keyBy('ref');
    expect($stays)->toHaveCount(3) // Abuja never leaks
        ->and($stays[$seed['inHouse']->booking_ref])->toMatchArray([
            'room_id' => $this->rooms[0]->id, 'guest_surname' => 'Bello',
            'start' => '2026-10-21', 'end' => '2026-10-24', 'state' => 'in_house',
        ])
        // Overdue: booked night 19 + checkout day through today (20, 21, 22).
        ->and($stays[$seed['overdue']->booking_ref])->toMatchArray([
            'start' => '2026-10-19', 'end' => '2026-10-23', 'state' => 'overdue',
        ])
        ->and($stays[$seed['arriving']->booking_ref])->toMatchArray([
            'start' => '2026-10-22', 'end' => '2026-10-24', 'state' => 'arriving', 'balance_due' => 20_000,
        ]);

    expect($data['blocks'])->toBe([
        ['room_id' => $this->rooms[3]->id, 'start' => '2026-10-23', 'end' => '2026-10-25', 'kind' => 'maintenance', 'reason' => 'Maintenance'],
        ['room_id' => $this->rooms[3]->id, 'start' => '2026-10-25', 'end' => '2026-10-26', 'kind' => 'blocked', 'reason' => 'Owner use'],
    ]);

    expect(collect($data['occupancy'])->pluck('pct', 'date')->all())->toBe([
        '2026-10-19' => 20, '2026-10-20' => 20, '2026-10-21' => 40, '2026-10-22' => 60,
        '2026-10-23' => 40, '2026-10-24' => 0, '2026-10-25' => 0,
    ]);
});

it('marks a checked-in guest due out today as departing and future bookings as booked', function (): void {
    $departing = hostBooking($this->rooms[0], [
        'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => '2026-10-20', 'checkout_date' => '2026-10-22',
    ]);
    hostReserveNights($departing);
    $future = hostBooking($this->rooms[1], ['checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-26']);
    hostReserveNights($future);

    $stays = collect($this->getJson(hostUrl($this->tenant, 'calendar?from=2026-10-20'), hostHeaders($this->frontdesk))
        ->assertOk()->json('data.stays'))->keyBy('ref');

    expect($stays[$departing->booking_ref]['state'])->toBe('departing')
        ->and($stays[$departing->booking_ref]['end'])->toBe('2026-10-23') // drawn through today, like the web overlay
        ->and($stays[$future->booking_ref]['state'])->toBe('booked');
});

it('scopes the calendar to the branch picked with the property header', function (): void {
    calSeed($this);

    $data = $this->getJson(hostUrl($this->tenant, 'calendar?from=2026-10-22&days=1'),
        hostHeaders($this->manager, ['X-Keverna-Property' => (string) $this->abuja->id]))
        ->assertOk()->json('data');

    expect(collect($data['rooms'])->pluck('number')->all())->toBe(['901'])
        ->and(collect($data['stays'])->pluck('guest_name')->all())->toBe(['Abuja Guest'])
        ->and($data['occupancy'])->toBe([['date' => '2026-10-22', 'pct' => 100]]);
});

it('validates the calendar window and keeps housekeeping out', function (): void {
    $this->getJson(hostUrl($this->tenant, 'calendar?from=2026-10-22&days=40'), hostHeaders($this->frontdesk))->assertStatus(422);
    $this->getJson(hostUrl($this->tenant, 'calendar'), hostHeaders($this->frontdesk))->assertStatus(422);

    $cleaner = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->getJson(hostUrl($this->tenant, 'calendar?from=2026-10-22'), hostHeaders($cleaner))
        ->assertStatus(403)->assertJsonPath('error', 'role_not_supported');
});

it('does not leak another tenant calendar', function (): void {
    $other = Tenant::factory()->create(['slug' => 'host-cal-other']);
    $other->makeCurrent();
    $o = hostBranch($other, 'Other', 1, '5');
    $b = hostBooking($o['rooms'][0], ['guest_name' => 'Other Tenant Guest']);
    hostReserveNights($b);
    $this->tenant->makeCurrent();

    $data = $this->getJson(hostUrl($this->tenant, 'calendar?from=2026-10-22'), hostHeaders($this->frontdesk))->assertOk()->json('data');
    expect(collect($data['stays'])->pluck('guest_name')->all())->not->toContain('Other Tenant Guest')
        ->and(collect($data['rooms'])->pluck('number')->all())->not->toContain('501');
});

// ── Rooms board ──────────────────────────────────────────────────

function boardSeed(object $t): void
{
    [$r1, $r2, $r3, $r4] = $t->rooms;
    hostBooking($r1, [
        'guest_name' => 'Tunde Ade', 'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => '2026-10-19', 'checkout_date' => '2026-10-21',
    ]);
    $r2->forceFill(['housekeeping_status' => HousekeepingStatus::Dirty->value])->save();
    hostBooking($r3, ['guest_name' => 'Kemi Lawal', 'checkin_date' => '2026-10-22', 'checkout_date' => '2026-10-24']);
    app(FlagRoom::class)->handle($r4, $t->manager, 'Broken AC');
}

it('lists room tiles with counts and filters by state', function (): void {
    boardSeed($this);

    $res = $this->getJson(hostUrl($this->tenant, 'rooms'), hostHeaders($this->frontdesk))->assertOk();
    $tiles = collect($res->json('data'))->keyBy('number');

    expect($res->json('counts'))->toBe(['ready' => 1, 'dirty' => 1, 'occupied' => 1, 'arriving' => 1, 'out_of_order' => 1, 'total' => 5, 'departing_today' => 0, 'overdue' => 1])
        ->and($tiles->pluck('tile_state', 'number')->all())->toBe([
            '101' => 'occupied', '102' => 'dirty', '103' => 'arriving', '104' => 'out_of_order', '105' => 'ready',
        ])
        ->and($tiles['101']['guest'])->toMatchArray(['name' => 'Tunde Ade', 'checkout' => '2026-10-21', 'overdue' => true])
        ->and($tiles['101']['occupancy'])->toBe('occupied')
        ->and($tiles['103']['next_arrival'])->toMatchArray(['name' => 'Kemi Lawal', 'time_label' => 'Today'])
        ->and($tiles['104']['housekeeping'])->toBe('out_of_order')
        ->and($tiles['102']['dirty_since'])->not->toBeNull()
        ->and($tiles['105'])->toMatchArray(['housekeeping' => 'clean', 'occupancy' => 'free', 'guest' => null, 'cleaner' => null, 'floor' => null])
        ->and($tiles->keys()->all())->not->toContain('901')
        // Front desk sees the board but can't change it (web: manager / supervisor).
        ->and($res->json('can.change_status'))->toBeFalse();

    $dirty = $this->getJson(hostUrl($this->tenant, 'rooms?state=dirty'), hostHeaders($this->frontdesk))->assertOk();
    expect(collect($dirty->json('data'))->pluck('number')->all())->toBe(['102'])
        ->and($dirty->json('counts.total'))->toBe(5);

    $this->getJson(hostUrl($this->tenant, 'rooms?state=bogus'), hostHeaders($this->frontdesk))->assertStatus(422);
});

it('marks a dirty room clean with the web rule and logs it', function (): void {
    boardSeed($this);
    $room = $this->rooms[1];

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'clean'], hostHeaders($this->manager) + hostWriteKey())
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('room.housekeeping', 'clean')
        ->assertJsonPath('room.tile_state', 'ready');

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatus::Clean);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'housekeeping_assignment', 'description' => 'Rm 102 marked Clean.']);

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'dirty'], hostHeaders($this->manager) + hostWriteKey())
        ->assertOk()->assertJsonPath('room.tile_state', 'dirty');
});

it('flags a room out of order with a reason and clears the flag on clean', function (): void {
    $room = $this->rooms[4];

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'out_of_order'], hostHeaders($this->manager) + hostWriteKey())
        ->assertStatus(422)->assertJsonPath('error', 'status_failed');

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'out_of_order', 'reason' => 'Leaking pipe'], hostHeaders($this->manager) + hostWriteKey())
        ->assertOk()->assertJsonPath('room.tile_state', 'out_of_order');
    expect(RoomInspection::query()->where('room_id', $room->id)->latest('id')->first()->status)->toBe(RoomInspectionStatus::Failed);

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'clean', 'reason' => 'Plumber fixed it'], hostHeaders($this->manager) + hostWriteKey())
        ->assertOk()->assertJsonPath('room.tile_state', 'ready');
    expect(RoomInspection::query()->where('room_id', $room->id)->latest('id')->first()->status)->toBe(RoomInspectionStatus::Passed);
});

it('gates room status changes like the web board and scopes them to the branch', function (): void {
    $room = $this->rooms[1];
    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'clean'], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertStatus(403)->assertJsonPath('error', 'forbidden');

    $this->postJson(hostUrl($this->tenant, "rooms/{$this->abujaRoom->id}/status"), ['state' => 'clean'], hostHeaders($this->manager) + hostWriteKey())
        ->assertStatus(404);
    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/status"), ['state' => 'sparkling'], hostHeaders($this->manager) + hostWriteKey())
        ->assertStatus(422);
});

it('lists housekeepers and assigns a cleaner through the shared action', function (): void {
    $bisi = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner, 'name' => 'Bisi Cleaner']);
    $room = $this->rooms[4];

    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/assign-cleaner"), ['operator_id' => $bisi->id], hostHeaders($this->manager) + hostWriteKey())
        ->assertOk()
        ->assertJsonPath('room.cleaner.name', 'Bisi Cleaner')
        ->assertJsonPath('room.tile_state', 'dirty');

    $ins = RoomInspection::query()->where('room_id', $room->id)->sole();
    expect($ins->housekeeper_by_operator_id)->toBe($bisi->id)
        ->and($ins->status)->toBe(RoomInspectionStatus::Pending);

    $list = collect($this->getJson(hostUrl($this->tenant, 'housekeepers'), hostHeaders($this->frontdesk))->assertOk()->json('data'))->keyBy('name');
    expect($list['Bisi Cleaner']['rooms_today'])->toBe(1)
        ->and($list->keys()->all())->toContain('Chidi Okafor')       // managers can pick up a room (web list)
        ->and($list->keys()->all())->not->toContain('Ngozi Eze');    // front desk is not on the roster

    // Not on the roster -> 422; front desk can't assign -> 403.
    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/assign-cleaner"), ['operator_id' => $this->frontdesk->id], hostHeaders($this->manager) + hostWriteKey())
        ->assertStatus(422)->assertJsonPath('error', 'invalid_cleaner');
    $this->postJson(hostUrl($this->tenant, "rooms/{$room->id}/assign-cleaner"), ['operator_id' => $bisi->id], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertStatus(403);
});

it('returns room history in the staff app shape, gated by the housekeeping module', function (): void {
    $room = $this->rooms[0];
    app(FlagRoom::class)->handle($room, $this->manager, 'Broken AC');

    $this->getJson(hostUrl($this->tenant, "rooms/{$room->id}/history"), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.room.room_number', '101')
        ->assertJsonPath('data.0.summary_note', 'Manager flag: Broken AC');

    $this->getJson(hostUrl($this->tenant, "rooms/{$this->abujaRoom->id}/history"), hostHeaders($this->frontdesk))->assertStatus(404);

    $officer = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::ReservationOfficer]);
    $this->getJson(hostUrl($this->tenant, "rooms/{$room->id}/history"), hostHeaders($officer))->assertStatus(403);
});
