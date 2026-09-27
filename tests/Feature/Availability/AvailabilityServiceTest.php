<?php

declare(strict_types=1);

use App\Enums\AvailabilityBlockType;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\RangeNotAvailableException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->svc = app(AvailabilityService::class);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ---------- isAvailable() — half-open interval ---------- */

it('reports an empty calendar as available for any future range', function (): void {
    expect($this->svc->isAvailable(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-05'),
    ))->toBeTrue();
});

it('reports unavailable when the range overlaps a Booked night', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2026-07-03']);

    expect($this->svc->isAvailable(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-05'),
    ))->toBeFalse();
});

it('reports unavailable when the range overlaps a Blocked night (maintenance)', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2026-07-03', 'type' => AvailabilityBlockType::Blocked]);

    expect($this->svc->isAvailable(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-05'),
    ))->toBeFalse();
});

it('treats back-to-back bookings as both valid (half-open interval)', function (): void {
    // Guest A holds nights 1-4 (checkout = morning of 5).
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->createMany([
            ['date' => '2026-07-01'], ['date' => '2026-07-02'],
            ['date' => '2026-07-03'], ['date' => '2026-07-04'],
        ]);

    // Guest B can check in on the 5th — the 5th is NOT occupied.
    expect($this->svc->isAvailable(
        $this->room, Carbon::parse('2026-07-05'), Carbon::parse('2026-07-07'),
    ))->toBeTrue();
});

it('returns false when checkout equals checkin', function (): void {
    expect($this->svc->isAvailable(
        $this->room, Carbon::parse('2026-07-05'), Carbon::parse('2026-07-05'),
    ))->toBeFalse();
});

/* ---------- blockRange() — operator manual block ---------- */

it('inserts one row per night for blockRange', function (): void {
    $this->svc->blockRange(
        $this->room,
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-04'),   // 3 nights: 1, 2, 3
        reason: 'Owner stay',
    );

    expect(AvailabilityBlock::where('room_id', $this->room->id)->count())->toBe(3);
    expect(AvailabilityBlock::pluck('reason')->unique()->all())->toBe(['Owner stay']);
    expect(AvailabilityBlock::pluck('type')->unique()->all())->toBe([AvailabilityBlockType::Blocked]);
});

it('throws RangeNotAvailableException when blocking a range that overlaps existing rows', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2026-07-03']);

    expect(fn () => $this->svc->blockRange(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-05'),
    ))->toThrow(RangeNotAvailableException::class);

    // Partial inserts must have been rolled back.
    expect(AvailabilityBlock::count())->toBe(1);
});

it('rejects checkout <= checkin', function (): void {
    expect(fn () => $this->svc->blockRange(
        $this->room, Carbon::parse('2026-07-05'), Carbon::parse('2026-07-05'),
    ))->toThrow(InvalidArgumentException::class);
});

/* ---------- unblockRange() — only blocks, never bookings ---------- */

it('removes Blocked rows in the range', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->createMany([
            ['date' => '2026-07-01', 'type' => AvailabilityBlockType::Blocked],
            ['date' => '2026-07-02', 'type' => AvailabilityBlockType::Blocked],
            ['date' => '2026-07-03', 'type' => AvailabilityBlockType::Blocked],
        ]);

    $deleted = $this->svc->unblockRange(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-04'),
    );

    expect($deleted)->toBe(3);
    expect(AvailabilityBlock::count())->toBe(0);
});

it('preserves Booked rows when unblocking a mixed range', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2026-07-01', 'type' => AvailabilityBlockType::Blocked]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2026-07-02']);

    $this->svc->unblockRange(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-03'),
    );

    expect(AvailabilityBlock::count())->toBe(1);
    expect(AvailabilityBlock::first()->type)->toBe(AvailabilityBlockType::Booked);
});

/* ---------- reserveRangeForBooking() — booking engine ---------- */

it('creates Booked rows stamped with the originating booking_id', function (): void {
    // Real Booking so the FK constraint (added once bookings table existed) is satisfied.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();

    $this->svc->reserveRangeForBooking(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-04'), bookingId: $booking->id,
    );

    $rows = AvailabilityBlock::where('room_id', $this->room->id)->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('type')->unique()->all())->toBe([AvailabilityBlockType::Booked]);
    expect($rows->pluck('booking_id')->unique()->all())->toBe([$booking->id]);
});

/* ---------- getBlocksForMonth() ---------- */

it('returns only blocks inside the requested month', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->createMany([
            ['date' => '2026-06-30'],
            ['date' => '2026-07-01'],
            ['date' => '2026-07-15'],
            ['date' => '2026-07-31'],
            ['date' => '2026-08-01'],
        ]);

    $july = $this->svc->getBlocksForMonth($this->room, Carbon::parse('2026-07-15'));

    expect($july)->toHaveCount(3);
});

/* ---------- DB-level uniqueness — the race-condition firewall ---------- */

it('the unique(tenant_id, room_id, date) constraint rejects duplicate inserts', function (): void {
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2026-07-10']);

    expect(fn () => AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2026-07-10']))
        ->toThrow(QueryException::class);
});

/* ---------- Cross-tenant isolation ---------- */

it('isAvailable() does NOT consider another tenant\'s blocks', function (): void {
    // Tenant B holds the same date.
    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();
    $tenantBProp = Property::factory()->for($tenantB)->create();
    $tenantBRoom = Room::factory()->for($tenantB)->for($tenantBProp)->create(['id' => $this->room->id + 100]);
    AvailabilityBlock::factory()->for($tenantB)->for($tenantBRoom)->booked()->create(['date' => '2026-07-03']);

    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    // Our room on our tenant is still free.
    expect($this->svc->isAvailable(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-05'),
    ))->toBeTrue();
});

it('blockRange on tenant A cannot affect tenant B (room_id scoping is hard)', function (): void {
    // Even though we pass our $this->room, the rows we insert carry our tenant_id.
    $this->svc->blockRange(
        $this->room, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-04'),
    );

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();
    expect(AvailabilityBlock::count())->toBe(0);

    // Confirm the rows do exist under tenant A.
    Tenant::forgetCurrent();
    expect(
        AvailabilityBlock::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->count()
    )->toBe(3);
});
