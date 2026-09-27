<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Housekeeping\FrontDeskOverrideInspection;
use App\Actions\Housekeeping\OpenRoomInspection;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Housekeeping\InspectionShow;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\Activity;
use App\Models\Booking;
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
 * Front-desk safety valve on the housekeeping chain:
 *   - Frontdesk / Manager can pass an open inspection with a reason
 *     so a stuck room becomes eligible for check-in.
 *   - Other roles can't run the override (audit-trail hygiene).
 *   - The overrideAndCheckIn flow on the reservation page does both
 *     in one round-trip.
 *   - The housekeeper "mark all done" button ticks every unchecked
 *     item at once, attributed to the current operator.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'fd-override']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 40_000, 'min_nights' => 1,
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
    foreach (['Bed made', 'Towels x2', 'Bathroom clean'] as $i => $label) {
        HousekeepingChecklistItem::query()->create([
            'tenant_id' => $this->tenant->id,
            'housekeeping_checklist_id' => $checklist->id,
            'label' => $label,
            'sort_order' => $i,
        ]);
    }
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
        'name' => 'Ada Frontdesk',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->houseCleaner = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::HouseCleaner,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── FrontDeskOverrideInspection ── */

it('passes the open inspection and stamps every sign-off slot with the overriding operator', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    expect($inspection->status)->toBe(RoomInspectionStatus::Pending);

    $result = app(FrontDeskOverrideInspection::class)->handle(
        $this->room, $this->frontdesk, 'housekeeper off shift',
    );

    expect($result->status)->toBe(RoomInspectionStatus::Passed);
    expect($result->supervisor_by_operator_id)->toBe($this->frontdesk->id);
    expect($result->housekeeper_by_operator_id)->toBe($this->frontdesk->id);
    expect($result->porter_by_operator_id)->toBe($this->frontdesk->id);
    expect($result->summary_note)->toContain('Front-desk override');
    expect($result->summary_note)->toContain('housekeeper off shift');
});

it('writes a frontdesk_override activity attributing the operator + reason', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);

    app(FrontDeskOverrideInspection::class)->handle(
        $this->room, $this->frontdesk, 'porter didn\'t show',
    );

    $log = Activity::query()
        ->where('event', 'frontdesk_override')
        ->where('subject_id', $inspection->id)
        ->latest('id')->first();
    expect($log)->not->toBeNull();
    expect((int) $log->causer_id)->toBe($this->frontdesk->id);
    expect($log->properties['reason'] ?? null)->toBe('porter didn\'t show');
});

it('refuses when the operator is neither frontdesk nor manager', function (): void {
    app(OpenRoomInspection::class)->handle($this->room);
    expect(fn () => app(FrontDeskOverrideInspection::class)->handle(
        $this->room, $this->houseCleaner, 'trying to override',
    ))->toThrow(RuntimeException::class);
});

it('refuses when there is no open inspection to override', function (): void {
    expect(fn () => app(FrontDeskOverrideInspection::class)->handle(
        $this->room, $this->frontdesk, 'nothing to do',
    ))->toThrow(RuntimeException::class);
});

it('refuses when reason is blank', function (): void {
    app(OpenRoomInspection::class)->handle($this->room);
    expect(fn () => app(FrontDeskOverrideInspection::class)->handle(
        $this->room, $this->frontdesk, '   ',
    ))->toThrow(RuntimeException::class);
});

it('lets a manager override too', function (): void {
    app(OpenRoomInspection::class)->handle($this->room);
    $result = app(FrontDeskOverrideInspection::class)->handle(
        $this->room, $this->manager, 'personally verified',
    );
    expect($result->status)->toBe(RoomInspectionStatus::Passed);
});

it('MarkCheckedIn allows check-in when a stale Failed inspection is followed by a fresh Passed one', function (): void {
    // Real-world regression: a room accumulates inspections over its
    // lifetime. One prior stay ended with a Failed inspection, but
    // housekeeping has since re-run and the CURRENT inspection is
    // Passed ("Ready for guest"). Previously the gate ran
    // whereNot('status', Passed)->latest() and picked up the stale
    // Failed row, blocking check-in with "flagged as not fit."
    Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));

    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Failed->value,
    ]);
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Passed->value,
    ]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Fresh Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->toDateString(),
        'checkout_date' => Carbon::today()->addDay()->toDateString(),
    ]);

    $action = app(MarkCheckedIn::class);
    $action->handle($booking->fresh(), $this->frontdesk);

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
    // The latest inspection is Passed, so no housekeeping warning
    // should be raised.
    expect($action->pendingInspection)->toBeNull();
    Carbon::setTestNow();
});

it('MarkCheckedIn still hard-blocks when the latest inspection is Failed', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Passed->value,
    ]);
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Failed->value,
    ]);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Blocked Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->toDateString(),
        'checkout_date' => Carbon::today()->addDay()->toDateString(),
    ]);

    expect(fn () => app(MarkCheckedIn::class)->handle($booking->fresh(), $this->frontdesk))
        ->toThrow(RuntimeException::class, 'flagged as not fit');
    Carbon::setTestNow();
});

/* ── overrideAndCheckIn (Livewire wiring on reservation page) ── */

it('overrideAndCheckIn clears the room + checks the guest in in one call', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));
    app(OpenRoomInspection::class)->handle($this->room); // blocker

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Chi Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->toDateString(),
        'checkout_date' => Carbon::today()->addDay()->toDateString(),
    ]);

    // Sanity — vanilla check-in refuses.
    expect(fn () => app(MarkCheckedIn::class)->handle($booking->fresh(), $this->frontdesk))
        ->toThrow(RuntimeException::class);

    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('overrideReason', 'housekeeper off shift, I did it')
        ->call('overrideAndCheckIn')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
    Carbon::setTestNow();
});

it('overrideAndCheckIn rejects non-frontdesk / non-manager operators', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));
    app(OpenRoomInspection::class)->handle($this->room);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ada Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->toDateString(),
        'checkout_date' => Carbon::today()->addDay()->toDateString(),
    ]);

    Livewire::actingAs($this->houseCleaner, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('overrideReason', 'trying')
        ->call('overrideAndCheckIn')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
    Carbon::setTestNow();
});

/* ── Housekeeper mark-all-done ── */

it('markAllDone ticks every unchecked item at once + attributes to the current operator', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    // sanity — items are pre-seeded from the checklist
    expect($inspection->items()->count())->toBe(3);
    expect($inspection->items()->where('checked', true)->count())->toBe(0);

    Livewire::actingAs($this->houseCleaner, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection])
        ->call('markAllDone')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    $items = RoomInspectionItem::query()->where('room_inspection_id', $inspection->id)->get();
    expect($items->every(fn ($i) => $i->checked === true))->toBeTrue();
    expect($items->every(fn ($i) => (int) $i->checked_by_operator_id === $this->houseCleaner->id))->toBeTrue();
});

it('markAllDone refuses when a non-housekeeper role tries to bulk-tick', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $porter = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter]);

    Livewire::actingAs($porter, 'web')
        ->test(InspectionShow::class, ['inspection' => $inspection])
        ->call('markAllDone')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'err');

    expect(RoomInspectionItem::query()->where('room_inspection_id', $inspection->id)
        ->where('checked', true)->count())->toBe(0);
});
