<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Bookings\MarkCheckedOut;
use App\Actions\Housekeeping\OpenRoomInspection;
use App\Actions\Housekeeping\SignOffSupervisorInspection;
use App\Actions\Housekeeping\SubmitHousekeeperCleaning;
use App\Actions\Housekeeping\VerifyPorterInspection;
use App\Enums\BookingStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\HousekeepingChecklist;
use App\Models\HousekeepingChecklistItem;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Housekeeping workflow — checkout opens a fresh inspection, three
 * signers advance it, and check-in is blocked until it Passes.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'hk-workflow']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        // Set clean so the checkin gate isn't blocked by housekeeping_status.
        'housekeeping_status' => HousekeepingStatus::Clean->value,
    ]);
    // Category checklist — 3 items on a reusable bundle attached
    // to this category.
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

    $this->manager   = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->housekeeper = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->porter    = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Porter]);
    $this->supervisor = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Supervisor]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ─── Trigger ──────────────────────────────────────────────────── */

it('opens a fresh inspection with snapshotted checklist items on checkout', function (): void {
    // A CheckedIn booking to check out. Balance must be 0 for MarkCheckedOut.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'payment_status' => PaymentStatus::Succeeded,
        'total_amount' => 40_000,
        'balance_due' => 0,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
    ]);

    app(MarkCheckedOut::class)->handle($booking, $this->manager);

    $inspection = RoomInspection::query()->first();
    expect($inspection)->not->toBeNull();
    expect($inspection->status)->toBe(RoomInspectionStatus::Pending);
    expect($inspection->room_id)->toBe($this->room->id);
    expect($inspection->triggered_by_booking_id)->toBe($booking->id);
    expect($inspection->items()->count())->toBe(3);
    expect($inspection->items()->pluck('label')->all())
        ->toBe(['Bed made', 'Towels x2', 'Bathroom clean']);
});

it('OpenRoomInspection is idempotent — a second call returns the existing one', function (): void {
    $first = app(OpenRoomInspection::class)->handle($this->room);
    $second = app(OpenRoomInspection::class)->handle($this->room);

    expect($first->id)->toBe($second->id);
    expect(RoomInspection::query()->count())->toBe(1);
});

/* ─── Sign-off chain ───────────────────────────────────────────── */

it('housekeeper → porter → supervisor advances the inspection to Passed', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    // Housekeeper checks every item.
    $inspection->items()->update(['checked' => true]);

    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);
    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::CleaningDone);
    expect($inspection->readiness_score)->toBe(100);
    expect($inspection->housekeeper_by_operator_id)->toBe($this->housekeeper->id);

    app(VerifyPorterInspection::class)->handle($inspection->refresh(), $this->porter, accept: true);
    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::PorterVerified);
    expect($inspection->porter_by_operator_id)->toBe($this->porter->id);

    app(SignOffSupervisorInspection::class)->handle($inspection->refresh(), $this->supervisor, accept: true);
    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::Passed);
    expect($inspection->supervisor_by_operator_id)->toBe($this->supervisor->id);
});

it('housekeeper score subtracts 10 per unchecked item + 5 per damage note', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    // Only 1 of 3 checked; one has a damage note.
    $items = $inspection->items()->orderBy('id')->get();
    $items[0]->forceFill(['checked' => true])->save();
    $items[1]->forceFill(['checked' => false, 'damage_note' => 'Torn towel'])->save();
    $items[2]->forceFill(['checked' => false])->save();

    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);
    // 100 - (2 unchecked * 10) - (1 damage * 5) = 75
    expect($inspection->refresh()->readiness_score)->toBe(75);
});

it('porter rejection flips status to Failed', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $inspection->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);

    app(VerifyPorterInspection::class)->handle(
        $inspection->refresh(), $this->porter, accept: false, reason: 'Bathroom still wet',
    );
    expect($inspection->refresh()->status)->toBe(RoomInspectionStatus::Failed);
    expect($inspection->summary_note)->toBe('Bathroom still wet');
});

it('supervisor sign-off requires porter stage first', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);

    expect(fn () => app(SignOffSupervisorInspection::class)->handle(
        $inspection, $this->supervisor, accept: true,
    ))->toThrow(RuntimeException::class, 'awaiting supervisor');
});

it('non-authorised roles cannot advance stages', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk]);

    expect(fn () => app(SubmitHousekeeperCleaning::class)->handle($inspection, $frontdesk))
        ->toThrow(RuntimeException::class);
});

/* ─── Check-in gate ────────────────────────────────────────────── */

it('blocks check-in while an open inspection is not Passed', function (): void {
    // Open an inspection — status stays Pending.
    app(OpenRoomInspection::class)->handle($this->room);

    $arrival = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Succeeded,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    expect(fn () => app(MarkCheckedIn::class)->handle($arrival, $this->manager))
        ->toThrow(RuntimeException::class, 'waiting on housekeeper');
});

it('unlocks check-in once the inspection reaches Passed', function (): void {
    $inspection = app(OpenRoomInspection::class)->handle($this->room);
    $inspection->items()->update(['checked' => true]);
    app(SubmitHousekeeperCleaning::class)->handle($inspection, $this->housekeeper);
    app(VerifyPorterInspection::class)->handle($inspection->refresh(), $this->porter);
    app(SignOffSupervisorInspection::class)->handle($inspection->refresh(), $this->supervisor);

    $arrival = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'payment_status' => PaymentStatus::Succeeded,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    $result = app(MarkCheckedIn::class)->handle($arrival, $this->manager);
    expect($result->booking_status)->toBe(BookingStatus::CheckedIn);
});
