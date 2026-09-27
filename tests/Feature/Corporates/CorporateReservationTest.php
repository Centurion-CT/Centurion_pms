<?php

declare(strict_types=1);

use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateReservationStatus;
use App\Enums\CorporateStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Corporates\Show as CorpShow;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\CorporateReservation;
use App\Models\CorporateReservationCategory;
use App\Models\CorporateReservationMember;
use App\Models\Operator;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Corporate reservation window: an allocation of rooms + a list of
 * expected staff for a specific branch + date range. This is what
 * a Manager sets up ahead of an incoming corporate delegation.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'corp-res']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->catStandard = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 50_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->catSuite = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Suite', 'base_rate' => 80_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
        'accepts_reservations' => true,
        'created_by_tenant_id' => $this->tenant->id,
    ]);
    $this->m1 = CorporateMember::query()->create([
        'corporate_id' => $this->corp->id, 'name' => 'Ada One',
        'email' => 'ada@acme.test', 'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $this->m2 = CorporateMember::query()->create([
        'corporate_id' => $this->corp->id, 'name' => 'Bola Two',
        'email' => 'bola@acme.test', 'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 12, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

// ── accepts_reservations toggle ──────────────────────────────────

it('toggles accepts_reservations between open + paused', function (): void {
    expect($this->corp->accepts_reservations)->toBeTrue();

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('toggleAcceptsReservations')
        ->assertDispatched('notify');
    expect($this->corp->refresh()->accepts_reservations)->toBeFalse();

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('toggleAcceptsReservations');
    expect($this->corp->refresh()->accepts_reservations)->toBeTrue();
});

// ── Corporate reservation CRUD ───────────────────────────────────

it('creates a corporate reservation with categories + members', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newReservation')
        ->set('reservationPropertyId', $this->property->id)
        ->set('reservationStartsAt', Carbon::today()->addDay()->toDateString())
        ->set('reservationEndsAt', Carbon::today()->addDays(4)->toDateString())
        ->set('reservationCategoryRows', [
            ['room_category_id' => $this->catStandard->id, 'rooms_count' => 2],
            ['room_category_id' => $this->catSuite->id, 'rooms_count' => 1],
        ])
        ->call('toggleReservationMember', $this->m1->id)
        ->call('toggleReservationMember', $this->m2->id)
        ->set('reservationNotes', 'Q4 offsite delegation')
        ->call('saveReservation')
        ->assertDispatched('notify');

    $r = CorporateReservation::query()->first();
    expect($r)->not->toBeNull();
    expect($r->status)->toBe(CorporateReservationStatus::Pending);
    expect($r->nights())->toBe(3);
    expect($r->totalRoomsAllocated())->toBe(3);
    expect($r->notes)->toBe('Q4 offsite delegation');

    $cats = CorporateReservationCategory::query()
        ->where('corporate_reservation_id', $r->id)->get();
    expect($cats)->toHaveCount(2);

    $membs = CorporateReservationMember::query()
        ->where('corporate_reservation_id', $r->id)->get();
    expect($membs)->toHaveCount(2);
    expect($membs->pluck('corporate_member_id')->sort()->values()->all())
        ->toBe(collect([$this->m1->id, $this->m2->id])->sort()->values()->all());
});

it('refuses saving when accepts_reservations is off', function (): void {
    $this->corp->forceFill(['accepts_reservations' => false])->save();

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newReservation')
        ->set('reservationPropertyId', $this->property->id)
        ->set('reservationStartsAt', Carbon::today()->addDay()->toDateString())
        ->set('reservationEndsAt', Carbon::today()->addDays(3)->toDateString())
        ->set('reservationCategoryRows', [
            ['room_category_id' => $this->catStandard->id, 'rooms_count' => 1],
        ])
        ->call('saveReservation')
        ->assertDispatched('notify');

    expect(CorporateReservation::query()->count())->toBe(0);
});

it('refuses saving with an inverted date range', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newReservation')
        ->set('reservationPropertyId', $this->property->id)
        ->set('reservationStartsAt', Carbon::today()->addDays(4)->toDateString())
        ->set('reservationEndsAt', Carbon::today()->addDay()->toDateString())
        ->set('reservationCategoryRows', [
            ['room_category_id' => $this->catStandard->id, 'rooms_count' => 1],
        ])
        ->call('saveReservation')
        ->assertDispatched('notify');

    expect(CorporateReservation::query()->count())->toBe(0);
});

it('refuses duplicate categories in the same reservation', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newReservation')
        ->set('reservationPropertyId', $this->property->id)
        ->set('reservationStartsAt', Carbon::today()->addDay()->toDateString())
        ->set('reservationEndsAt', Carbon::today()->addDays(3)->toDateString())
        ->set('reservationCategoryRows', [
            ['room_category_id' => $this->catStandard->id, 'rooms_count' => 2],
            ['room_category_id' => $this->catStandard->id, 'rooms_count' => 1],
        ])
        ->call('saveReservation')
        ->assertDispatched('notify');

    expect(CorporateReservation::query()->count())->toBe(0);
});

