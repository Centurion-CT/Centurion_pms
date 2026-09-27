<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\CorporateMemberStatus;
use App\Enums\CorporateStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Approvals\Index as ApprovalsIndex;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Mail\Approvals\CheckinApprovalRequestedMail;
use App\Models\Booking;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\GuestActionApproval;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Two check-in flows that skip the "collect payment first" gate:
 *   1. Approval-gated: front-desk requests, manager approves in the
 *      Approvals module, booking flips Pending → Confirmed → CheckedIn
 *      atomically and the outstanding lands on the folio.
 *   2. Corporate-guaranteed: booking already ties to an Active
 *      Corporate + Active CorporateMember, so front-desk can check in
 *      directly with no manager in the loop.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'checkin-approvals']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 50_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '207']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk, 'email' => 'fd@example.com',
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager, 'email' => 'mgr@example.com',
    ]);
    Carbon::setTestNow(Carbon::create(2026, 9, 12, 10, 0, 0));
    Mail::fake();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

// ── Approval-gated flow ──────────────────────────────────────────

it('front desk requests approval — creates the GuestActionApproval row', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'guest_name' => 'Micheal Corp',
        'total_amount' => 300_000, 'balance_due' => 0,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDays(6),
    ]);

    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('requestCheckinWithoutPayment')
        ->assertDispatched('notify');

    $approval = GuestActionApproval::query()->where('booking_id', $b->id)->first();
    expect($approval)->not->toBeNull();
    expect($approval->kind)->toBe('checkin_no_payment');
    expect($approval->status)->toBe('pending');
    expect($approval->room_id)->toBe($this->room->id);
    expect($approval->payload['total_amount'] ?? null)->toBe(300_000);

    // Manager gets the request email.
    Mail::assertQueued(CheckinApprovalRequestedMail::class);
});

it('approval mail points at the tenant subdomain, not the central host', function (): void {
    // Regression: route('tenant.approvals.index') resolves to the central
    // domain when the mailable renders on a queue worker with no tenant
    // bound. Recipient would land on "property not found". Fix scopes
    // the URL to $booking->tenant->primaryHost() explicitly.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'total_amount' => 100_000,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    $approval = GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b->id,
        'requester_operator_id' => $this->frontdesk->id,
        'kind' => 'checkin_no_payment',
        'status' => 'pending',
        'payload' => [],
        'room_id' => $this->room->id,
    ]);

    // Render OFF the tenant context — mirrors the queue worker.
    Tenant::forgetCurrent();

    $mail = new CheckinApprovalRequestedMail(
        booking: $b->fresh(['tenant', 'room']),
        approval: $approval,
        requester: $this->frontdesk,
    );
    $rendered = $mail->render();

    expect($rendered)->toContain('https://'.$this->tenant->primaryHost().'/dashboard/approvals');
});

it('refuses when a request is already pending on the same booking', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'total_amount' => 100_000,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('requestCheckinWithoutPayment');

    // Second attempt should be blocked, single approval row only.
    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('requestCheckinWithoutPayment')
        ->assertDispatched('notify');

    expect(GuestActionApproval::query()->where('booking_id', $b->id)->count())->toBe(1);
});

it('approval unlocks the booking: Pending → Confirmed with balance_due = total, front desk still runs the actual check-in', function (): void {
    // Behavior change: manager approval no longer forces MarkCheckedIn.
    // That path failed whenever the room hadn't cleared housekeeping,
    // which is common at the time approval is requested. Now approval
    // just moves the booking to Confirmed with the total on the folio,
    // drops a note in the front-desk queue, and hands off. The desk
    // runs the standard Check in once inspection signs off.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'guest_name' => 'Approve Me',
        'total_amount' => 274_500, 'balance_due' => 0,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDays(6),
    ]);
    $approval = GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $b->id,
        'room_id' => $this->room->id,
        'kind' => 'checkin_no_payment',
        'payload' => ['total_amount' => 274_500],
        'coord_score' => 0, 'guest_latitude' => 0, 'guest_longitude' => 0, 'distance_meters' => 0,
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ApprovalsIndex::class)
        ->call('approve', $approval->id)
        ->assertDispatched('notify');

    $b->refresh();
    expect($b->booking_status)->toBe(BookingStatus::Confirmed);
    expect((int) $b->balance_due)->toBe(274_500);
    expect($approval->refresh()->status)->toBe('approved');

    // Front-desk-visible note landed on the queue.
    $note = \App\Models\GuestRequest::query()
        ->where('booking_id', $b->id)
        ->latest('id')
        ->first();
    expect($note)->not->toBeNull();
    expect($note->kind)->toBe(\App\Enums\GuestRequestKind::Custom);
    expect($note->status)->toBe('open');
    expect((array) $note->body)->toMatchArray(['source' => 'checkin_no_payment_approval']);
});

it('reject leaves the booking Pending', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'total_amount' => 100_000,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);
    $approval = GuestActionApproval::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'room_id' => $this->room->id,
        'kind' => 'checkin_no_payment', 'payload' => [], 'coord_score' => 0,
        'guest_latitude' => 0, 'guest_longitude' => 0, 'distance_meters' => 0, 'status' => 'pending',
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ApprovalsIndex::class)
        ->call('reject', $approval->id);

    expect($b->refresh()->booking_status)->toBe(BookingStatus::Pending);
    expect($approval->refresh()->status)->toBe('rejected');
});

// ── Corporate-guaranteed flow ────────────────────────────────────

