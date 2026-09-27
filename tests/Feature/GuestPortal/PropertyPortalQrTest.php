<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Per-property portal QR — one QR per branch, guest scans + types
 * their room number + phone + PIN. Backwards-compat: the older
 * per-room QR URL (/room/{slug}) still resolves and pre-fills the
 * room number.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'pp-qr']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create(['name' => 'Test Branch']);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 40_000, 'min_nights' => 1,
    ]);
    $this->room205 = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create([
        'room_number' => '205',
    ]);
    $this->room301 = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create([
        'room_number' => '301',
    ]);
    Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);

    $this->booking205 = Booking::factory()->for($this->tenant)->for($this->room205)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Guest 205',
        'guest_phone' => '+2348011111111',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('mounting with the property portal QR does NOT pre-fill a room', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->assertSet('propertyId', $this->property->id)
        ->assertSet('prefillRoomId', null)
        ->assertSet('roomNumber', '');
});

it('property-QR guest enters room number + phone and lands on pin-set', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->set('roomNumber', '205')
        ->set('phone', '08011111111')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_set')
        ->assertSet('roomId', $this->room205->id);
});

it('rejects a room number that does not exist in the property', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->set('roomNumber', '999')
        ->set('phone', '08011111111')
        ->call('submitPhone')
        ->assertSet('step', 'auth.phone')
        ->assertSet('authError', fn (string $e) => str_contains($e, "couldn't find that room"));
});

it('rejects a phone that does not match the room\'s checked-in booking', function (): void {
    // 205 belongs to +234801… — 301 has no booking; a guest typing 301
    // + the 205 phone number won't match.
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->set('roomNumber', '301')
        ->set('phone', '08011111111')
        ->call('submitPhone')
        ->assertSet('step', 'auth.phone')
        ->assertSet('authError', fn (string $e) => str_contains($e, 'active reservation on room 301'));
});

it('trims + normalises the typed room number (spaces / case)', function (): void {
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->property->portal_qr_slug])
        ->set('roomNumber', '  205 ')
        ->set('phone', '08011111111')
        ->call('submitPhone')
        ->assertSet('step', 'auth.pin_set');
});

it('property QR download endpoint streams a PNG', function (): void {
    $manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => now(),
        'phone' => '+2348012345678',
        'phone_verified_at' => now(),
    ]);
    $this->actingAs($manager, 'web');

    $response = $this->get('http://pp-qr.keverna.test/dashboard/branches/'.$this->property->id.'/qr');
    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    expect(strlen($response->getContent()))->toBeGreaterThan(200);
});

it('legacy /room/{qr_slug} route still resolves and pre-fills the room number', function (): void {
    // Same component, mounted with the room's qr_slug instead of the
    // property's portal_qr_slug.
    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room205->qr_slug])
        ->assertSet('propertyId', $this->property->id)
        ->assertSet('prefillRoomId', $this->room205->id)
        ->assertSet('roomNumber', '205');
});
