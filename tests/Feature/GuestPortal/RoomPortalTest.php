<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * In-room QR portal — auth (phone → PIN set/verify), then tiles
 * (extend / checkout / rate / complaint / taxi / transfer / order).
 * Guest-initiated actions need a Manager to attribute to for the
 * audit trail (systemOperator() helper), so every test seeds one.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gp-test']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create(['name' => 'Test Property']);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Std', 'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    // System operator so guest actions (extend, checkout) have someone
    // to attribute activity to.
    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    // A live booking in the room, guest phone in the trad Nigerian format.
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

it('mounts on a valid room qr_slug (legacy) and pre-fills the room', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->assertSet('step', 'auth.phone')
        ->assertSet('propertyId', $this->property->id)
        ->assertSet('prefillRoomId', $this->room->id);
});

it('mounts on a valid property portal_qr_slug and shows the phone + room-number step', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->assertSet('step', 'auth.phone')
        ->assertSet('propertyId', $this->property->id)
        ->assertSet('prefillRoomId', null);
});

it('404s on an unknown qr_slug', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => 'nope-not-real-slug'])
        ->assertStatus(404);
});

it('matches guest phone in any Nigerian format and prompts to set a PIN on first scan', function (): void {
    // Try the "0801..." local format even though the booking stored "+234".
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_set');
});

it('shows a friendly error when phone does not match any active booking on the room', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('phone', '08099999999')
        ->call('submitPhone')
        ->assertSet('step', 'auth.phone')
        ->assertSet('authError', fn (string $e) => str_contains($e, "couldn't find an active reservation"));
});

it('sets a PIN, verifies it, and lands the guest on the portal home', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_set')
        ->set('pin', '4271')
        ->set('pinConfirm', '4271')
        ->call('submitPinSet')
        ->assertSet('step', 'home');
});

it('rejects a mismatched confirmation PIN', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->set('pin', '4271')
        ->set('pinConfirm', '9999')
        ->call('submitPinSet')
        ->assertSet('step', 'auth.pin_set')
        ->assertSet('authError', fn (string $e) => str_contains($e, "don't match"));
});

it('locks after 3 wrong PIN attempts on a returning guest', function (): void {
    // Seed a stored PIN so the flow lands on pin_enter, not pin_set.
    $customer = app(\App\Services\Customers\CustomerLinker::class)
        ->attachToBooking($this->booking->fresh());
    $customer->setPinFor($this->property, '1234');

    $component = Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->set('phone', '08012345678')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_enter');

    foreach ([1, 2, 3] as $attempt) {
        $component->set('pin', '0000')->call('submitPinEnter');
    }

    // After the 3rd wrong PIN, we're bumped back to phone entry.
    $component->assertSet('step', 'auth.phone');
});

it('extend stay tile pushes checkout_date forward', function (): void {
    markAsVerified();

    $newCheckout = Carbon::today()->addDays(3)->toDateString();
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'extend')
        ->set('extendCheckoutDate', $newCheckout)
        ->call('submitExtend')
        ->assertSet('step', 'home');

    expect($this->booking->fresh()->checkout_date->toDateString())->toBe($newCheckout);
});

it('rate tile writes a Review (pending display)', function (): void {
    markAsVerified();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'rate')
        ->set('ratingStars', 4)
        ->set('ratingBody', 'Loved the location')
        ->call('submitRating')
        ->assertSet('step', 'home');

    $review = Review::query()->latest('id')->first();
    expect($review)->not->toBeNull();
    expect((int) $review->rating)->toBe(4);
    expect((string) $review->body)->toBe('Loved the location');
    expect((bool) $review->is_displayed)->toBeFalse();
});

it('complaint tile creates a GuestRequest with kind=complaint', function (): void {
    markAsVerified();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'complaint')
        ->set('requestNotes', 'AC is broken')
        ->call('submitRequest', 'complaint')
        ->assertSet('step', 'home');

    $req = GuestRequest::query()->latest('id')->firstOrFail();
    expect($req->kind->value)->toBe('complaint');
    expect($req->notes)->toBe('AC is broken');
    expect($req->status)->toBe('open');
    expect($req->room_id)->toBe($this->room->id);
});

it('taxi tile records destination + when in the body payload', function (): void {
    markAsVerified();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'taxi')
        ->set('taxiWhen', 'In 30 min')
        ->set('taxiDestination', 'Murtala Airport')
        ->call('submitRequest', 'taxi')
        ->assertSet('step', 'home');

    $req = GuestRequest::query()->where('kind', 'taxi')->latest('id')->firstOrFail();
    expect($req->body['when'])->toBe('In 30 min');
    expect($req->body['destination'])->toBe('Murtala Airport');
});

