<?php

declare(strict_types=1);

use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Livewire\Dashboard\GuestRequests\Index as GuestRequestsIndex;
use App\Livewire\GuestPortal\RoomPortal;
use App\Models\Booking;
use App\Models\GuestRequest;
use App\Models\GuestRequestMessage;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Detail panel + operator ↔ guest messaging on guest requests.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'gr-messages']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Deluxe Suite', 'base_rate' => 40_000,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'name' => 'Ada Desk',
    ]);
    $this->actingAs($this->operator, 'web');

    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Ifeanyi Guest',
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function transferRequest(): GuestRequest
{
    $target = RoomCategory::factory()->for(test()->tenant)->for(test()->property)->create([
        'name' => 'Bigger Suite',
        'base_rate' => 60_000,
    ]);

    return GuestRequest::query()->create([
        'tenant_id'  => test()->tenant->id,
        'booking_id' => test()->booking->id,
        'room_id'    => test()->room->id,
        'kind'       => GuestRequestKind::Transfer->value,
        'status'     => 'open',
        'notes'      => 'Room too small',
        'body'       => ['target_category_id' => $target->id],
    ]);
}

it('list view resolves target_category_id to the category name', function (): void {
    transferRequest(); // creates a request with body.target_category_id = X

    Livewire::test(GuestRequestsIndex::class)
        ->assertSee('Bigger Suite')
        ->assertDontSee('target_category_id');
});

it('openRequest loads the panel with request + messages', function (): void {
    $req = transferRequest();
    // Seed a prior operator message so the thread renders.
    GuestRequestMessage::query()->create([
        'tenant_id' => $this->tenant->id,
        'guest_request_id' => $req->id,
        'sender_type' => 'operator',
        'sender_operator_id' => $this->operator->id,
        'message' => 'On the way.',
    ]);

    Livewire::test(GuestRequestsIndex::class)
        ->call('openRequest', $req->id)
        ->assertSet('selectedRequestId', $req->id)
        ->assertSee('Ifeanyi Guest')
        ->assertSee('Room too small')
        ->assertSee('On the way.');
});

it('sendMessage writes an operator message + clears the compose box', function (): void {
    $req = transferRequest();

    Livewire::test(GuestRequestsIndex::class)
        ->call('openRequest', $req->id)
        ->set('newMessage', 'We have a bigger room ready.')
        ->call('sendMessage')
        ->assertSet('newMessage', '');

    $m = GuestRequestMessage::query()->where('guest_request_id', $req->id)->latest('id')->first();
    expect($m)->not->toBeNull();
    expect($m->message)->toBe('We have a bigger room ready.');
    expect($m->sender_type)->toBe('operator');
    expect($m->sender_operator_id)->toBe($this->operator->id);
});

it('sendMessage no-ops on empty input or no selected request', function (): void {
    Livewire::test(GuestRequestsIndex::class)
        ->set('newMessage', '   ')
        ->call('sendMessage');
    expect(GuestRequestMessage::count())->toBe(0);
});

it('guest self-portal My-requests tile surfaces operator messages', function (): void {
    $req = transferRequest();
    GuestRequestMessage::query()->create([
        'tenant_id' => $this->tenant->id,
        'guest_request_id' => $req->id,
        'sender_type' => 'operator',
        'sender_operator_id' => $this->operator->id,
        'message' => 'Bigger room is ready — please head to reception.',
    ]);

    // Verify as the guest.
    session()->put("guest_portal.verified.{$this->room->id}", $this->booking->id);

    // Flip the booking to CheckedIn so the portal renders the tiles.
    $this->booking->forceFill(['booking_status' => \App\Enums\BookingStatus::CheckedIn])->save();

    Livewire::test(RoomPortal::class, ['qrSlug' => $this->room->qr_slug])
        ->call('openTile', 'requests')
        ->assertSee('Bigger room is ready — please head to reception.');
});