it('corporate direct check-in: Pending → CheckedIn without any approval', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
    ]);
    $member = CorporateMember::query()->create([
        'corporate_id' => $corp->id,
        'name' => 'Acme Traveller',
        'email' => 'travel@acme.test',
        'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'guest_name' => 'Acme Traveller',
        'total_amount' => 150_000, 'balance_due' => 0,
        'corporate_id' => $corp->id,
        'corporate_member_id' => $member->id,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('checkInAsCorporate')
        ->assertDispatched('notify');

    $b->refresh();
    expect($b->booking_status)->toBe(BookingStatus::CheckedIn);
    // Outstanding = total, sits on folio until corp settles.
    expect((int) $b->balance_due)->toBe(150_000);

    // No approval was created.
    expect(GuestActionApproval::query()->where('booking_id', $b->id)->count())->toBe(0);
});

it('refuses corporate check-in when the member is offboarded', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(), 'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test', 'country' => 'NG',
    ]);
    $member = CorporateMember::query()->create([
        'corporate_id' => $corp->id, 'name' => 'Ex Employee',
        'email' => 'ex@acme.test', 'role' => 'member',
        'status' => CorporateMemberStatus::Offboarded->value,
    ]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'total_amount' => 100_000,
        'corporate_id' => $corp->id,
        'corporate_member_id' => $member->id,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('checkInAsCorporate')
        ->assertDispatched('notify');

    expect($b->refresh()->booking_status)->toBe(BookingStatus::Pending);
});

it('checkout bypasses the outstanding guard for corp-guaranteed bookings', function (): void {
    // Corp-guaranteed guest already checked in, with an outstanding
    // that the corp will settle by invoice. Frontdesk must be able to
    // check them out anyway — blocking would strand the guest at the
    // counter for a bill nobody's paying in person.
    $corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
    ]);
    $member = CorporateMember::query()->create([
        'corporate_id' => $corp->id,
        'name' => 'Acme Traveller',
        'email' => 'travel@acme.test',
        'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Acme Guest',
        'total_amount' => 150_000,
        'balance_due' => 150_000,             // full outstanding
        'corporate_id' => $corp->id,
        'corporate_member_id' => $member->id,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('checkOut')
        ->assertDispatched('notify');

    expect($b->refresh()->booking_status)->toBe(BookingStatus::CheckedOut);
});

it('corp early checkout trims total_amount + balance_due to actual nights', function (): void {
    // 5-night booking at ₦50k/night = ₦250k. Guest is corp-guaranteed
    // (no cash collected). Actual stay: 2 nights. On checkout the
    // total should trim to ₦100k so the invoice only bills what was
    // consumed, not the 3 unused nights.
    $corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(),
        'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test',
        'country' => 'NG',
    ]);
    $member = CorporateMember::query()->create([
        'corporate_id' => $corp->id,
        'name' => 'Corp Guest',
        'email' => 'guest@acme.test',
        'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Corp Guest',
        'nights' => 5,
        'room_total' => 250_000,
        'total_amount' => 250_000,
        'balance_due' => 250_000,          // unpaid — corp invoice later
        'deposit_amount' => 0,
        'corporate_id' => $corp->id,
        'corporate_member_id' => $member->id,
        'checkin_date' => Carbon::today()->subDays(2),
        'checkout_date' => Carbon::today()->addDays(3),   // 3 unused nights ahead
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('checkOut')
        ->assertDispatched('notify');

    $b->refresh();
    expect($b->booking_status)->toBe(BookingStatus::CheckedOut);
    // Trimmed to 2 actual nights × ₦50k = ₦100k.
    expect((int) $b->nights)->toBe(2);
    expect((int) $b->room_total)->toBe(100_000);
    expect((int) $b->total_amount)->toBe(100_000);
    expect((int) $b->balance_due)->toBe(100_000);
    expect($b->checkout_date->toDateString())->toBe(Carbon::today()->toDateString());
});

it('non-corp early checkout leaves total_amount alone (unchanged behaviour)', function (): void {
    // Regression: a paid walk-in leaving early still gets the "keep"
    // resolution — revenue collected for those nights is real, so we
    // do NOT trim total_amount. The refund flow (if any) is separate.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Paid Walkin',
        'nights' => 3,
        'room_total' => 150_000,
        'total_amount' => 150_000,
        'balance_due' => 0,                 // fully paid
        'deposit_amount' => 150_000,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today()->addDays(2),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('checkOut');

    $b->refresh();
    expect($b->booking_status)->toBe(BookingStatus::CheckedOut);
    // Nights trimmed for occupancy math, total kept as revenue.
    expect((int) $b->nights)->toBe(1);
    expect((int) $b->total_amount)->toBe(150_000);
});

it('checkout still refuses on a non-corp booking with outstanding', function (): void {
    // Regression: only corp-authorized stays skip the guard. A regular
    // walk-in with outstanding must still be blocked.
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Walk-in Guest',
        'total_amount' => 100_000,
        'balance_due' => 100_000,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
    ]);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->call('checkOut')
        ->assertDispatched('notify');

    expect($b->refresh()->booking_status)->toBe(BookingStatus::CheckedIn);
});

it('reservation Show surfaces the Corporate direct check-in when pre-authorized', function (): void {
    $corp = Corporate::query()->create([
        'slug' => 'acme-'.uniqid(), 'name' => 'Acme Ltd',
        'status' => CorporateStatus::Active->value,
        'primary_email' => 'ops@acme.test', 'country' => 'NG',
    ]);
    $member = CorporateMember::query()->create([
        'corporate_id' => $corp->id, 'name' => 'Acme Traveller',
        'email' => 't@acme.test', 'role' => 'member',
        'status' => CorporateMemberStatus::Active->value,
    ]);
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'guest_name' => 'Acme Traveller',
        'total_amount' => 150_000,
        'corporate_id' => $corp->id,
        'corporate_member_id' => $member->id,
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->frontdesk, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->assertSee('data-testid="corp-checkin-panel"', false)
        ->assertSee('Corporate guaranteed');
});
