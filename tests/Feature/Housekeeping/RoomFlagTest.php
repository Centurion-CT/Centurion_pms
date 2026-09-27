<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedIn;
use App\Actions\Housekeeping\ClearRoomFlag;
use App\Actions\Housekeeping\FlagRoom;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Housekeeping\RoomHistory;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomInspection;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Manager-only "put this room out of service" control. The two
 * actions swap the room's latest inspection state, and the check-in
 * gate keys off "latest is Failed" so a flagged room is uncheckable
 * until a manager clears it.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'room-flag']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
        'room_number' => '207',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'name' => 'Ada Manager',
    ]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk, 'name' => 'Chi Frontdesk',
    ]);
    $this->supervisor = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Supervisor, 'name' => 'Ola Supervisor',
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

/* ── FlagRoom ── */

it('creates a Failed inspection carrying the reason on summary_note', function (): void {
    $result = app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');

    expect($result->status)->toBe(RoomInspectionStatus::Failed);
    expect($result->summary_note)->toBe('Manager flag: AC broken');
    expect($result->supervisor_by_operator_id)->toBe($this->manager->id);
    expect($result->room_id)->toBe($this->room->id);
});

it('writes a room_flagged activity attributing the operator and reason', function (): void {
    $inspection = app(FlagRoom::class)->handle($this->room, $this->manager, 'Broken window');

    $log = Activity::query()
        ->where('event', 'room_flagged')
        ->where('subject_id', $inspection->id)
        ->latest('id')->first();
    expect($log)->not->toBeNull();
    expect((int) $log->causer_id)->toBe($this->manager->id);
    expect($log->properties['reason'] ?? null)->toBe('Broken window');
});

it('refuses when the operator is not a manager', function (): void {
    expect(fn () => app(FlagRoom::class)->handle($this->room, $this->frontdesk, 'trying'))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(FlagRoom::class)->handle($this->room, $this->supervisor, 'trying'))
        ->toThrow(RuntimeException::class);
});

it('refuses when the reason is blank', function (): void {
    expect(fn () => app(FlagRoom::class)->handle($this->room, $this->manager, '   '))
        ->toThrow(RuntimeException::class);
});

it('blocks check-in on the flagged room via MarkCheckedIn', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));
    // Room was already Passed from a prior turnover; the fresh
    // Failed inspection should trump it and block check-in.
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Passed->value,
    ]);
    app(FlagRoom::class)->handle($this->room, $this->manager, 'Plumbing');

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->toDateString(),
        'checkout_date' => Carbon::today()->addDay()->toDateString(),
    ]);

    expect(fn () => app(MarkCheckedIn::class)->handle($booking->fresh(), $this->frontdesk))
        ->toThrow(RuntimeException::class, 'flagged as not fit');
    Carbon::setTestNow();
});

/* ── ClearRoomFlag ── */

it('creates a Passed inspection when clearing an active flag', function (): void {
    app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');

    $cleared = app(ClearRoomFlag::class)->handle($this->room, $this->manager);

    expect($cleared->status)->toBe(RoomInspectionStatus::Passed);
    expect($cleared->summary_note)->toBe('Manager cleared flag.');
    expect($cleared->supervisor_by_operator_id)->toBe($this->manager->id);
    expect($cleared->readiness_score)->toBe(100);
});

it('unblocks check-in once the flag is cleared', function (): void {
    Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));
    app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');
    app(ClearRoomFlag::class)->handle($this->room, $this->manager);

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Guest',
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::today()->toDateString(),
        'checkout_date' => Carbon::today()->addDay()->toDateString(),
    ]);

    app(MarkCheckedIn::class)->handle($booking->fresh(), $this->frontdesk);
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
    Carbon::setTestNow();
});

it('refuses to clear when the room is not currently flagged', function (): void {
    // Latest inspection is Passed → not flagged → no-op should throw
    // so a stray click doesn't overwrite an in-flight inspection.
    RoomInspection::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->room->id,
        'status' => RoomInspectionStatus::Passed->value,
    ]);

    expect(fn () => app(ClearRoomFlag::class)->handle($this->room, $this->manager))
        ->toThrow(RuntimeException::class, 'not currently flagged');
});

it('refuses to clear when the operator is not a manager', function (): void {
    app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');

    expect(fn () => app(ClearRoomFlag::class)->handle($this->room, $this->frontdesk))
        ->toThrow(RuntimeException::class);
});

/* ── RoomHistory Livewire wiring ── */

it('RoomHistory flagRoom method wires the FlagRoom action', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(RoomHistory::class, ['room' => $this->room])
        ->set('flagReason', 'Broken window, glazier on Monday')
        ->call('flagRoom')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'warn');

    $latest = RoomInspection::query()->where('room_id', $this->room->id)->latest('id')->first();
    expect($latest?->status)->toBe(RoomInspectionStatus::Failed);
    expect($latest?->summary_note)->toContain('Broken window');
});

it('RoomHistory clearRoomFlag method wires the ClearRoomFlag action', function (): void {
    app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');

    Livewire::actingAs($this->manager, 'web')
        ->test(RoomHistory::class, ['room' => $this->room])
        ->call('clearRoomFlag')
        ->assertDispatched('notify', fn (string $e, array $p) => ($p['kind'] ?? null) === 'ok');

    $latest = RoomInspection::query()->where('room_id', $this->room->id)->latest('id')->first();
    expect($latest?->status)->toBe(RoomInspectionStatus::Passed);
});

it('RoomHistory surfaces the flag banner and clear button when flagged for a manager', function (): void {
    app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');

    $html = Livewire::actingAs($this->manager, 'web')
        ->test(RoomHistory::class, ['room' => $this->room])
        ->html();

    expect($html)->toContain('data-testid="hk-room-flag-banner"');
    expect($html)->toContain('data-testid="hk-room-clear-flag"');
    expect($html)->toContain('AC broken');
});

it('RoomHistory does not render the flag form for non-managers', function (): void {
    $html = Livewire::actingAs($this->frontdesk, 'web')
        ->test(RoomHistory::class, ['room' => $this->room])
        ->html();

    expect($html)->not->toContain('data-testid="hk-room-flag-form"');
    expect($html)->not->toContain('data-testid="hk-room-flag-toggle"');
});

it('RoomHistory surfaces the flag banner without controls for non-managers when flagged', function (): void {
    app(FlagRoom::class)->handle($this->room, $this->manager, 'AC broken');

    $html = Livewire::actingAs($this->frontdesk, 'web')
        ->test(RoomHistory::class, ['room' => $this->room])
        ->html();

    // Banner is still visible so everyone sees WHY check-in is
    // blocked, but the clear button is manager-only.
    expect($html)->toContain('data-testid="hk-room-flag-banner"');
    expect($html)->toContain('AC broken');
    expect($html)->not->toContain('data-testid="hk-room-clear-flag"');
});
