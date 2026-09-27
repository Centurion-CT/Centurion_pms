<?php

declare(strict_types=1);

use App\Actions\Bookings\ReassignRoom;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Availability\AvailabilityService;
use App\Services\Bookings\RoomAllocator;
use Illuminate\Support\Carbon;

/**
 * Cross-room reassignment for CheckedIn guests: the front-desk desk
 * moves the guest to a different room mid-stay + collects any rate
 * difference. Two subtle rules verify here:
 *
 *   1. Candidate pool must ignore past-night conflicts on the target
 *      room (the guest is only moving into the REMAINING nights).
 *   2. Past-night blocks stay on the old room so the ledger reads
 *      truthfully - only the remaining nights repoint.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'reassign-ci']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->cheap = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 30_000,
    ]);
    $this->pricier = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 50_000,
    ]);
    $this->roomA = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->cheap, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->pricier, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '201']);
    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));

    // 3-night booking on roomA: Sep 7 -> Sep 10. Guest checked in
    // Sep 7 + is currently on their second night; 1 night remaining.
    $this->booking = Booking::factory()->for($this->tenant)->for($this->roomA)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::create(2026, 9, 7),
        'checkout_date' => Carbon::create(2026, 9, 10),
        'nights' => 3,
        'total_amount' => 90_000, 'room_total' => 90_000,
        'balance_due' => 0,
    ]);
    // Reserve roomA for the three nights.
    foreach ([Carbon::create(2026, 9, 7), Carbon::create(2026, 9, 8), Carbon::create(2026, 9, 9)] as $d) {
        AvailabilityBlock::query()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->roomA->id,
            'room_category_id' => $this->cheap->id,
            'date' => $d->format('Y-m-d H:i:s'),
            'type' => \App\Enums\AvailabilityBlockType::Booked->value,
            'booking_id' => $this->booking->id,
        ]);
    }
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('candidate pool ignores past-night conflicts on the target room', function (): void {
    // Give roomB an existing block on Sep 7 (before our guest's
    // remaining window). It should NOT filter roomB out of the
    // candidate pool since the reassign only moves nights today
    // onward (Sep 9 -> Sep 10, one night remaining).
    AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $this->roomB->id,
        'room_category_id' => $this->pricier->id,
        'date' => Carbon::create(2026, 9, 7)->format('Y-m-d H:i:s'),
        'type' => \App\Enums\AvailabilityBlockType::Booked->value,
        'booking_id' => null,
    ]);

    $allocator = app(RoomAllocator::class);
    $candidates = $allocator->availableRoomsInProperty(
        propertyId: $this->property->id,
        // Simulating what Reservations\Show now passes for CheckedIn:
        // max(today, checkin_date).
        checkin: Carbon::create(2026, 9, 9),
        checkout: Carbon::create(2026, 9, 10),
        excludeRoomId: $this->roomA->id,
    );

    expect($candidates->pluck('id')->all())->toContain($this->roomB->id);
});

it('reassigns roomA → roomB, only future nights move, pro-rata rate applied', function (): void {
    // Pro-rata: 2 nights already stayed at ₦30k (roomA), 1 remaining
    // at ₦50k (roomB). rate_delta = (50k − 30k) × 1 = +₦20k.
    $result = app(ReassignRoom::class)->handle(
        $this->booking, $this->roomB, $this->manager,
    );

    expect($result->rateDelta)->toBe(20_000);
    expect($result->nights)->toBe(3);
    // Activity log carries the per-night breakdown for audit.
    $activity = \App\Models\Activity::query()
        ->where('subject_id', $this->booking->id)
        ->where('event', 'room_reassigned')
        ->latest('id')->first();
    $props = $activity->properties ?? collect();
    $props = is_array($props) ? $props : $props->toArray();
    expect((int) $props['nights_stayed_at_old_rate'])->toBe(2);
    expect((int) $props['remaining_nights_at_new_rate'])->toBe(1);

    $b = $this->booking->refresh();
    expect($b->room_id)->toBe($this->roomB->id);
    expect((int) $b->total_amount)->toBe(110_000);   // 90k + 20k
    expect((int) $b->balance_due)->toBe(20_000);     // rate delta
});

it('renders the "Change room" action button on reservation-show for a CheckedIn guest', function (): void {
    $r = \Livewire\Livewire::actingAs($this->manager, 'web')
        ->test(\App\Livewire\Dashboard\Reservations\Show::class, ['bookingRef' => $this->booking->booking_ref]);
    $r->assertSee('data-testid="action-change-room"', false)
        ->assertSee('Change room');
});

it('leaves past-night blocks on the old room + repoints only future nights', function (): void {
    app(ReassignRoom::class)->handle(
        $this->booking, $this->roomB, $this->manager,
    );

    // Sep 7 + Sep 8 blocks should still belong to roomA (past
    // nights the guest actually slept there).
    $roomABlocks = AvailabilityBlock::query()
        ->where('booking_id', $this->booking->id)
        ->where('room_id', $this->roomA->id)
        ->orderBy('date')->pluck('date')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())->all();
    expect($roomABlocks)->toBe([
        '2026-09-07', '2026-09-08',
    ]);

    // Sep 9 (today's remaining night) repointed to roomB.
    $roomBBlocks = AvailabilityBlock::query()
        ->where('booking_id', $this->booking->id)
        ->where('room_id', $this->roomB->id)
        ->orderBy('date')->pluck('date')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->toDateString())->all();
    expect($roomBBlocks)->toBe(['2026-09-09']);
});
