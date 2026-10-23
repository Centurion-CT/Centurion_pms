<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Http\Controllers\Mobile\Host\RoomBoard;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/DeskTestHelpers.php';

/**
 * host/rooms as Today on the desk reads it (docs/desk-app/PHASE-1.md):
 * departing today, overdue occupant, arriving guest name + date, and
 * the matching counts, through a desk operator token.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-23 09:00:00'));

    $this->tenant = Tenant::factory()->create(['slug' => 'desk-board']);
    $this->tenant->makeCurrent();
    $branch = hostBranch($this->tenant, 'Ikoyi', 5, '1');
    $this->property = $branch['property'];
    $this->rooms = $branch['rooms'];
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'name' => 'Ngozi Eze', 'role' => OperatorRole::Frontdesk, 'staff_pin_hash' => Hash::make('2222'),
    ]);

    [$r1, $r2, $r3, $r4] = $this->rooms;
    hostBooking($r1, ['guest_name' => 'Tunde Ade', 'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => '2026-10-21', 'checkout_date' => '2026-10-23']);
    hostBooking($r2, ['guest_name' => 'Kemi Lawal', 'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => '2026-10-20', 'checkout_date' => '2026-10-22']);
    hostBooking($r3, ['guest_name' => 'Ife Okoro', 'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => '2026-10-22', 'checkout_date' => '2026-10-25']);
    hostBooking($r4, ['guest_name' => 'Musa Bala', 'checkin_date' => '2026-10-23', 'checkout_date' => '2026-10-24']);
    hostBooking($this->rooms[4], ['guest_name' => 'Ada Obi', 'checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-26']);

    $desk = makeDesk($this->frontdesk, $this->property->id);
    $this->token = $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => $this->frontdesk->id, 'pin' => '2222'], deskBearer($desk['token'], deskKey()))
        ->assertOk()->json('token');
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('gives Today departing, overdue and arriving details on each tile', function (): void {
    $res = $this->getJson(hostUrl($this->tenant, 'rooms'), deskBearer($this->token))->assertOk();
    $tiles = collect($res->json('data'))->keyBy('number');

    expect($tiles['101']['guest'])->toBe([
        'ref' => $tiles['101']['guest']['ref'], 'name' => 'Tunde Ade', 'checkout' => '2026-10-23', 'overdue' => false, 'departing_today' => true,
    ])
        ->and($tiles['102']['guest'])->toMatchArray(['name' => 'Kemi Lawal', 'overdue' => true, 'departing_today' => false])
        ->and($tiles['103']['guest'])->toMatchArray(['overdue' => false, 'departing_today' => false])
        ->and($tiles['104']['tile_state'])->toBe('arriving')
        ->and($tiles['104']['next_arrival'])->toMatchArray(['name' => 'Musa Bala', 'time_label' => 'Today', 'date' => '2026-10-23', 'today' => true])
        ->and($tiles['105']['tile_state'])->toBe('ready')
        ->and($tiles['105']['next_arrival'])->toMatchArray(['name' => 'Ada Obi', 'time_label' => 'Tomorrow', 'date' => '2026-10-24', 'today' => false])
        // No floor in the data model yet.
        ->and($tiles['101']['floor'])->toBeNull();

    expect($res->json('counts'))->toBe([
        'ready' => 1, 'dirty' => 0, 'occupied' => 3, 'arriving' => 1, 'out_of_order' => 0, 'total' => 5,
        'departing_today' => 1, 'overdue' => 1,
    ]);
});

it('sends each room floor and its label on the room board', function (): void {
    $tiles = RoomBoard::tiles(Property::query()->first());
    expect($tiles)->not->toBeEmpty();
    foreach ($tiles as $t) {
        expect($t)->toHaveKeys(['floor', 'floor_label']);
        $expected = Room::query()->where('room_number', $t['number'])->value('floor');
        expect($t['floor'])->toBe($expected)->and($t['floor_label'])->toBe(Room::floorLabel($expected));
    }
});
