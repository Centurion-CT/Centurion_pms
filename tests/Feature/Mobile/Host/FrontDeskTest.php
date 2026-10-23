<?php

declare(strict_types=1);

use App\Actions\Bookings\AddBookingCharge;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomInspection;
use App\Models\Tenant;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

require_once __DIR__.'/HostTestHelpers.php';

/**
 * Host app front desk API (docs/host-app/PHASE-2.md, section B).
 *
 * Ikoyi (current branch, rooms 101-104) + Abuja (same tenant, room 901)
 * + another tenant: Abuja and the other tenant must never leak.
 */
function fdKey(): array
{
    return ['Idempotency-Key' => (string) Str::ulid()];
}

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));

    $this->tenant = Tenant::factory()->create(['slug' => 'host-desk']);
    $this->tenant->makeCurrent();

    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 4, '1');
    $this->property = $ikoyi['property'];
    $this->category = $ikoyi['category'];
    $this->rooms = $ikoyi['rooms'];
    $abuja = hostBranch($this->tenant, 'Abuja', 1, '9');
    $this->abujaRoom = $abuja['rooms'][0];

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor']);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze']);

    $this->book = function (Room $room, array $attrs = []): Booking {
        return Booking::factory()->for($this->tenant)->for($room)->create(array_merge([
            'guest_name' => 'Guest '.Str::random(4),
            'guest_phone' => '+2348011111111',
            'booking_status' => BookingStatus::Confirmed,
            'checkin_date' => Carbon::today(),
            'checkout_date' => Carbon::today()->addDays(2),
            'nights' => 2,
            'room_total' => 60_000,
            'total_amount' => 60_000,
            'balance_due' => 0,
            'source' => 'walk_in',
        ], $attrs));
    };
    $this->h = fn (?Operator $op = null) => hostHeaders($op ?? $this->frontdesk);
    $this->w = fn (?Operator $op = null) => hostHeaders($op ?? $this->frontdesk) + fdKey();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

// ── GET host/bookings ────────────────────────────────────────────