it('editReservation loads existing state into the form + save updates in place', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id'  => $this->property->id,
        'starts_at'    => Carbon::today()->addDay(),
        'ends_at'      => Carbon::today()->addDays(3),
        'status'       => CorporateReservationStatus::Pending->value,
        'notes'        => 'Original note',
    ]);
    CorporateReservationCategory::query()->create([
        'corporate_reservation_id' => $r->id,
        'room_category_id' => $this->catStandard->id,
        'rooms_count' => 2,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->m1->id,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('editReservation', $r->id)
        ->assertSet('editingReservationId', $r->id)
        ->assertSet('reservationPropertyId', $this->property->id)
        ->assertSet('reservationNotes', 'Original note')
        ->set('reservationEndsAt', Carbon::today()->addDays(5)->toDateString())
        ->set('reservationCategoryRows', [
            ['room_category_id' => $this->catStandard->id, 'rooms_count' => 3],
            ['room_category_id' => $this->catSuite->id, 'rooms_count' => 1],
        ])
        // Swap member: drop m1, pick m2.
        ->call('toggleReservationMember', $this->m1->id)
        ->call('toggleReservationMember', $this->m2->id)
        ->set('reservationNotes', 'Updated note')
        ->call('saveReservation')
        ->assertDispatched('notify');

    // Same row, updated in place.
    expect(CorporateReservation::query()->count())->toBe(1);
    $r->refresh();
    expect($r->notes)->toBe('Updated note');
    expect($r->ends_at->toDateString())->toBe(Carbon::today()->addDays(5)->toDateString());

    // Two categories: Standard 3 + Suite 1.
    $cats = CorporateReservationCategory::query()
        ->where('corporate_reservation_id', $r->id)
        ->orderBy('room_category_id')
        ->get();
    expect($cats)->toHaveCount(2);
    expect($cats->sum('rooms_count'))->toBe(4);

    // Member list: only m2 (m1 was toggled off, m2 toggled on).
    $memberIds = CorporateReservationMember::query()
        ->where('corporate_reservation_id', $r->id)
        ->pluck('corporate_member_id')
        ->all();
    expect($memberIds)->toBe([$this->m2->id]);
});

it('edit preserves members with an assigned booking even if the operator forgets them', function (): void {
    // Regression: unchecking a member during edit shouldn't orphan a
    // booking that already ties to this reservation via
    // assigned_booking_id.
    $room = \App\Models\Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->catStandard, 'category')->create();
    $booking = \App\Models\Booking::factory()->for($this->tenant)->for($room)->create();
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id'  => $this->property->id,
        'starts_at'    => Carbon::today()->addDay(),
        'ends_at'      => Carbon::today()->addDays(3),
        'status'       => CorporateReservationStatus::Pending->value,
    ]);
    CorporateReservationCategory::query()->create([
        'corporate_reservation_id' => $r->id,
        'room_category_id' => $this->catStandard->id,
        'rooms_count' => 1,
    ]);
    CorporateReservationMember::query()->create([
        'corporate_reservation_id' => $r->id,
        'corporate_member_id' => $this->m1->id,
        'assigned_booking_id' => $booking->id,
        'assigned_at' => Carbon::now(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('editReservation', $r->id)
        ->call('toggleReservationMember', $this->m1->id)   // drop m1
        ->call('toggleReservationMember', $this->m2->id)   // add m2
        ->call('saveReservation');

    // m1 preserved (had an assigned booking), m2 added.
    $memberIds = CorporateReservationMember::query()
        ->where('corporate_reservation_id', $r->id)
        ->pluck('corporate_member_id')
        ->sort()->values()->all();
    expect($memberIds)->toBe(collect([$this->m1->id, $this->m2->id])->sort()->values()->all());
});

it('refuses to open the edit form for a Cancelled reservation', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id'  => $this->property->id,
        'starts_at'    => Carbon::today()->subDays(3),
        'ends_at'      => Carbon::today()->subDay(),
        'status'       => CorporateReservationStatus::Cancelled->value,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('editReservation', $r->id)
        ->assertSet('reservationFormOpen', false)
        ->assertSet('editingReservationId', null)
        ->assertDispatched('notify');
});

it('cancel flips a reservation to Cancelled', function (): void {
    $r = CorporateReservation::query()->create([
        'corporate_id' => $this->corp->id,
        'property_id'  => $this->property->id,
        'starts_at'    => Carbon::today()->addDay(),
        'ends_at'      => Carbon::today()->addDays(3),
        'status'       => CorporateReservationStatus::Pending->value,
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('cancelReservation', $r->id);

    expect($r->refresh()->status)->toBe(CorporateReservationStatus::Cancelled);
});

it('add + remove category row helpers work', function (): void {
    Livewire::actingAs($this->manager, 'web')
        ->test(CorpShow::class, ['corporate' => $this->corp])
        ->call('newReservation')
        ->assertCount('reservationCategoryRows', 1)
        ->call('addReservationCategoryRow')
        ->assertCount('reservationCategoryRows', 2)
        ->call('addReservationCategoryRow')
        ->assertCount('reservationCategoryRows', 3)
        ->call('removeReservationCategoryRow', 1)
        ->assertCount('reservationCategoryRows', 2);
});
