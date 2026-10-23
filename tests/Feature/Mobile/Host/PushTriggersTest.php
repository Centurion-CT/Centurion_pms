<?php

declare(strict_types=1);

use App\Actions\Bookings\SettleCharges;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\GuestRequestKind;
use App\Enums\OperatorRole;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\BookingCharge;
use App\Models\GuestActionApproval;
use App\Models\GuestRequest;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Push\HostPush;
use App\Services\Push\NullDriver;
use App\Services\Push\PushDriver;
use App\Services\Push\PushResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PushTestHelpers.php';

/**
 * Host app phase 5 push triggers: who gets what. Thursday 22 Oct 2026,
 * 09:00 UTC (10:00 Lagos, outside quiet hours).
 *
 * Devices (token = name):
 *   mgr       manager, all branches
 *   mgr2      second manager
 *   mgr2-off  second manager's tablet with every pref off
 *   fd        front desk, all branches (not an owner, no inventory)
 *   fd-abuja  front desk limited to Abuja
 *   staff-app manager's staff-app install (app=staff): never
 *   revoked   manager's signed-out phone: never
 *   other     manager of another tenant: never
 */
beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));
    Cache::flush();
    Mail::fake();
    $this->push = fakePush();

    $this->tenant = Tenant::factory()->create(['slug' => 'push-t']);
    $this->tenant->makeCurrent();
    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 3, '1');
    $abuja = hostBranch($this->tenant, 'Abuja', 1, '9');
    $this->ikoyi = $ikoyi['property'];
    $this->abuja = $abuja['property'];
    $this->rooms = $ikoyi['rooms'];
    $this->category = $ikoyi['category'];
    $this->abujaRoom = $abuja['rooms'][0];

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor']);
    $this->manager2 = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Bola Ade']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze']);
    $this->fdAbuja = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Musa Bello']);
    $this->fdAbuja->branches()->sync([$this->abuja->id]);

    pushDevice($this->manager, 'mgr');
    pushDevice($this->manager2, 'mgr2');
    pushDevice($this->manager2, 'mgr2-off', ['push_prefs' => array_fill_keys(HostPush::PREFS, false)]);
    pushDevice($this->frontdesk, 'fd');
    pushDevice($this->fdAbuja, 'fd-abuja');
    pushDevice($this->manager, 'staff-app', ['app' => MobileDevice::APP_STAFF]);
    pushDevice($this->manager, 'revoked', ['revoked_at' => now()]);
    pushDevice($this->manager, 'no-app', ['app' => null]);

    $other = Tenant::factory()->create(['slug' => 'push-other']);
    $other->makeCurrent();
    pushDevice(Operator::factory()->for($other)->create(['role' => OperatorRole::Manager]), 'other');
    $this->tenant->makeCurrent();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
    Cache::flush();
});

// ── bookings ─────────────────────────────────────────────────────

it('pushes a new confirmed booking to the branch staff who can see bookings', function (): void {
    $b = hostBooking($this->rooms[0], [
        'guest_name' => 'Zainab Yusuf', 'source' => 'online',
        'checkin_date' => Carbon::parse('2026-10-24'), 'checkout_date' => Carbon::parse('2026-10-28'), 'nights' => 4,
        'total_amount' => 240_000, 'room_total' => 240_000,
    ]);

    expect($this->push->tokens('bookings'))->toEqualCanonicalizing(['mgr', 'mgr2', 'fd']);
    $m = $this->push->messages('bookings')[0];
    expect($m->title)->toBe('New booking from your website')
        ->and($m->body)->toBe('Zainab Yusuf · Ikoyi Deluxe · 24 to 28 Oct · ₦240,000')
        ->and($m->data())->toBe([
            'kind' => 'bookings', 'target' => 'booking:'.$b->booking_ref, 'property_id' => (string) $this->ikoyi->id,
            'tenant' => 'push-t', 'id' => (string) $b->booking_ref,
        ])
        ->and($m->title.$m->body)->not->toContain('—')
        ->and($m->body)->not->toContain('+234');
});

it('reaches Abuja-only staff for Abuja bookings only', function (): void {
    hostBooking($this->abujaRoom, ['source' => 'walk_in', 'guest_name' => 'Ada Obi']);

    expect($this->push->tokens('bookings'))->toEqualCanonicalizing(['mgr', 'mgr2', 'fd', 'fd-abuja'])
        ->and($this->push->messages('bookings')[0]->title)->toBe('New walk-in booking');
});

it('waits for a pending hold to confirm and pushes once', function (): void {
    $b = hostBooking($this->rooms[0], ['booking_status' => BookingStatus::Pending]);
    expect($this->push->sent)->toBe([]);

    $b->forceFill(['booking_status' => BookingStatus::OnHold])->save();
    $b->forceFill(['booking_status' => BookingStatus::Confirmed])->save();
    $b->forceFill(['booking_status' => BookingStatus::CheckedIn])->save();

    expect($this->push->tokens('bookings'))->toHaveCount(3);
});

// ── payments ─────────────────────────────────────────────────────