it('lists the arriving segment with counts, scoped to the branch', function (): void {
    $book = $this->book;
    $arr = $book($this->rooms[0], ['guest_name' => 'Amaka Bello', 'balance_due' => 20_000, 'booking_status' => BookingStatus::OnHold]);
    $book($this->rooms[1], ['booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDays(3), 'checkout_date' => Carbon::today()->subDay()]);
    $book($this->rooms[2], ['booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDay(), 'checkout_date' => Carbon::today()]);
    $book($this->rooms[3], ['checkin_date' => Carbon::today()->addDays(5), 'checkout_date' => Carbon::today()->addDays(6)]);
    $book($this->rooms[3], ['checkin_date' => Carbon::today()->addDays(20), 'checkout_date' => Carbon::today()->addDays(21)]);
    // Category-only arrival in this branch.
    Booking::factory()->for($this->tenant)->create([
        'room_id' => null, 'room_category_id' => $this->category->id, 'guest_name' => 'Uche Unassigned',
        'checkin_date' => Carbon::today(), 'checkout_date' => Carbon::today()->addDay(), 'total_amount' => 30_000,
    ]);
    // Noise: other branch + other tenant.
    $book($this->abujaRoom, ['guest_name' => 'Abuja Guest']);
    $other = Tenant::factory()->create(['slug' => 'other-desk']);
    $other->makeCurrent();
    $otherRoom = hostBranch($other, 'Other', 1, '5')['rooms'][0];
    Booking::factory()->for($other)->for($otherRoom)->create(['checkin_date' => Carbon::today(), 'booking_status' => BookingStatus::Confirmed, 'guest_name' => 'Other Tenant']);
    $this->tenant->makeCurrent();

    $res = $this->getJson(hostUrl($this->tenant, 'bookings?segment=arriving'), ($this->h)());

    $res->assertOk()
        ->assertJsonPath('counts', ['arriving' => 2, 'in_house' => 2, 'leaving' => 2, 'upcoming' => 1]);
    $names = collect($res->json('data'))->pluck('guest_name')->all();
    expect($names)->toBe(['Amaka Bello', 'Uche Unassigned']);

    $card = $res->json('data.0');
    expect($card)->toBe([
        'ref' => $arr->booking_ref,
        'guest_name' => 'Amaka Bello',
        'guest_phone' => '+2348011111111',
        'room_number' => '101',
        'category' => 'Ikoyi Deluxe',
        'checkin' => '2026-10-22',
        'checkout' => '2026-10-24',
        'nights' => 2,
        'status' => 'on_hold',
        'status_label' => 'On hold',
        'balance_due' => 20_000,
        'total_amount' => 60_000,
        'chip' => ['label' => '₦20,000 due', 'tone' => 'due'],
        'overdue' => false,
        'corporate' => null,
        'needs_room' => false,
    ]);
    expect($res->json('data.1.needs_room'))->toBeTrue();
    // Pending "not paid yet" owes its total.
    expect($res->json('data.1.balance_due'))->toBe(30_000);
});

it('puts overdue guests first in leaving and searches name, phone digits and room', function (): void {
    $book = $this->book;
    $book($this->rooms[0], ['guest_name' => 'Today Leaver', 'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDay(), 'checkout_date' => Carbon::today()]);
    $book($this->rooms[1], ['guest_name' => 'Late Leaver', 'guest_phone' => '+234 802 555 1234', 'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDays(3), 'checkout_date' => Carbon::today()->subDay()]);

    $res = $this->getJson(hostUrl($this->tenant, 'bookings?segment=leaving'), ($this->h)())->assertOk();
    expect(collect($res->json('data'))->pluck('guest_name')->all())->toBe(['Late Leaver', 'Today Leaver']);
    expect($res->json('data.0.overdue'))->toBeTrue();

    $byPhone = $this->getJson(hostUrl($this->tenant, 'bookings?segment=leaving&q=5551234'), ($this->h)())->assertOk();
    expect(collect($byPhone->json('data'))->pluck('guest_name')->all())->toBe(['Late Leaver']);
    expect($byPhone->json('counts.leaving'))->toBe(1);

    $byRoom = $this->getJson(hostUrl($this->tenant, 'bookings?segment=in_house&q=101'), ($this->h)())->assertOk();
    expect(collect($byRoom->json('data'))->pluck('guest_name')->all())->toBe(['Today Leaver']);
});

it('rejects an unknown segment and non-host roles', function (): void {
    $this->getJson(hostUrl($this->tenant, 'bookings?segment=nope'), ($this->h)())->assertStatus(422);
    $cleaner = Operator::factory()->for($this->tenant)->create(['role' => 'house_cleaner']);
    $this->getJson(hostUrl($this->tenant, 'bookings'), hostHeaders($cleaner))
        ->assertStatus(403)->assertJsonPath('error', 'role_not_supported');
});

// ── GET host/bookings/{ref} ──────────────────────────────────────

it('returns the booking detail with folio, can flags and payment options', function (): void {
    $customer = Customer::create(['name' => 'Amaka Bello', 'phone' => '+2348011111111', 'lifecycle' => CustomerLifecycle::Active, 'is_vip' => true]);
    app(WalletService::class)->topup($customer, 15_000, description: 'top up');
    $b = ($this->book)($this->rooms[0], ['customer_id' => $customer->id, 'balance_due' => 20_000, 'booking_status' => BookingStatus::OnHold, 'guest_email' => 'amaka@example.com']);
    Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'paystack_reference' => 'manual-x1',
        'amount' => 40_000, 'type' => PaymentType::Deposit, 'method' => 'transfer', 'status' => PaymentStatus::Succeeded, 'paid_at' => now(),
    ]);
    app(AddBookingCharge::class)->handle($b, $this->manager, BookingChargeType::Laundry, 'Laundry', 3_000);

    $res = $this->getJson(hostUrl($this->tenant, 'bookings/'.$b->booking_ref), ($this->h)())->assertOk();

    $res->assertJsonPath('data.outstanding', 23_000)
        ->assertJsonPath('data.balance_due', 23_000)
        ->assertJsonPath('data.paid_total', 40_000)
        ->assertJsonPath('data.guest_email', 'amaka@example.com')
        ->assertJsonPath('data.customer.is_vip', true)
        ->assertJsonPath('data.customer.wallet_balance', 15_000)
        ->assertJsonPath('data.room_ready', ['state' => 'ready', 'label' => 'Ready'])
        ->assertJsonPath('data.charges.0.type', 'laundry')
        ->assertJsonPath('data.payments.0.method_label', 'Bank transfer')
        ->assertJsonPath('data.can', [
            'check_in' => true, 'check_out' => false, 'take_payment' => true, 'add_charge' => true,
            'extend' => true, 'no_show' => true, 'cancel' => true,
        ])
        ->assertJsonPath('data.payment_options.wallet_available', 15_000);
    expect(collect($res->json('data.payment_options.methods'))->pluck('value')->all())
        ->toBe(['cash', 'transfer', 'card', 'pos', 'other', 'wallet']);
});

it('404s a booking from another branch or tenant', function (): void {
    $abuja = ($this->book)($this->abujaRoom);
    $this->getJson(hostUrl($this->tenant, 'bookings/'.$abuja->booking_ref), ($this->h)())
        ->assertNotFound()->assertJsonPath('error', 'not_found');
    $this->postJson(hostUrl($this->tenant, 'bookings/'.$abuja->booking_ref.'/payments'), ['amount' => 1000, 'method' => 'cash'], ($this->w)())
        ->assertNotFound();
});

// ── POST host/bookings/{ref}/payments ────────────────────────────

it('takes a part payment that lowers the outstanding by exactly the amount', function (): void {
    $b = ($this->book)($this->rooms[0], ['balance_due' => 30_000, 'booking_status' => BookingStatus::OnHold]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 10_000, 'method' => 'pos'], ($this->w)())
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('booking.outstanding', 20_000);

    // A second, smaller payment records fully too.
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 5_000, 'method' => 'cash'], ($this->w)())
        ->assertOk()->assertJsonPath('booking.outstanding', 15_000);
    expect(Payment::query()->where('booking_id', $b->id)->pluck('amount')->map(fn ($a) => (int) $a)->all())->toBe([10_000, 5_000]);
});

it('records cash handed over in full and leaves the wallet alone, trimming a long note', function (): void {
    $customer = Customer::create(['name' => 'W', 'lifecycle' => CustomerLifecycle::Active]);
    app(WalletService::class)->topup($customer, 8_000, description: 'top up');
    $b = ($this->book)($this->rooms[0], ['customer_id' => $customer->id, 'balance_due' => 30_000, 'booking_status' => BookingStatus::CheckedIn]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), [
        'amount' => 30_000, 'method' => 'cash', 'reference_note' => str_repeat('Receipt 123 ', 40),
    ], ($this->w)())->assertOk()->assertJsonPath('booking.outstanding', 0);

    // The guest paid ₦30,000 in cash: all of it is recorded as cash so
    // the drawer reconciles; the wallet is only spent when chosen.
    expect($customer->fresh()->walletBalance())->toBe(8_000);
    $cash = Payment::query()->where('booking_id', $b->id)->where('method', 'cash')->first();
    expect((int) $cash->amount)->toBe(30_000);
    expect(mb_strlen((string) $cash->reference_note))->toBeLessThanOrEqual(120);
});

it('takes a partial wallet payment that reduces what is owed', function (): void {
    $customer = Customer::create(['name' => 'W', 'lifecycle' => CustomerLifecycle::Active]);
    app(WalletService::class)->topup($customer, 50_000, description: 'top up');
    $b = ($this->book)($this->rooms[0], ['customer_id' => $customer->id, 'balance_due' => 30_000, 'booking_status' => BookingStatus::OnHold]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 12_000, 'method' => 'wallet'], ($this->w)())
        ->assertOk()->assertJsonPath('booking.outstanding', 18_000);
    expect($customer->fresh()->walletBalance())->toBe(38_000);
});

it('confirms a pending booking when paid, and refuses bad input', function (): void {
    $b = ($this->book)($this->rooms[0], ['booking_status' => BookingStatus::Pending, 'balance_due' => 0]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 20_000, 'method' => 'transfer'], ($this->w)())
        ->assertOk()->assertJsonPath('booking.status', 'on_hold')->assertJsonPath('booking.outstanding', 40_000);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 20_000, 'method' => 'paystack'], ($this->w)())
        ->assertStatus(422);
    $paid = ($this->book)($this->rooms[1]);
    $this->postJson(hostUrl($this->tenant, "bookings/{$paid->booking_ref}/payments"), ['amount' => 5_000, 'method' => 'cash'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'payment_failed');
});

