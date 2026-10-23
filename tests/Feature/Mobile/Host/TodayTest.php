<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

require_once __DIR__.'/HostTestHelpers.php';

/**
 * GET /api/mobile/v1/host/today on a seeded branch:
 *   Ikoyi (current, 4 rooms)
 *     A1  OnHold, room 101, arriving today, ₦20,000 balance, paid ₦30,000 today
 *     A2  Confirmed, room 102, arriving today, fully paid
 *     U1  Pending, category-only (no room), arriving today
 *     D1  CheckedIn, room 103, checkout was yesterday (overdue)
 *     D2  CheckedIn, room 104, leaving today, ₦5,000 balance
 *   Abuja (same tenant, other branch) + another tenant: noise that must not leak.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));

    $this->tenant = Tenant::factory()->create(['slug' => 'host-today']);
    $this->tenant->makeCurrent();

    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 4, '1');
    $this->property = $ikoyi['property'];
    $this->category = $ikoyi['category'];
    [$r1, $r2, $r3, $r4] = $ikoyi['rooms'];
    $abuja = hostBranch($this->tenant, 'Abuja', 1, '9');

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze']);

    $today = Carbon::today();
    $book = fn (Room $room, array $attrs) => Booking::factory()->for($this->tenant)->for($room)->create($attrs);

    $this->a1 = $book($r1, [
        'guest_name' => 'Amaka Bello', 'booking_status' => BookingStatus::OnHold,
        'checkin_date' => $today, 'checkout_date' => $today->copy()->addDays(2), 'nights' => 2,
        'room_total' => 50_000, 'total_amount' => 50_000, 'balance_due' => 20_000, 'balance_due_at' => null,
    ]);
    $this->a2 = $book($r2, [
        'guest_name' => 'Bayo Ade', 'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today, 'checkout_date' => $today->copy()->addDay(), 'nights' => 1,
        'room_total' => 30_000, 'total_amount' => 30_000, 'balance_due' => 0,
    ]);
    $this->u1 = Booking::factory()->for($this->tenant)->create([
        'room_id' => null, 'room_category_id' => $this->category->id,
        'guest_name' => 'Chioma Uche', 'booking_status' => BookingStatus::Pending,
        'checkin_date' => $today, 'checkout_date' => $today->copy()->addDay(), 'nights' => 1,
        'room_total' => 30_000, 'total_amount' => 30_000, 'balance_due' => 30_000,
    ]);
    $this->d1 = $book($r3, [
        'guest_name' => 'Dayo Femi', 'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => $today->copy()->subDays(3), 'checkout_date' => $today->copy()->subDay(), 'nights' => 2,
        'room_total' => 60_000, 'total_amount' => 60_000, 'balance_due' => 0,
    ]);
    $this->d2 = $book($r4, [
        'guest_name' => 'Efe Johnson', 'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => $today->copy()->subDays(2), 'checkout_date' => $today, 'nights' => 2,
        'room_total' => 60_000, 'total_amount' => 60_000, 'balance_due' => 5_000,
    ]);

    // Tonight: rooms 101 + 102 sold (2 of 4).
    foreach ([[$r1, $this->a1], [$r2, $this->a2]] as [$room, $booking]) {
        AvailabilityBlock::factory()->for($this->tenant)->create([
            'room_id' => $room->id, 'date' => $today->toDateString(),
            'type' => AvailabilityBlockType::Booked, 'reason' => null, 'booking_id' => $booking->id,
        ]);
    }

    // Money: ₦30,000 today, ₦10,000 two days ago, ₦5,000 refunded today,
    // ₦20,000 nine days ago (previous week window).
    $pay = fn (Booking $b, int $amount, Carbon $at, PaymentStatus $status = PaymentStatus::Succeeded) => Payment::factory()
        ->for($this->tenant)->for($b)->create(['amount' => $amount, 'status' => $status, 'paid_at' => $at]);
    $pay($this->a1, 30_000, Carbon::now()->subHour());
    $pay($this->d1, 10_000, Carbon::now()->subDays(2));
    $pay($this->d2, 5_000, Carbon::now()->subMinutes(10), PaymentStatus::Refunded);
    $pay($this->d2, 20_000, Carbon::now()->subDays(9));

    // Guest requests: complaint (urgent) + taxi open, order resolved.
    foreach ([[GuestRequestKind::Complaint, 'open'], [GuestRequestKind::Taxi, 'open'], [GuestRequestKind::Order, 'resolved']] as [$kind, $status]) {
        GuestRequest::query()->create([
            'tenant_id' => $this->tenant->id, 'booking_id' => $this->d2->id, 'room_id' => $r4->id,
            'kind' => $kind, 'status' => $status,
        ]);
    }
    GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $this->a1->id, 'room_id' => $r1->id,
        'kind' => 'extend', 'status' => 'pending', 'payload' => [],
    ]);

    // Same-tenant other branch: arrival, payment, request, approval. Must not show.
    $abujaRoom = $abuja['rooms'][0];
    $abujaBooking = $book($abujaRoom, [
        'guest_name' => 'Abuja Guest', 'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => $today, 'checkout_date' => $today->copy()->addDay(), 'nights' => 1,
        'room_total' => 99_000, 'total_amount' => 99_000, 'balance_due' => 99_000,
    ]);
    $pay($abujaBooking, 77_000, Carbon::now()->subHour());
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $abujaBooking->id, 'room_id' => $abujaRoom->id,
        'kind' => GuestRequestKind::Complaint, 'status' => 'open',
    ]);
    GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $abujaBooking->id, 'room_id' => $abujaRoom->id,
        'kind' => 'extend', 'status' => 'pending', 'payload' => [],
    ]);
    $this->abuja = $abuja['property'];

    // Another tenant with the same shape of data.
    $this->other = Tenant::factory()->create(['slug' => 'host-today-other']);
    $this->other->makeCurrent();
    $otherBranch = hostBranch($this->other, 'Elsewhere', 1, '5');
    $this->otherManager = Operator::factory()->for($this->other)->create(['role' => OperatorRole::Manager]);
    $otherBooking = Booking::factory()->for($this->other)->for($otherBranch['rooms'][0])->create([
        'guest_name' => 'Other Tenant Guest', 'booking_status' => BookingStatus::OnHold,
        'checkin_date' => $today, 'checkout_date' => $today->copy()->addDay(), 'nights' => 1,
        'room_total' => 88_000, 'total_amount' => 88_000, 'balance_due' => 88_000,
    ]);
    Payment::factory()->for($this->other)->for($otherBooking)->create([
        'amount' => 66_000, 'status' => PaymentStatus::Succeeded, 'paid_at' => Carbon::now(),
    ]);
    $this->otherHeaders = hostHeaders($this->otherManager);

    $this->tenant->makeCurrent();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('returns the Today payload for the current branch', function (): void {
    $d1Owed = $this->d1->fresh()->outstandingTotal();
    expect($d1Owed)->toBeGreaterThan(0); // one projected overstay night

    $response = $this->getJson(hostUrl($this->tenant, 'today'), hostHeaders($this->manager));
    $response->assertOk();
    $data = $response->json('data');

    expect($data['date'])->toBe('2026-10-22');
    expect($data['greeting_name'])->toBe('Chidi');

    expect($data['counts'])->toBe([
        'arriving' => 3,
        'arriving_unassigned' => 1,
        'leaving' => 2,
        'leaving_overdue' => 1,
        'in_house' => 2,
        'rooms_total' => 4,
        'requests_open' => 2,
        'requests_urgent' => 1,
    ]);

    // Owed = open stays in the branch: A1 20k + D2 5k + D1 overstay.
    // U1 has no room, so (like web Today) it is not in the outstanding tile.
    expect($data['money'])->toBe([
        'collected_today' => 30_000,
        'owed_total' => 20_000 + 5_000 + $d1Owed,
        'owed_stays' => 3,
    ]);

    expect($data['arrivals'])->toBe([
        [
            'ref' => $this->a1->booking_ref, 'guest_name' => 'Amaka Bello', 'room_number' => '101',
            'category' => 'Ikoyi Deluxe', 'eta' => null, 'balance_due' => 20_000,
            'status' => 'on_hold', 'status_label' => BookingStatus::OnHold->label(),
            'chip' => ['label' => '₦20,000 due', 'tone' => 'due'],
        ],
        [
            'ref' => $this->a2->booking_ref, 'guest_name' => 'Bayo Ade', 'room_number' => '102',
            'category' => 'Ikoyi Deluxe', 'eta' => null, 'balance_due' => 0,
            'status' => 'confirmed', 'status_label' => BookingStatus::Confirmed->label(),
            'chip' => ['label' => 'Paid', 'tone' => 'ok'],
        ],
        [
            'ref' => $this->u1->booking_ref, 'guest_name' => 'Chioma Uche', 'room_number' => null,
            'category' => 'Ikoyi Deluxe', 'eta' => null, 'balance_due' => 30_000,
            'status' => 'pending', 'status_label' => BookingStatus::Pending->label(),
            'chip' => ['label' => '₦30,000 due', 'tone' => 'due'],
        ],
    ]);

    expect($data['departures'])->toBe([
        ['ref' => $this->d1->booking_ref, 'guest_name' => 'Dayo Femi', 'room_number' => '103', 'balance_due' => $d1Owed, 'overdue' => true],
        ['ref' => $this->d2->booking_ref, 'guest_name' => 'Efe Johnson', 'room_number' => '104', 'balance_due' => 5_000, 'overdue' => false],
    ]);

    // Owner block (manager).
    $owner = $data['owner'];
    expect($owner['revenue_week'])->toBe(35_000);          // 30k + 10k - 5k refund
    expect($owner['revenue_week_change_pct'])->toBe(75);   // vs 20k the week before (JSON drops the .0)
    expect($owner['series_7d'])->toHaveCount(7);
    expect($owner['series_7d'][0]['date'])->toBe('2026-10-16');
    expect($owner['series_7d'][6])->toBe(['date' => '2026-10-22', 'amount' => 25_000]);
    expect($owner['series_7d'][4])->toBe(['date' => '2026-10-20', 'amount' => 10_000]);
    expect($owner['occupancy_tonight_pct'])->toBe(75);     // 101 + 102 booked, 103 overdue still in; 104 leaves today
    // (30k + 60k + 60k) / 5 nights; OnHold/Pending excluded. Every stay
    // is priced at 30k/night so the figure holds whether or not the
    // window's end date is inclusive on the test SQLite date columns.
    expect($owner['adr_week'])->toBe(30_000);
    expect($owner['approvals_waiting'])->toBe(1);
});

it('flags an arrival whose balance deadline has passed as late', function (): void {
    $this->a1->forceFill(['balance_due_at' => Carbon::now()->subHour()])->save();

    $arrivals = $this->getJson(hostUrl($this->tenant, 'today').'?fresh=1', hostHeaders($this->frontdesk))->json('data.arrivals');

    expect($arrivals[0]['chip'])->toBe(['label' => '₦20,000 overdue', 'tone' => 'late']);
});

it('hides the owner block from frontdesk but shows it with finance.view', function (): void {
    $this->getJson(hostUrl($this->tenant, 'today'), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.owner', null)
        ->assertJsonPath('data.counts.arriving', 3);

    $this->frontdesk->forceFill(['permissions' => ['finance.view']])->save();
    $this->getJson(hostUrl($this->tenant, 'today'), hostHeaders($this->frontdesk))
        ->assertOk()
        ->assertJsonPath('data.owner.approvals_waiting', 1);
});

it('scopes Today to the X-Keverna-Property branch', function (): void {
    $data = $this->getJson(
        hostUrl($this->tenant, 'today'),
        hostHeaders($this->manager, ['X-Keverna-Property' => (string) $this->abuja->id]),
    )->assertOk()->json('data');

    expect(collect($data['arrivals'])->pluck('guest_name')->all())->toBe(['Abuja Guest']);
    expect($data['counts']['rooms_total'])->toBe(1);
    expect($data['counts']['requests_urgent'])->toBe(1);
    expect($data['money']['collected_today'])->toBe(77_000);
    expect($data['departures'])->toBe([]);
    expect($data['owner']['approvals_waiting'])->toBe(1);
});

it('never shows another tenant\'s data', function (): void {
    $data = $this->getJson(hostUrl($this->tenant, 'today'), hostHeaders($this->manager))->json('data');
    $names = collect($data['arrivals'])->pluck('guest_name')->concat(collect($data['departures'])->pluck('guest_name'));
    expect($names)->not->toContain('Other Tenant Guest');
    expect($names)->not->toContain('Abuja Guest');
    expect($data['money']['collected_today'])->toBe(30_000);

    // Centurion runs one hotel per install, so the other tenant's own
    // host is not served here; its token must still be refused.
    // A token minted on one tenant does not work on another tenant's host.
    app('auth')->forgetGuards();
    $this->getJson(hostUrl($this->tenant, 'today'), $this->otherHeaders)->assertStatus(401);
    app('auth')->forgetGuards();
    $this->getJson(hostUrl($this->tenant, 'properties'), $this->otherHeaders)->assertStatus(401);
});

it('counts an overdue checked-in guest as occupying their room tonight, not one due out today', function (): void {
    // 101 + 102 booked tonight, 103 overdue (still in the room), 104 due out today.
    $this->getJson(hostUrl($this->tenant, 'today').'?fresh=1', hostHeaders($this->manager))
        ->assertOk()
        ->assertJsonPath('data.owner.occupancy_tonight_pct', 75);
});