it('pushes a desk payment to owners only and never to the person who took it', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Chiamaka Obi', 'balance_due' => 85_000, 'total_amount' => 85_000, 'booking_status' => BookingStatus::OnHold]);
    $this->push->reset();

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 85_000, 'method' => 'transfer'], hostHeaders($this->manager) + hostWriteKey())
        ->assertOk();

    expect($this->push->tokens('payments'))->toBe(['mgr2']);
    $m = $this->push->messages('payments')[0];
    expect($m->title)->toBe('Payment received: ₦85,000')
        ->and($m->body)->toBe('Chiamaka Obi paid by transfer · Room 101')
        ->and($m->target)->toBe('booking:'.$b->booking_ref);
});

it('sends one push for a settle that writes several payment rows', function (): void {
    $b = hostBooking($this->rooms[1], ['guest_name' => 'Tunde Bakare', 'balance_due' => 10_000]);
    foreach ([5_000, 3_000] as $amount) {
        BookingCharge::query()->create([
            'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'type' => BookingChargeType::RoomService->value,
            'description' => 'Room service', 'amount' => $amount, 'settled' => false,
        ]);
    }
    $this->push->reset();

    app(SettleCharges::class)->settleAll($b, $this->frontdesk, 'cash');

    expect(Payment::query()->where('booking_id', $b->id)->count())->toBe(3)
        ->and($this->push->tokens('payments'))->toEqualCanonicalizing(['mgr', 'mgr2'])
        ->and($this->push->messages('payments')[0]->title)->toBe('Payment received: ₦18,000')
        ->and($this->push->messages('payments')[0]->body)->toBe('Tunde Bakare paid in cash · Room 102');
});

it('ignores wallet moves and refunds, and pushes a Paystack payment once when it succeeds', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Kemi Ade']);
    $this->push->reset();

    Payment::factory()->for($this->tenant)->for($b)->succeeded()->create(['amount' => 20_000, 'method' => 'wallet', 'type' => PaymentType::Balance]);
    Payment::factory()->for($this->tenant)->for($b)->create(['amount' => 5_000, 'method' => 'cash', 'status' => PaymentStatus::Refunded]);
    Payment::factory()->for($this->tenant)->for($b)->succeeded()->create(['amount' => 9_000, 'method' => 'cash', 'type' => PaymentType::PosSale]);
    expect($this->push->sent)->toBe([]);

    $online = Payment::factory()->for($this->tenant)->for($b)->create(['amount' => 60_000, 'type' => PaymentType::Full, 'status' => PaymentStatus::Pending]);
    expect($this->push->sent)->toBe([]);
    $online->forceFill(['status' => PaymentStatus::Succeeded, 'paid_at' => now()])->save();
    $online->forceFill(['paid_at' => now()->addMinute()])->save();

    expect($this->push->tokens('payments'))->toEqualCanonicalizing(['mgr', 'mgr2'])
        ->and($this->push->messages('payments')[0]->body)->toBe('Kemi Ade paid online · Room 101');
});

// ── approvals ────────────────────────────────────────────────────

it('pushes a held portal action to approvers of the branch, even at night', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Amaka Bello']);
    $this->push->reset();
    Carbon::setTestNow(Carbon::parse('2026-10-22 22:30:00')); // 23:30 Lagos

    $a = GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[0]->id,
        'kind' => 'checkout', 'payload' => [], 'coord_score' => 20, 'status' => 'pending',
    ]);

    expect($this->push->tokens('approvals'))->toEqualCanonicalizing(['mgr', 'mgr2', 'fd']);
    $m = $this->push->messages('approvals')[0];
    expect($m->title)->toBe('Approval needed: Check out')
        ->and($m->body)->toStartWith('Amaka Bello · Rm 101')
        ->and($m->target)->toBe('approvals')
        ->and($m->id)->toBe('portal_action:'.$a->id)
        ->and($m->urgent)->toBeTrue();
});

it('leaves out the operator who asked for a check-in without payment', function (): void {
    $b = hostBooking($this->rooms[0]);
    $this->push->reset();

    GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[0]->id,
        'kind' => 'checkin_no_payment', 'coord_score' => 100, 'status' => 'pending',
        'payload' => ['requested_by_operator_id' => $this->frontdesk->id, 'requested_by_operator_name' => 'Ngozi Eze'],
    ]);

    expect($this->push->tokens('approvals'))->toEqualCanonicalizing(['mgr', 'mgr2']);
});

it('pushes held money when a paid stay is cancelled', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Efe Johnson']);
    Payment::factory()->for($this->tenant)->for($b)->succeeded()->create(['amount' => 50_000, 'type' => PaymentType::Full, 'method' => 'cash']);
    $this->push->reset();

    $b->forceFill(['booking_status' => BookingStatus::Cancelled])->save();

    expect($this->push->tokens('approvals'))->toEqualCanonicalizing(['mgr', 'mgr2', 'fd'])
        ->and($this->push->messages('approvals')[0]->title)->toBe('Approval needed: ₦50,000 held on a cancelled stay')
        ->and($this->push->messages('approvals')[0]->id)->toBe('held_money:'.$b->id);

    // Nothing held: no push.
    $this->push->reset();
    hostBooking($this->rooms[1])->forceFill(['booking_status' => BookingStatus::Cancelled])->save();
    expect($this->push->tokens('approvals'))->toBe([]);
});