// ── POST host/bookings/{ref}/check-in ────────────────────────────

it('needs a payment to check in a pending booking, then confirms and checks in', function (): void {
    $b = ($this->book)($this->rooms[0], ['booking_status' => BookingStatus::Pending]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-in"), [], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'payment_required');

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-in"), [
        'payment' => ['amount' => 60_000, 'method' => 'cash'],
    ], ($this->w)())->assertOk()
        ->assertJsonPath('booking.status', 'checked_in')
        ->assertJsonPath('booking.outstanding', 0)
        ->assertJsonPath('warning', null);
    expect((int) Payment::query()->where('booking_id', $b->id)->sum('amount'))->toBe(60_000);
});

it('refuses check-in when the room was flagged not fit (room_not_ready)', function (): void {
    $b = ($this->book)($this->rooms[0]);
    RoomInspection::query()->create(['tenant_id' => $this->tenant->id, 'room_id' => $this->rooms[0]->id, 'status' => RoomInspectionStatus::Failed->value]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-in"), [], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'room_not_ready');
    expect($b->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
});

it('rolls the payment back when the check-in itself fails', function (): void {
    ($this->book)($this->rooms[0], ['booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDay(), 'checkout_date' => Carbon::today()->addDay()]);
    $b = ($this->book)($this->rooms[0], ['booking_status' => BookingStatus::Pending]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-in"), [
        'payment' => ['amount' => 60_000, 'method' => 'cash'],
    ], ($this->w)())->assertStatus(422)->assertJsonPath('error', 'action_failed');

    expect(Payment::query()->where('booking_id', $b->id)->count())->toBe(0);
    expect($b->fresh()->booking_status)->toBe(BookingStatus::Pending);
});

// ── POST host/bookings/{ref}/check-out ───────────────────────────

it('blocks check-out with a balance, then checks out once paid', function (): void {
    $b = ($this->book)($this->rooms[0], [
        'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDays(2), 'checkout_date' => Carbon::today(), 'balance_due' => 5_000,
    ]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-out"), [], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'balance_outstanding')->assertJsonPath('outstanding', 5_000);

    $b->forceFill(['balance_due' => 0])->save();
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-out"), [], ($this->w)())
        ->assertOk()->assertJsonPath('booking.status', 'checked_out');
});

it('asks for an early check-out mode when unused paid nights are refundable', function (): void {
    $b = ($this->book)($this->rooms[0], [
        'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDay(), 'checkout_date' => Carbon::today()->addDays(2), 'nights' => 3,
        'room_total' => 90_000, 'total_amount' => 90_000,
    ]);
    Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'paystack_reference' => 'manual-x2',
        'amount' => 90_000, 'type' => PaymentType::Full, 'method' => 'cash', 'status' => PaymentStatus::Succeeded, 'paid_at' => now(),
    ]);

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-out"), [], ($this->w)());
    $res->assertStatus(422)->assertJsonPath('error', 'early_mode_required');
    expect($res->json('refundable_amount'))->toBeGreaterThan(0);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-out"), ['early_mode' => 'keep'], ($this->w)())
        ->assertOk()->assertJsonPath('booking.status', 'checked_out');
});

// ── POST host/bookings/{ref}/charges ─────────────────────────────

it('adds a charge, waives one, and refuses system-only types', function (): void {
    $b = ($this->book)($this->rooms[0], ['booking_status' => BookingStatus::CheckedIn]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/charges"), ['type' => 'food_beverage', 'amount' => 4_500], ($this->w)())
        ->assertOk()->assertJsonPath('booking.outstanding', 4_500)->assertJsonPath('booking.charges.0.description', 'Food & beverage');
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/charges"), ['type' => 'damage', 'amount' => 9_000, 'description' => 'Broken glass', 'waive' => true], ($this->w)())
        ->assertOk()->assertJsonPath('booking.outstanding', 4_500)->assertJsonPath('booking.charges.1.settled_method', 'waived');
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/charges"), ['type' => 'extension', 'amount' => 1_000], ($this->w)())
        ->assertStatus(422);
});

// ── Extend ───────────────────────────────────────────────────────

it('quotes and extends a stay, collecting the extension', function (): void {
    $b = ($this->book)($this->rooms[0], ['booking_status' => BookingStatus::CheckedIn, 'checkin_date' => Carbon::today()->subDay(), 'checkout_date' => Carbon::today()->addDay(), 'nights' => 2]);
    ($this->book)($this->rooms[0], ['checkin_date' => Carbon::today()->addDays(4), 'checkout_date' => Carbon::today()->addDays(5)]);

    $this->getJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/extend-quote?checkout=".Carbon::today()->addDays(3)->toDateString()), ($this->h)())
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.max_checkout', Carbon::today()->addDays(4)->toDateString())
        ->assertJsonPath('data.extra_nights', 2)
        ->assertJsonPath('data.extension_amount', 60_000)
        ->assertJsonPath('data.new_total', 120_000)
        ->assertJsonPath('data.outstanding_after', 60_000);

    $this->getJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/extend-quote?checkout=".Carbon::today()->addDays(6)->toDateString()), ($this->h)())
        ->assertOk()->assertJsonPath('data.available', false);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/extend"), [
        'checkout' => Carbon::today()->addDays(3)->toDateString(), 'collect' => 'extension', 'method' => 'card',
    ], ($this->w)())->assertOk()
        ->assertJsonPath('booking.checkout', Carbon::today()->addDays(3)->toDateString())
        ->assertJsonPath('booking.outstanding', 0);

    $ext = BookingCharge::query()->where('booking_id', $b->id)->where('type', 'extension')->first();
    expect((bool) $ext->settled)->toBeTrue();
    expect((int) Payment::query()->where('booking_id', $b->id)->where('method', 'card')->sum('amount'))->toBe(60_000);
});

// ── No-show / cancel ─────────────────────────────────────────────

it('marks a no-show and cancels a future booking', function (): void {
    $arr = ($this->book)($this->rooms[0]);
    $this->postJson(hostUrl($this->tenant, "bookings/{$arr->booking_ref}/no-show"), ['forfeit_deposit' => true], ($this->w)())
        ->assertOk()->assertJsonPath('booking.status', 'no_show');

    $future = ($this->book)($this->rooms[1], ['checkin_date' => Carbon::today()->addDays(10), 'checkout_date' => Carbon::today()->addDays(11)]);
    $this->postJson(hostUrl($this->tenant, "bookings/{$future->booking_ref}/no-show"), ['forfeit_deposit' => false], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'action_failed');
    $this->postJson(hostUrl($this->tenant, "bookings/{$future->booking_ref}/cancel"), ['refund_requested' => false], ($this->w)())
        ->assertOk()->assertJsonPath('booking.status', 'cancelled');
});

// ── Rooms / customers / meta ─────────────────────────────────────

it('lists free rooms of the branch only, with walk-in pricing', function (): void {
    ($this->book)($this->rooms[0], ['checkin_date' => Carbon::today(), 'checkout_date' => Carbon::today()->addDays(2)]);
    \App\Models\AvailabilityBlock::factory()->for($this->tenant)->create([
        'room_id' => $this->rooms[0]->id, 'date' => Carbon::today()->toDateString(), 'type' => 'booked',
    ]);

    $res = $this->getJson(hostUrl($this->tenant, 'rooms/available?checkin=2026-10-22&checkout=2026-10-24&adults=2'), ($this->h)())->assertOk();

    expect(collect($res->json('data'))->pluck('room_number')->all())->toBe(['102', '103', '104']);
    expect($res->json('data.0'))->toMatchArray([
        'room_number' => '102', 'category' => 'Ikoyi Deluxe', 'nightly_rate' => 30_000, 'total' => 60_000,
        'breakfast_available' => false, 'breakfast_rate' => null,
    ]);
    $this->getJson(hostUrl($this->tenant, 'rooms/available?checkin=2026-10-22&checkout=2026-10-24&adults=5'), ($this->h)())
        ->assertOk()->assertJsonCount(0, 'data');
});

it('looks a customer up by phone', function (): void {
    $c = Customer::create(['name' => 'Bisi Ade', 'phone' => '+2348031234567', 'email' => 'bisi@example.com', 'lifecycle' => CustomerLifecycle::Active]);
    app(WalletService::class)->topup($c, 7_000, description: 'top up');

    $this->getJson(hostUrl($this->tenant, 'customers/lookup?phone=8031234567'), ($this->h)())
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $c->id, 'name' => 'Bisi Ade', 'email' => 'bisi@example.com', 'phone' => '+2348031234567',
            'stays_count' => 0, 'is_vip' => false, 'wallet_balance' => 7_000, 'corporate' => null,
        ]]);
    $this->getJson(hostUrl($this->tenant, 'customers/lookup?phone=0809999999'), ($this->h)())
        ->assertOk()->assertExactJson(['data' => null]);
});

