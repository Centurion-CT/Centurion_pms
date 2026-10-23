<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Models\Customer;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\GuestRequestMessage;
use App\Models\Operator;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Notifications\BookingActivityNotification;
use App\Services\GuestPortal\GuestNotifier;
use App\Services\Sendpipe\SendpipeClient;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

require_once __DIR__.'/HostTestHelpers.php';

/**
 * Host app phase 3: guest requests (20 / 20b), alerts (21), search (29).
 * Ikoyi (current branch) + Abuja (same tenant) + another tenant.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));

    $this->tenant = Tenant::factory()->create(['slug' => 'host-req']);
    $this->tenant->makeCurrent();

    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 3, '1');
    $this->property = $ikoyi['property'];
    $this->rooms = $ikoyi['rooms'];
    $abuja = hostBranch($this->tenant, 'Abuja', 1, '9');
    $this->abujaRoom = $abuja['rooms'][0];

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze']);
    foreach ([$this->manager, $this->frontdesk] as $op) {
        $op->forceFill(['current_property_id' => $this->property->id, 'last_notifications_seen_at' => Carbon::now()->subHour()])->save();
    }

    // Capture WhatsApp sends.
    $this->sent = new ArrayObject;
    $sent = $this->sent;
    app()->instance(GuestNotifier::class, new class($sent) extends GuestNotifier
    {
        public function __construct(private ArrayObject $out)
        {
            parent::__construct(app(SendpipeClient::class));
        }

        public function sendRequestResolvedNotice(string $phone, string $message): void
        {
            $this->out[] = ['phone' => $phone, 'message' => $message];
        }
    });
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function reqMake(object $t, $room, GuestRequestKind $kind, array $attrs = []): GuestRequest
{
    $b = hostBooking($room, ['guest_name' => 'Amaka Bello', 'guest_phone' => '+2348022222222', 'booking_status' => BookingStatus::CheckedIn]);

    return GuestRequest::query()->create(array_merge([
        'tenant_id' => $t->tenant->id, 'booking_id' => $b->id, 'room_id' => $room->id,
        'kind' => $kind->value, 'status' => 'open', 'notes' => 'The AC is dripping on the bed',
    ], $attrs));
}

// ── Guest requests ───────────────────────────────────────────────

it('lists open guest requests of the branch with counts and kind filter', function (): void {
    $complaint = reqMake($this, $this->rooms[0], GuestRequestKind::Complaint);
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:30:00'));
    $taxi = reqMake($this, $this->rooms[1], GuestRequestKind::Taxi, ['notes' => null]);
    reqMake($this, $this->rooms[2], GuestRequestKind::Order, ['status' => 'resolved', 'resolved_at' => now()]);
    reqMake($this, $this->abujaRoom, GuestRequestKind::Complaint);

    $res = $this->getJson(hostUrl($this->tenant, 'guest-requests'), hostHeaders($this->frontdesk))->assertOk();
    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$taxi->id, $complaint->id])
        ->and($res->json('counts'))->toBe(['open' => 2, 'urgent' => 1, 'resolved_today' => 1])
        ->and($res->json('data.1'))->toMatchArray([
            'kind' => 'complaint', 'kind_label' => 'Complaint', 'urgent' => true, 'status' => 'open',
            'room_number' => '101', 'guest_name' => 'Amaka Bello', 'preview' => 'The AC is dripping on the bed', 'resolved_by' => null,
        ])
        ->and($res->json('data.0.preview'))->toBe('Taxi request');

    $this->getJson(hostUrl($this->tenant, 'guest-requests?kind=taxi'), hostHeaders($this->frontdesk))
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(hostUrl($this->tenant, 'guest-requests?status=resolved'), hostHeaders($this->frontdesk))
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(hostUrl($this->tenant, 'guest-requests?kind=spa'), hostHeaders($this->frontdesk))->assertStatus(422);
});

it('shows the thread with quick replies and resolved details', function (): void {
    $target = RoomCategory::factory()->for($this->tenant)->for($this->property)->create(['name' => 'Bigger Suite']);
    $req = reqMake($this, $this->rooms[0], GuestRequestKind::Transfer, ['body' => ['target_category_id' => $target->id]]);
    GuestRequestMessage::query()->create(['tenant_id' => $this->tenant->id, 'guest_request_id' => $req->id, 'sender_type' => 'guest', 'message' => 'Any update?']);
    GuestRequestMessage::query()->create(['tenant_id' => $this->tenant->id, 'guest_request_id' => $req->id, 'sender_type' => 'operator', 'sender_operator_id' => $this->manager->id, 'message' => 'Checking now']);

    $res = $this->getJson(hostUrl($this->tenant, "guest-requests/{$req->id}"), hostHeaders($this->frontdesk))->assertOk();
    expect($res->json('data.guest_phone'))->toBe('+2348022222222')
        ->and($res->json('data.body'))->toBe('The AC is dripping on the bed')
        ->and($res->json('data.details'))->toBe([['label' => 'Wants', 'value' => 'Bigger Suite']])
        ->and($res->json('data.messages'))->toHaveCount(2)
        ->and($res->json('data.messages.0'))->toMatchArray(['from' => 'guest', 'author' => 'Amaka Bello', 'body' => 'Any update?'])
        ->and($res->json('data.messages.1'))->toMatchArray(['from' => 'staff', 'author' => 'Chidi Okafor'])
        ->and($res->json('data.quick_replies'))->not->toBeEmpty();

    $abuja = reqMake($this, $this->abujaRoom, GuestRequestKind::Taxi);
    $this->getJson(hostUrl($this->tenant, "guest-requests/{$abuja->id}"), hostHeaders($this->frontdesk))->assertStatus(404);
});

it('replies on the portal thread and nudges the guest on WhatsApp', function (): void {
    $req = reqMake($this, $this->rooms[0], GuestRequestKind::Complaint);

    $this->postJson(hostUrl($this->tenant, "guest-requests/{$req->id}/reply"), ['body' => 'Engineer is on the way'], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('request.messages.0.body', 'Engineer is on the way')
        ->assertJsonPath('request.messages.0.from', 'staff')
        ->assertJsonPath('request.messages.0.author', 'Ngozi Eze');

    $m = GuestRequestMessage::query()->sole();
    expect($m->sender_type)->toBe('operator')->and($m->sender_operator_id)->toBe($this->frontdesk->id);
    expect($this->sent->getArrayCopy())->toBe([['phone' => '+2348022222222', 'message' => 'Ikoyi: Engineer is on the way']]);

    $this->postJson(hostUrl($this->tenant, "guest-requests/{$req->id}/reply"), ['body' => '   '], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertStatus(422);
    $abuja = reqMake($this, $this->abujaRoom, GuestRequestKind::Taxi);
    $this->postJson(hostUrl($this->tenant, "guest-requests/{$abuja->id}/reply"), ['body' => 'Hi'], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertStatus(404);
    expect(GuestRequestMessage::query()->count())->toBe(1);
});

it('resolves with the WhatsApp notice and reopens, 409 on repeats', function (): void {
    $req = reqMake($this, $this->rooms[0], GuestRequestKind::Taxi);

    $this->postJson(hostUrl($this->tenant, "guest-requests/{$req->id}/resolve"), [], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertOk()
        ->assertJsonPath('request.status', 'resolved')
        ->assertJsonPath('request.resolved_by', 'Ngozi Eze');
    expect($this->sent->getArrayCopy()[0]['message'])->toBe("Ikoyi: your taxi request has been sorted. Reach out if there's anything else.");

    $this->postJson(hostUrl($this->tenant, "guest-requests/{$req->id}/resolve"), [], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertStatus(409)->assertJsonPath('error', 'already_resolved');

    $this->postJson(hostUrl($this->tenant, "guest-requests/{$req->id}/reopen"), [], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertOk()->assertJsonPath('request.status', 'open')->assertJsonPath('request.resolved_at', null);
    $this->postJson(hostUrl($this->tenant, "guest-requests/{$req->id}/reopen"), [], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertStatus(409)->assertJsonPath('error', 'already_open');
});

it('keeps housekeeping roles out of guest requests', function (): void {
    $cleaner = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::HouseCleaner]);
    $this->getJson(hostUrl($this->tenant, 'guest-requests'), hostHeaders($cleaner))
        ->assertStatus(403)->assertJsonPath('error', 'role_not_supported');
});

// ── Notifications ────────────────────────────────────────────────

it('returns the merged bell feed with read state and marks read', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Kemi Lawal']);
    $this->frontdesk->notify(new BookingActivityNotification($b, 'confirmed', 'New confirmed booking', "Kemi Lawal confirmed {$b->booking_ref}."));
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:10:00'));
    $req = reqMake($this, $this->rooms[1], GuestRequestKind::Taxi);
    reqMake($this, $this->abujaRoom, GuestRequestKind::Complaint); // other branch: not in the feed

    $res = $this->getJson(hostUrl($this->tenant, 'notifications'), hostHeaders($this->frontdesk))->assertOk();
    $items = collect($res->json('data'));
    expect($res->json('unread'))->toBe(2)
        ->and($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['id' => 'request:'.$req->id, 'kind' => 'guest_request', 'icon_kind' => 'request', 'title' => 'Taxi request', 'read' => false])
        ->and($items[1])->toMatchArray(['kind' => 'notification', 'icon_kind' => 'booking', 'title' => 'New confirmed booking', 'booking_ref' => $b->booking_ref, 'read' => false]);

    $dbId = $items[1]['id'];
    $this->postJson(hostUrl($this->tenant, 'notifications/read'), ['ids' => [$dbId, 'request:'.$req->id]], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('unread', 1);
    expect(DatabaseNotification::query()->find($dbId)->read_at)->not->toBeNull();

    $this->postJson(hostUrl($this->tenant, 'notifications/read-all'), [], hostHeaders($this->frontdesk) + hostWriteKey())
        ->assertOk()->assertJsonPath('unread', 0);
    expect($this->frontdesk->fresh()->last_notifications_seen_at->equalTo(Carbon::now()))->toBeTrue();

    $after = $this->getJson(hostUrl($this->tenant, 'notifications'), hostHeaders($this->frontdesk))->assertOk();
    expect($after->json('data'))->toHaveCount(1) // activity cleared, db notification stays (read)
        ->and($after->json('data.0.read'))->toBeTrue();
});

it('never marks another operator notification read and pages database notifications', function (): void {
    $b = hostBooking($this->rooms[0]);
    $this->manager->notify(new BookingActivityNotification($b, 'cancelled', 'Booking cancelled', 'x'));
    $managerNote = DatabaseNotification::query()->where('notifiable_id', $this->manager->id)->sole();

    $this->postJson(hostUrl($this->tenant, 'notifications/read'), ['ids' => [$managerNote->id]], hostHeaders($this->frontdesk) + hostWriteKey())->assertOk();
    expect($managerNote->fresh()->read_at)->toBeNull();

    for ($i = 0; $i < 22; $i++) {
        $this->frontdesk->notify(new BookingActivityNotification($b, 'confirmed', 'N'.$i, 'm'));
    }
    $p1 = $this->getJson(hostUrl($this->tenant, 'notifications'), hostHeaders($this->frontdesk))->assertOk();
    expect($p1->json('next_page'))->toBe(2);
    $p2 = $this->getJson(hostUrl($this->tenant, 'notifications?page=2'), hostHeaders($this->frontdesk))->assertOk();
    expect($p2->json('data'))->toHaveCount(2)->and($p2->json('next_page'))->toBeNull();
});

it('shows pending approvals in the feed like the web bell', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Amaka Bello']);
    GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[0]->id,
        'kind' => 'checkout', 'payload' => [], 'coord_score' => 12, 'status' => 'pending',
    ]);

    $this->getJson(hostUrl($this->tenant, 'notifications'), hostHeaders($this->manager))
        ->assertOk()
        ->assertJsonPath('data.0.icon_kind', 'approval')
        ->assertJsonPath('data.0.booking_ref', $b->booking_ref);
});

// ── Search ───────────────────────────────────────────────────────

it('searches guests, bookings and rooms with the web matching rules, branch scoped', function (): void {
    $customer = Customer::create(['name' => 'Amaka Bello', 'phone' => '+2348033333333', 'lifecycle' => CustomerLifecycle::Active, 'property_id' => $this->property->id]);
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Amaka Bello', 'customer_id' => $customer->id]);
    hostBooking($this->abujaRoom, ['guest_name' => 'Amaka Abuja']);

    $res = $this->getJson(hostUrl($this->tenant, 'search?q=amaka'), hostHeaders($this->frontdesk))->assertOk();
    expect(collect($res->json('data.bookings'))->pluck('ref')->all())->toBe([$b->booking_ref])
        ->and($res->json('data.bookings.0.guest_name'))->toBe('Amaka Bello')
        ->and($res->json('data.bookings.0.chip'))->toHaveKeys(['label', 'tone'])
        ->and($res->json('data.guests.0'))->toMatchArray([
            'customer_id' => $customer->id, 'name' => 'Amaka Bello', 'stays_count' => 1, 'last_ref' => $b->booking_ref,
        ]);

    // Ref prefix (case-insensitive) and room number.
    $this->getJson(hostUrl($this->tenant, 'search?q='.strtolower(substr($b->booking_ref, 0, 6))), hostHeaders($this->frontdesk))
        ->assertOk()->assertJsonPath('data.bookings.0.ref', $b->booking_ref);
    $rooms = $this->getJson(hostUrl($this->tenant, 'search?q=10'), hostHeaders($this->frontdesk))->assertOk()->json('data.rooms');
    expect(collect($rooms)->pluck('number')->all())->toBe(['101', '102', '103'])
        ->and($rooms[0])->toHaveKeys(['id', 'number', 'category', 'tile_state']);
    $this->getJson(hostUrl($this->tenant, 'search?q=90'), hostHeaders($this->frontdesk))->assertOk()->assertJsonCount(0, 'data.rooms');

    // Under 2 characters: empty groups.
    $this->getJson(hostUrl($this->tenant, 'search?q=a'), hostHeaders($this->frontdesk))
        ->assertOk()->assertExactJson(['data' => ['guests' => [], 'bookings' => [], 'rooms' => []]]);
});

it('does not find another tenant in search', function (): void {
    $other = Tenant::factory()->create(['slug' => 'host-req-other']);
    $other->makeCurrent();
    $o = hostBranch($other, 'Other', 1, '1');
    hostBooking($o['rooms'][0], ['guest_name' => 'Zed Foreign']);
    $this->tenant->makeCurrent();

    $this->getJson(hostUrl($this->tenant, 'search?q=zed'), hostHeaders($this->frontdesk))
        ->assertOk()->assertJsonCount(0, 'data.bookings')->assertJsonCount(0, 'data.guests');
});