// ── guest requests ───────────────────────────────────────────────

it('pushes a guest request to the branch with the request target', function (): void {
    $b = hostBooking($this->rooms[2], ['guest_name' => 'Amaka Bello']);
    $this->push->reset();

    $r = GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[2]->id,
        'kind' => GuestRequestKind::Complaint, 'status' => 'open', 'notes' => 'The AC is not cooling',
    ]);

    expect($this->push->tokens('guest_requests'))->toEqualCanonicalizing(['mgr', 'mgr2', 'fd']);
    $m = $this->push->messages('guest_requests')[0];
    expect($m->title)->toBe('Complaint from Room 103')
        ->and($m->body)->toBe('Amaka Bello: The AC is not cooling')
        ->and($m->target)->toBe('request:'.$r->id)
        ->and($m->urgent)->toBeTrue();
});

it('keeps quiet at night except for urgent guest requests and approvals', function (): void {
    $b = hostBooking($this->rooms[0]);
    $this->push->reset();
    Carbon::setTestNow(Carbon::parse('2026-10-22 21:30:00')); // 22:30 Lagos

    hostBooking($this->rooms[1]);
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[0]->id,
        'kind' => GuestRequestKind::Taxi, 'status' => 'open',
    ]);
    Payment::factory()->for($this->tenant)->for($b)->succeeded()->create(['amount' => 10_000, 'method' => 'cash', 'type' => PaymentType::Balance]);
    expect($this->push->sent)->toBe([]);

    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->rooms[0]->id,
        'kind' => GuestRequestKind::Complaint, 'status' => 'open',
    ]);
    expect($this->push->tokens('guest_requests'))->toHaveCount(3);

    // 06:59 Lagos still quiet, 07:00 is not.
    $this->push->reset();
    Carbon::setTestNow(Carbon::parse('2026-10-23 05:59:00'));
    hostBooking($this->rooms[2]);
    expect($this->push->sent)->toBe([]);
    Carbon::setTestNow(Carbon::parse('2026-10-23 06:00:00'));
    hostBooking($this->rooms[2], ['checkin_date' => Carbon::parse('2026-11-01'), 'checkout_date' => Carbon::parse('2026-11-02')]);
    expect($this->push->tokens('bookings'))->toHaveCount(3);
});

// ── plumbing ─────────────────────────────────────────────────────

it('clears a dead token and keeps going', function (): void {
    $this->push->results['mgr'] = PushResult::InvalidToken;

    hostBooking($this->rooms[0]);

    expect($this->push->tokens('bookings'))->toEqualCanonicalizing(['mgr', 'mgr2', 'fd'])
        ->and(MobileDevice::query()->where('operator_id', $this->manager->id)->whereNotNull('push_token')->pluck('push_token')->all())
        ->not->toContain('mgr');
});

it('queues nothing when push is off', function (): void {
    app()->instance(PushDriver::class, new NullDriver);
    Queue::fake();

    hostBooking($this->rooms[0]);
    GuestRequest::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => hostBooking($this->rooms[1])->id, 'room_id' => $this->rooms[1]->id,
        'kind' => GuestRequestKind::Complaint, 'status' => 'open',
    ]);

    Queue::assertNothingPushed();
});

it('sends one low-stock push per branch per day to staff with stock access', function (): void {
    // A store that survives tenant switches (the array store is rebuilt
    // by PrefixCacheTask), like redis in production.
    config(['cache.default' => 'database']);
    $bar = Outlet::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->ikoyi->id, 'name' => 'Pool bar', 'type' => OutletType::Bar]);
    foreach (['Heineken', 'Coke 50cl', 'Water', 'Chapman'] as $name) {
        Product::query()->create([
            'tenant_id' => $this->tenant->id, 'outlet_id' => $bar->id, 'name' => $name, 'price' => 1500, 'cost' => 800,
            'track_stock' => true, 'quantity_on_hand' => 2, 'low_stock_threshold' => 5,
        ]);
    }
    Tenant::forgetCurrent();

    $this->artisan('inventory:send-low-stock-digest')->assertSuccessful();

    expect($this->push->tokens('low_stock'))->toEqualCanonicalizing(['mgr', 'mgr2']);
    $m = $this->push->messages('low_stock')[0];
    expect($m->title)->toBe('Low stock at Ikoyi')
        ->and($m->body)->toBe('4 items are running low: Chapman, Coke 50cl, Heineken and 1 more')
        ->and($m->target)->toBe('/dashboard/inventory')
        ->and($m->data()['property_id'])->toBe((string) $this->ikoyi->id);

    $this->push->reset();
    $this->artisan('inventory:send-low-stock-digest')->assertSuccessful();
    expect($this->push->sent)->toBe([]);
});