it('serves meta labels from the shared lists', function (): void {
    $res = $this->getJson(hostUrl($this->tenant, 'meta'), ($this->h)())->assertOk();
    expect($res->json('data.payment_methods'))->toBe([
        ['value' => 'cash', 'label' => 'Cash'], ['value' => 'transfer', 'label' => 'Bank transfer'],
        ['value' => 'card', 'label' => 'Card'], ['value' => 'pos', 'label' => 'POS terminal'], ['value' => 'other', 'label' => 'Other'],
    ]);
    $types = collect($res->json('data.charge_types'))->pluck('value');
    expect($types)->not->toContain('extension')->not->toContain('deposit_forfeit')->toContain('laundry');
    expect(collect($res->json('data.booking_statuses'))->pluck('value')->all())->toBe(['confirmed', 'on_hold', 'pending', 'wallet', 'corp_invoice']);
    expect(collect($res->json('data.early_checkout_modes'))->pluck('value')->all())->toBe(['keep', 'wallet', 'cash']);
    // No em-dashes in app copy.
    expect($res->getContent())->not->toContain('—');
});

// ── POST host/bookings ───────────────────────────────────────────

it('creates a paid-in-full booking without an email', function (): void {
    $res = $this->postJson(hostUrl($this->tenant, 'bookings'), [
        'room_id' => $this->rooms[1]->id, 'checkin' => '2026-10-22', 'checkout' => '2026-10-24',
        'guest_name' => 'Walk In', 'guest_phone' => '08051112222', 'adults' => 1,
        'status' => 'confirmed', 'method' => 'pos',
    ], ($this->w)());

    $res->assertOk()->assertJsonPath('booking.status', 'confirmed')->assertJsonPath('booking.outstanding', 0)
        ->assertJsonPath('booking.guest_email', null);
    $b = Booking::query()->where('booking_ref', $res->json('booking.ref'))->first();
    expect(Payment::query()->where('booking_id', $b->id)->value('method'))->toBe('pos');
    expect((int) Payment::query()->where('booking_id', $b->id)->sum('amount'))->toBe((int) $b->total_amount);
});