it('checkout tile requires the confirmation phrase', function (): void {
    markAsVerified();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'checkout')
        ->set('checkoutConfirm', 'nope')
        ->call('submitCheckout')
        ->assertSet('step', 'tile.checkout')
        ->assertSet('authError', fn (string $e) => str_contains($e, 'check out'));

    expect($this->booking->fresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('checkout tile flips the booking + clears the session marker', function (): void {
    markAsVerified();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'checkout')
        ->set('checkoutConfirm', 'check out')
        ->call('submitCheckout')
        ->assertSet('step', 'checkout.done');

    expect($this->booking->fresh()->booking_status)->toBe(BookingStatus::CheckedOut);
    expect(session("guest_portal.verified.{$this->room->id}"))->toBeNull();
});

it('guest can cancel their own open request from the My requests tile', function (): void {
    markAsVerified();

    // Seed one open request on this booking.
    $req = GuestRequest::query()->create([
        'tenant_id'  => $this->tenant->id,
        'booking_id' => $this->booking->id,
        'room_id'    => $this->room->id,
        'kind'       => \App\Enums\GuestRequestKind::Transfer->value,
        'status'     => 'open',
        'notes'      => 'Room too small',
        'body'       => [],
    ]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'requests')
        ->assertSet('step', 'tile.requests')
        ->call('cancelRequest', $req->id);

    expect($req->fresh()->status)->toBe('cancelled');
});

it('cancelRequest silently ignores a request that does not belong to the verified booking', function (): void {
    markAsVerified();

    // Foreign booking on a different tenant → shouldn't be cancellable.
    $otherTenant = Tenant::factory()->create(['slug' => 'other-tenant']);
    $otherReq = GuestRequest::query()->create([
        'tenant_id'  => $otherTenant->id,
        'booking_id' => 99999,
        'room_id'    => $this->room->id,
        'kind'       => \App\Enums\GuestRequestKind::Complaint->value,
        'status'     => 'open',
        'notes'      => 'nope',
        'body'       => [],
    ]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'requests')
        ->call('cancelRequest', $otherReq->id);

    expect($otherReq->fresh()->status)->toBe('open');
});

it('reload on the per-property QR restores the verified session (no re-auth)', function (): void {
    // Simulate the guest having already verified: both session keys
    // set by confirmVerified() — the room-keyed marker + the new
    // property-keyed pointer that lets mount() find the room on a
    // per-property QR without asking the guest again.
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
    session()->put("guest_portal.property.{$this->property->id}", [
        'room_id' => $this->room->id,
        'booking_id' => $this->booking->id,
    ]);

    // Mount using the property's portal_qr_slug (per-property QR),
    // NOT the room's qr_slug — mount() must resolve the room via
    // the property pointer.
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->assertSet('step', 'home')
        ->assertSet('roomId', $this->room->id);
});

it('reload clears a stale property pointer when the booking is no longer CheckedIn', function (): void {
    // Verified session but the booking was checked out out-of-band.
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);
    session()->put("guest_portal.property.{$this->property->id}", [
        'room_id' => $this->room->id,
        'booking_id' => $this->booking->id,
    ]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->assertSet('step', 'auth.phone')
        ->assertSet('roomId', 0);

    expect(session("guest_portal.verified.{$this->room->id}"))->toBeNull();
    expect(session("guest_portal.property.{$this->property->id}"))->toBeNull();
});

it('shows the Where-we-are card with map + copy/share when coordinates are set', function (): void {
    markAsVerified();
    $this->property->update([
        'address' => '10 Marina Rd',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'latitude' => 6.4541,
        'longitude' => 3.3947,
    ]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->assertSee('Where we are')
        ->assertSee('10 Marina Rd, Lagos, Lagos')
        ->assertSeeHtml('data-testid="portal-map-embed"')
        ->assertSeeHtml('data-testid="portal-location-copy"');
});

it('hides the Where-we-are card entirely when address AND coordinates are unset', function (): void {
    markAsVerified();
    $this->property->update([
        'address' => '',
        'city' => null,
        'state' => null,
        'latitude' => null,
        'longitude' => null,
    ]);

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->assertDontSee('Where we are');
});

// ── Helpers ────────────────────────────────────────────────────
function markAsVerified(): void
{
    session()->put("guest_portal.verified.".test()->room->id, test()->booking->id);
}
