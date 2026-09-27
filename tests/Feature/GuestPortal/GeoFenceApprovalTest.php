<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\Approvals\Index as ApprovalsIndex;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Geo-fence + coord-score gate for the guest self-service portal.
 * Verifies: score maths at the property pin / at the edge / beyond;
 * the gate holds actions below threshold and passes them above;
 * the operator queue approves the held action into the real
 * underlying booking mutation.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gf-test']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    // Fence-enabled property. Standard preset: 200m radius, min score 60.
    $this->property = Property::factory()->for($this->tenant)->create([
        'name' => 'Fenced Hotel',
        'latitude' => 6.5244,
        'longitude' => 3.3792,
        'geo_fence_radius_meters' => 200,
        'guest_coord_min_score' => 60,
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Std', 'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Amara Okafor',
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
        'total_amount' => 40_000,
        'room_total' => 40_000,
        'balance_due' => 0,
        'nights' => 2,
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('scores a guest at the property pin as 100', function (): void {
    $score = $this->property->computeGuestCoordScore(6.5244, 3.3792);
    expect($score)->toBe(100);
});

it('scores a guest at the fence edge as 0', function (): void {
    // 200m ≈ 0.0018 degrees latitude - push far enough to be past
    // the fence, not right on the edge (where rounding fights us).
    $score = $this->property->computeGuestCoordScore(6.5244 + 0.005, 3.3792);
    expect($score)->toBe(0);
});

it('scores a guest partway through the fence proportionally', function (): void {
    // ~50m out of a 200m fence - should score around 75.
    $score = $this->property->computeGuestCoordScore(6.5244 + 0.00045, 3.3792);
    expect($score)->toBeGreaterThan(65)
        ->and($score)->toBeLessThan(85);
});

it('portal gate holds an extend below the score threshold', function (): void {
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
    // Score 0 → below min 60 → queue.
    session()->put("guest_portal.coords.{$this->property->id}", [
        'lat' => null, 'lng' => null, 'score' => 0, 'distance' => null,
    ]);

    $originalCheckout = $this->booking->checkout_date->toDateString();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        ->set('extendCheckoutDate', Carbon::today()->addDays(3)->toDateString())
        ->call('submitExtend')
        ->assertSet('step', 'home');

    // Booking untouched, approval queued.
    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($originalCheckout);
    expect(GuestActionApproval::query()->where('kind', 'extend')->where('status', 'pending')->count())->toBe(1);
});

it('portal gate lets an extend through when the score meets the threshold', function (): void {
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
    session()->put("guest_portal.coords.{$this->property->id}", [
        'lat' => 6.5244, 'lng' => 3.3792, 'score' => 100, 'distance' => 0,
    ]);

    $newCheckout = Carbon::today()->addDays(3)->toDateString();
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        ->set('extendCheckoutDate', $newCheckout)
        ->call('submitExtend')
        ->assertSet('step', 'home');

    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($newCheckout);
    expect(GuestActionApproval::query()->count())->toBe(0);
});

it('portal gate is a no-op when the property has fence disabled', function (): void {
    // Off preset: fence=0 disables the gate regardless of score.
    $this->property->forceFill([
        'geo_fence_radius_meters' => 0,
        'guest_coord_min_score' => 0,
    ])->save();

    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
    // No coords in session at all - with the gate off, still passes.

    $newCheckout = Carbon::today()->addDays(3)->toDateString();
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        ->set('extendCheckoutDate', $newCheckout)
        ->call('submitExtend')
        ->assertSet('step', 'home');

    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($newCheckout);
    expect(GuestActionApproval::query()->count())->toBe(0);
});

it('portal gate queues a guest request when the score is below threshold', function (): void {
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
    session()->put("guest_portal.coords.{$this->property->id}", [
        'lat' => null, 'lng' => null, 'score' => 0, 'distance' => null,
    ]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'complaint')
        ->set('requestNotes', 'AC is too cold')
        ->call('submitRequest', 'complaint')
        ->assertSet('step', 'home');

    // No open GuestRequest - it's in the approval queue instead.
    expect(GuestRequest::query()->count())->toBe(0);
    $approval = GuestActionApproval::query()->firstOrFail();
    expect($approval->kind)->toBe('request:complaint');
    expect($approval->status)->toBe('pending');
});

it('operator approve on the queue runs the underlying extend', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($operator, 'web');

    $newCheckout = Carbon::today()->addDays(3)->toDateString();
    $approval = GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'room_id' => $this->room->id,
        'kind' => 'extend',
        'payload' => ['new_checkout_date' => $newCheckout, 'breakfast_included' => false],
        'coord_score' => 20,
        'status' => 'pending',
    ]);

    Livewire::test(ApprovalsIndex::class)
        ->call('approve', $approval->id);

    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($newCheckout);
    expect($approval->fresh()->status)->toBe('approved');
});

it('operator reject leaves the booking untouched and records the note', function (): void {
    $operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
    $this->actingAs($operator, 'web');

    $original = $this->booking->checkout_date->toDateString();
    $approval = GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'room_id' => $this->room->id,
        'kind' => 'extend',
        'payload' => ['new_checkout_date' => Carbon::today()->addDays(3)->toDateString(), 'breakfast_included' => false],
        'coord_score' => 20,
        'status' => 'pending',
    ]);

    Livewire::test(ApprovalsIndex::class)
        ->set('rejectNote', "Couldn't reach guest by phone")
        ->call('reject', $approval->id);

    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($original);
    expect($approval->fresh()->status)->toBe('rejected');
    expect($approval->fresh()->operator_note)->toBe("Couldn't reach guest by phone");
});