it('enforces deposit, wallet, corporate and branch rules on create', function (): void {
    $base = [
        'room_id' => $this->rooms[1]->id, 'checkin' => '2026-10-22', 'checkout' => '2026-10-24',
        'guest_name' => 'Walk In', 'guest_phone' => '08051112222', 'adults' => 1,
    ];

    $this->postJson(hostUrl($this->tenant, 'bookings'), $base + ['status' => 'on_hold', 'amount' => 1, 'method' => 'cash'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'deposit_too_low');
    $this->postJson(hostUrl($this->tenant, 'bookings'), $base + ['status' => 'wallet'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'wallet_unavailable');
    $this->postJson(hostUrl($this->tenant, 'bookings'), $base + ['status' => 'corp_invoice'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'corporate_required');
    $this->postJson(hostUrl($this->tenant, 'bookings'), array_merge($base, ['room_id' => $this->abujaRoom->id, 'status' => 'pending']), ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'room_not_found');
    $this->postJson(hostUrl($this->tenant, 'bookings'), array_merge($base, ['adults' => 3, 'status' => 'pending']), ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'room_too_small')
        ->assertJsonPath('capacity.adults', 2);
    expect(Booking::query()->count())->toBe(0);
});

it('creates an on-hold booking from a deposit and a wallet booking that spends what it can', function (): void {
    $dep = $this->postJson(hostUrl($this->tenant, 'bookings'), [
        'room_id' => $this->rooms[1]->id, 'checkin' => '2026-10-22', 'checkout' => '2026-10-24',
        'guest_name' => 'Deposit Guest', 'guest_phone' => '08051112222', 'adults' => 1,
        'status' => 'on_hold', 'amount' => 30_000, 'method' => 'transfer',
    ], ($this->w)())->assertOk();
    $dep->assertJsonPath('booking.status', 'on_hold')->assertJsonPath('booking.outstanding', $dep->json('booking.total_amount') - 30_000);

    $c = Customer::create(['name' => 'Wallet Guest', 'phone' => '+2348077777777', 'lifecycle' => CustomerLifecycle::Active]);
    app(WalletService::class)->topup($c, 20_000, description: 'top up');
    $wal = $this->postJson(hostUrl($this->tenant, 'bookings'), [
        'room_id' => $this->rooms[2]->id, 'checkin' => '2026-10-22', 'checkout' => '2026-10-24',
        'guest_name' => 'Wallet Guest', 'guest_phone' => '+2348077777777', 'adults' => 1,
        'status' => 'wallet', 'customer_id' => $c->id,
    ], ($this->w)())->assertOk();
    $wal->assertJsonPath('booking.status', 'on_hold')
        ->assertJsonPath('booking.outstanding', $wal->json('booking.total_amount') - 20_000);
    expect($c->fresh()->walletBalance())->toBe(0);
});

it('lets a reservation officer create a booking', function (): void {
    $officer = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::ReservationOfficer]);
    $this->postJson(hostUrl($this->tenant, 'bookings'), [
        'room_id' => $this->rooms[1]->id, 'checkin' => '2026-10-22', 'checkout' => '2026-10-23',
        'guest_name' => 'Walk In', 'guest_phone' => '08051112222', 'adults' => 1, 'status' => 'pending',
    ], ($this->w)($officer))->assertOk()->assertJsonPath('booking.status', 'pending');
});

// ── Web handoff ──────────────────────────────────────────────────

it('mints a one-time dashboard link that signs in without the desktop shell', function (): void {
    $res = $this->postJson(hostUrl($this->tenant, 'web-handoff'), ['next' => '/dashboard/finance'], ($this->w)($this->manager))
        ->assertOk()->assertJsonPath('expires_in', 60);
    $url = (string) $res->json('url');
    expect($url)->toStartWith('http://host-desk.'.config('keverna.central_domain').'/auth/app-handoff?code=');
    expect($url)->not->toContain('token');

    $this->get($url)
        ->assertRedirect('/dashboard/finance')
        ->assertCookieMissing('keverna_desktop_shell');
    $this->assertAuthenticatedAs($this->manager, 'web');

    // Single use.
    auth('web')->logout();
    $this->get($url)->assertRedirect(route('tenant.login'));
    $this->assertGuest('web');
});

it('expires the handoff code after 60 seconds and rejects non-dashboard targets', function (): void {
    $this->postJson(hostUrl($this->tenant, 'web-handoff'), ['next' => 'https://evil.test/dashboard'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'invalid_next');
    $this->postJson(hostUrl($this->tenant, 'web-handoff'), ['next' => '//evil.test'], ($this->w)())
        ->assertStatus(422);
    $this->postJson(hostUrl($this->tenant, 'web-handoff'), ['next' => '/dashboard/reservations/KV-ABC123'], ($this->w)())
        ->assertOk();

    $url = (string) $this->postJson(hostUrl($this->tenant, 'web-handoff'), ['next' => '/dashboard'], ($this->w)())->json('url');
    Carbon::setTestNow(Carbon::now()->addSeconds(61));
    $this->travel(61)->seconds();
    $this->get($url)->assertRedirect(route('tenant.login'));
    $this->assertGuest('web');
});

