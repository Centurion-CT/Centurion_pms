<?php

declare(strict_types=1);

use App\Actions\Approvals\AlreadyDecidedException;
use App\Actions\Approvals\DecideGuestActionApproval;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Mail\Bookings\DepositKeptMail;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\Bookings\HeldMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/HostTestHelpers.php';

/**
 * Host app phase 3: inbox counts + approvals (19) + decide, for the
 * three approval kinds. Ikoyi (current branch) + Abuja (same tenant)
 * + another tenant never leak.
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));
    Cache::flush();
    Mail::fake();

    $this->tenant = Tenant::factory()->create(['slug' => 'host-inbox']);
    $this->tenant->makeCurrent();

    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 3, '1');
    $this->property = $ikoyi['property'];
    $this->category = $ikoyi['category'];
    $this->rooms = $ikoyi['rooms'];
    $abuja = hostBranch($this->tenant, 'Abuja', 1, '9');
    $this->abujaRoom = $abuja['rooms'][0];
    $this->abujaCategory = $abuja['category'];

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze']);
    foreach ([$this->manager, $this->frontdesk] as $op) {
        $op->forceFill(['current_property_id' => $this->property->id])->save();
    }

});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
    Cache::flush();
});

function inboxPortal(object $t, $room, string $kind = 'request:taxi', array $payload = [], array $booking = []): GuestActionApproval
{
    $b = hostBooking($room, array_merge(['guest_name' => 'Amaka Bello', 'booking_status' => BookingStatus::CheckedIn], $booking));

    return GuestActionApproval::query()->create([
        'tenant_id' => $t->tenant->id, 'booking_id' => $b->id, 'room_id' => $room->id,
        'kind' => $kind, 'payload' => $payload ?: ['kind' => 'taxi', 'notes' => 'Airport at 6pm'],
        'coord_score' => 20, 'status' => 'pending',
    ]);
}

function inboxHeld(object $t, $room, int $paid = 50_000, BookingStatus $status = BookingStatus::Cancelled): Booking
{
    $b = hostBooking($room, ['guest_name' => 'Zainab Yusuf', 'guest_email' => 'z@example.com', 'booking_status' => $status]);
    Payment::factory()->for($t->tenant)->succeeded()->create([
        'booking_id' => $b->id, 'amount' => $paid, 'type' => PaymentType::Full, 'paid_at' => Carbon::now()->subDay(),
    ]);

    return $b;
}

function inboxDecide(object $t, Operator $op, string $id, array $body)
{
    return test()->postJson(hostUrl($t->tenant, 'inbox/approvals/'.$id.'/decide'), $body, hostHeaders($op) + hostWriteKey());
}

// ── List + counts ────────────────────────────────────────────────

it('lists every approval kind for the branch, newest first, with server actions', function (): void {
    $portal = inboxPortal($this, $this->rooms[0]);
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:05:00'));
    $held = inboxHeld($this, $this->rooms[1]);
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:10:00'));
    // Noise: Abuja branch items and decided items never show.
    inboxPortal($this, $this->abujaRoom);
    inboxHeld($this, $this->abujaRoom);
    inboxPortal($this, $this->rooms[2])->forceFill(['status' => 'approved'])->save();

    $items = collect($this->getJson(hostUrl($this->tenant, 'inbox/approvals'), hostHeaders($this->manager))->assertOk()->json('data'));

    expect($items->pluck('id')->all())->toEqualCanonicalizing([
        'portal_action:'.$portal->id, 'held_money:'.$held->id,
    ])->and($items->last()['id'])->toBe('portal_action:'.$portal->id);

    $byId = $items->keyBy('id');
    expect($byId['portal_action:'.$portal->id])->toMatchArray([
        'kind' => 'portal_action', 'title' => 'Guest request: taxi request', 'guest_name' => 'Amaka Bello',
    ])->and(collect($byId['portal_action:'.$portal->id]['actions'])->pluck('value')->all())->toBe(['approve', 'reject']);

    $heldItem = $byId['held_money:'.$held->id];
    expect($heldItem['amount'])->toBe(50_000)
        ->and($heldItem['booking_ref'])->toBe($held->booking_ref)
        ->and($heldItem['actions'][0])->toMatchArray(['value' => 'refund', 'needs_amount' => true, 'needs_method' => true, 'max_amount' => 50_000])
        ->and(collect($heldItem['actions'][0]['methods'])->pluck('value')->all())->toBe(['cash', 'transfer', 'card', 'paystack', 'other'])
        ->and($heldItem['actions'][1])->toMatchArray(['value' => 'keep', 'needs_reason' => true]);
});

it('counts the badge sources for the branch', function (): void {
    inboxPortal($this, $this->rooms[0]);
    inboxHeld($this, $this->rooms[1]);
    inboxPortal($this, $this->abujaRoom);
    $b = hostBooking($this->rooms[2]);
    foreach ([GuestRequestKind::Complaint, GuestRequestKind::Taxi] as $kind) {
        GuestRequest::query()->create(['tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[2]->id, 'kind' => $kind->value, 'status' => 'open']);
    }
    GuestRequest::query()->create(['tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[2]->id, 'kind' => 'order', 'status' => 'resolved']);

    $this->getJson(hostUrl($this->tenant, 'inbox/counts'), hostHeaders($this->manager))
        ->assertOk()
        ->assertJsonPath('data.approvals', 2)
        ->assertJsonPath('data.requests_open', 2)
        ->assertJsonPath('data.requests_urgent', 1);

    // Front desk sees the same two queues.
    $this->getJson(hostUrl($this->tenant, 'inbox/counts'), hostHeaders($this->frontdesk))
        ->assertOk()->assertJsonPath('data.approvals', 2);
    expect($this->getJson(hostUrl($this->tenant, 'inbox/counts'), hostHeaders($this->frontdesk))->json('data.notifications_unread'))->toBeGreaterThan(0);
});

// ── portal_action ────────────────────────────────────────────────

it('approves a held portal action by running its payload, then 409s a second decision', function (): void {
    $a = inboxPortal($this, $this->rooms[0]);

    inboxDecide($this, $this->frontdesk, 'portal_action:'.$a->id, ['action' => 'approve'])
        ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('item', null);

    expect($a->fresh()->status)->toBe('approved')
        ->and($a->fresh()->approved_by_operator_id)->toBe($this->frontdesk->id);
    $req = GuestRequest::query()->sole();
    expect($req->kind)->toBe(GuestRequestKind::Taxi)->and($req->notes)->toBe('Airport at 6pm');

    inboxDecide($this, $this->manager, 'portal_action:'.$a->id, ['action' => 'reject', 'reason' => 'Too late'])
        ->assertStatus(409)->assertJsonPath('error', 'already_decided');
    expect(GuestRequest::query()->count())->toBe(1);
});

it('rejects a portal action with the reason as the operator note', function (): void {
    $a = inboxPortal($this, $this->rooms[0]);

    inboxDecide($this, $this->manager, 'portal_action:'.$a->id, ['action' => 'reject', 'reason' => 'Guest not reachable'])->assertOk();

    expect($a->fresh())->status->toBe('rejected')->operator_note->toBe('Guest not reachable');
    expect(GuestRequest::query()->count())->toBe(0);
});

it('returns 422 and keeps the approval pending when the payload fails', function (): void {
    // check-in without payment needs a Pending booking; this one is checked in.
    $a = inboxPortal($this, $this->rooms[0], 'checkin_no_payment', ['requested_by_operator_name' => 'Ngozi Eze', 'total_amount' => 60_000]);

    inboxDecide($this, $this->manager, 'portal_action:'.$a->id, ['action' => 'approve'])
        ->assertStatus(422)->assertJsonPath('error', 'decision_failed');
    expect($a->fresh()->status)->toBe('pending');

    inboxDecide($this, $this->manager, 'portal_action:'.$a->id, ['action' => 'shrug'])
        ->assertStatus(422)->assertJsonPath('error', 'decision_failed');
});

it('approves check-in without payment exactly like the web queue', function (): void {
    $a = inboxPortal($this, $this->rooms[0], 'checkin_no_payment', ['requested_by_operator_name' => 'Ngozi Eze', 'total_amount' => 60_000], [
        'booking_status' => BookingStatus::Pending,
    ]);

    $this->getJson(hostUrl($this->tenant, 'inbox/approvals'), hostHeaders($this->manager))
        ->assertJsonPath('data.0.title', 'Check in without payment')
        ->assertJsonPath('data.0.requested_by', 'Ngozi Eze')
        ->assertJsonPath('data.0.amount', 60_000);

    inboxDecide($this, $this->manager, 'portal_action:'.$a->id, ['action' => 'approve'])->assertOk();
    expect(Booking::query()->find($a->booking_id)->booking_status)->toBe(BookingStatus::Confirmed);
});

it('is race-safe: a stale approval loaded as pending throws already decided', function (): void {
    $a = inboxPortal($this, $this->rooms[0]);
    $stale = GuestActionApproval::query()->find($a->id);
    // Someone else decides between our read and our click.
    app(DecideGuestActionApproval::class)->reject($a, $this->manager, 'first');

    expect(fn () => app(DecideGuestActionApproval::class)->approve($stale, $this->frontdesk))
        ->toThrow(AlreadyDecidedException::class);
    expect(GuestRequest::query()->count())->toBe(0)
        ->and($a->fresh()->operator_note)->toBe('first');
});

it('never decides another branch or tenant approval', function (): void {
    $abuja = inboxPortal($this, $this->abujaRoom);
    inboxDecide($this, $this->manager, 'portal_action:'.$abuja->id, ['action' => 'approve'])->assertStatus(404);

    $other = Tenant::factory()->create(['slug' => 'host-inbox-other']);
    $other->makeCurrent();
    $o = hostBranch($other, 'Other', 1, '5');
    $ob = hostBooking($o['rooms'][0]);
    $foreign = GuestActionApproval::query()->create([
        'tenant_id' => $other->id, 'booking_id' => $ob->id, 'room_id' => $o['rooms'][0]->id,
        'kind' => 'checkout', 'payload' => [], 'coord_score' => 0, 'status' => 'pending',
    ]);
    $this->tenant->makeCurrent();

    inboxDecide($this, $this->manager, 'portal_action:'.$foreign->id, ['action' => 'approve'])->assertStatus(404);
    inboxDecide($this, $this->manager, 'nonsense:1', ['action' => 'approve'])->assertStatus(404);
    $this->tenant->makeCurrent();
    expect(GuestActionApproval::query()->withoutGlobalScopes()->find($foreign->id)->status)->toBe('pending')
        ->and($abuja->fresh()->status)->toBe('pending');
});

// ── held_money ───────────────────────────────────────────────────

it('refunds held money partially then fully, then 409s', function (): void {
    $b = inboxHeld($this, $this->rooms[0], 50_000);

    $res = inboxDecide($this, $this->frontdesk, 'held_money:'.$b->id, ['action' => 'refund', 'amount' => 20_000, 'method' => 'paystack', 'note' => 'Partial'])
        ->assertOk()
        ->assertJsonPath('item.id', 'held_money:'.$b->id)
        ->assertJsonPath('item.amount', 30_000);

    $refund = Payment::query()->where('booking_id', $b->id)->where('status', PaymentStatus::Refunded)->sole();
    expect((int) $refund->amount)->toBe(20_000)->and($refund->method)->toBe('paystack');

    inboxDecide($this, $this->frontdesk, 'held_money:'.$b->id, ['action' => 'refund', 'amount' => 40_000, 'method' => 'cash'])
        ->assertStatus(422)->assertJsonPath('error', 'decision_failed');
    inboxDecide($this, $this->frontdesk, 'held_money:'.$b->id, ['action' => 'refund', 'amount' => 10_000, 'method' => 'pos'])
        ->assertStatus(422);

    inboxDecide($this, $this->frontdesk, 'held_money:'.$b->id, ['action' => 'refund', 'amount' => 30_000, 'method' => 'cash'])
        ->assertOk()->assertJsonPath('item', null);
    expect(app(HeldMoney::class)->heldFor($b))->toBe(0);

    inboxDecide($this, $this->frontdesk, 'held_money:'.$b->id, ['action' => 'keep', 'reason' => 'Late'])
        ->assertStatus(409)->assertJsonPath('error', 'already_decided');
});

it('keeps held money as forfeit with a reason (whole amount by default)', function (): void {
    $b = inboxHeld($this, $this->rooms[0], 45_000, BookingStatus::NoShow);

    inboxDecide($this, $this->manager, 'held_money:'.$b->id, ['action' => 'keep'])
        ->assertStatus(422)->assertJsonPath('error', 'decision_failed');

    inboxDecide($this, $this->manager, 'held_money:'.$b->id, ['action' => 'keep', 'reason' => 'No-show, non refundable'])
        ->assertOk()->assertJsonPath('item', null);

    $charge = BookingCharge::query()->where('booking_id', $b->id)->sole();
    expect($charge->type)->toBe(BookingChargeType::DepositForfeit)
        ->and((int) $charge->amount)->toBe(45_000)
        ->and($charge->settled_method)->toBe(HeldMoney::FORFEIT_METHOD);
    Mail::assertQueued(DepositKeptMail::class);
});

it('does not decide held money on another branch', function (): void {
    $b = inboxHeld($this, $this->abujaRoom);
    inboxDecide($this, $this->manager, 'held_money:'.$b->id, ['action' => 'refund', 'amount' => 1_000, 'method' => 'cash'])->assertStatus(404);
    expect(app(HeldMoney::class)->heldFor($b))->toBe(50_000);
});
