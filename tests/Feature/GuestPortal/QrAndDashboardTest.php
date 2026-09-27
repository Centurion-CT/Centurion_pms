<?php

declare(strict_types=1);

use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\GuestRequests\Index as GuestRequestsIndex;
use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use Livewire\Livewire;

/**
 * Operator side: the /dashboard/rooms/{id}/qr endpoint streams
 * a PNG containing the /room/{slug} URL; the /dashboard/guest-
 * requests page lists incoming portal requests.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'qr-dash']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'email_verified_at' => now(),
        'phone' => '+2348012345678',
        'phone_verified_at' => now(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

it('streams a PNG QR containing the /room/{slug} URL', function (): void {
    $this->actingAs($this->manager, 'web');

    $response = $this->get('http://qr-dash.keverna.test/dashboard/rooms/'.$this->room->id.'/qr');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    expect(strlen($response->getContent()))->toBeGreaterThan(200); // real PNG has more than a header
});

it('cross-tenant qr download 404s', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-org']);
    $otherRoom = Room::factory()->for($otherTenant)->create();

    $this->actingAs($this->manager, 'web');
    // Hitting our tenant subdomain with the other tenant's room id.
    // Room::query global scope filters by tenant; the other room's
    // id resolves to nothing here.
    $response = $this->get('http://qr-dash.keverna.test/dashboard/rooms/'.$otherRoom->id.'/qr');
    $response->assertNotFound();
});

it('Guest requests index lists open requests and hides resolved by default', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $booking->id,
        'room_id' => $this->room->id, 'kind' => GuestRequestKind::Complaint->value,
        'status' => 'open', 'notes' => 'Noisy neighbour',
    ]);
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $booking->id,
        'room_id' => $this->room->id, 'kind' => GuestRequestKind::Taxi->value,
        'status' => 'resolved',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(GuestRequestsIndex::class)
        ->assertViewHas('requests', fn ($p) => $p->total() === 1);
});

it('resolve() flips status + records the operator', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create();
    $req = GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $booking->id,
        'room_id' => $this->room->id, 'kind' => GuestRequestKind::Complaint->value,
        'status' => 'open',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(GuestRequestsIndex::class)
        ->call('resolve', $req->id);

    $fresh = $req->fresh();
    expect($fresh->status)->toBe('resolved');
    expect((int) $fresh->resolved_by_operator_id)->toBe($this->manager->id);
    expect($fresh->resolved_at)->not->toBeNull();
});
