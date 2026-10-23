<?php

declare(strict_types=1);

use App\Actions\Bookings\AddBookingCharge;
use App\Actions\Bookings\SettleCharges;
use App\Enums\BookingChargeType;
use App\Enums\BookingStatus;
use App\Enums\CustomerLifecycle;
use App\Enums\GuestRequestKind;
use App\Enums\HousekeepingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Events\BookingConfirmed;
use App\Livewire\Dashboard\Reservations\Show as ReservationShow;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GuestIdDocument;
use App\Models\GuestRequest;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Guests\GuestIdDocuments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/DeskTestHelpers.php';

/**
 * Keverna Desk phase 2 server (docs/desk-app/PHASE-2.md, Server): move
 * room, dates, discounts, guest ID + retention, keys, receipts, folio,
 * the bookings list views and the booking detail additions.
 *
 * Ikoyi (current branch): 101-104 Deluxe at 30k + 201 Suite at 50k.
 * Abuja (same tenant) and another tenant must never leak.
 */
const DESK_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-23 09:00:00'));
    Storage::fake((string) config('media-library.disk_name'));

    $this->tenant = Tenant::factory()->create(['slug' => 'desk-folio', 'phone' => '+2348000000001']);
    $this->tenant->makeCurrent();

    $ikoyi = hostBranch($this->tenant, 'Ikoyi', 4, '1');
    $this->property = $ikoyi['property'];
    $this->property->forceFill(['address' => '12 Bourdillon Rd'])->save();
    $this->category = $ikoyi['category'];
    $this->rooms = $ikoyi['rooms'];
    $suite = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Ikoyi Suite', 'capacity_adults' => 2, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->suite = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $suite->id, 'room_number' => '201', 'status' => RoomStatus::Active->value,
    ]);
    $this->abujaRoom = hostBranch($this->tenant, 'Abuja', 1, '9')['rooms'][0];

    $this->manager = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager, 'name' => 'Chidi Okafor', 'staff_pin_hash' => Hash::make('1111')]);
    $this->frontdesk = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Frontdesk, 'name' => 'Ngozi Eze', 'staff_pin_hash' => Hash::make('2222')]);
    $this->officer = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::ReservationOfficer, 'name' => 'Bisi Ade']);

    $this->h = fn (?Operator $op = null) => hostHeaders($op ?? $this->frontdesk);
    $this->w = fn (?Operator $op = null) => hostHeaders($op ?? $this->frontdesk) + hostWriteKey();

    // In house in 101 since 21 Oct, leaving 25 Oct: 4 nights, 2 left.
    $this->inHouse = function (array $attrs = []): Booking {
        $b = hostBooking($this->rooms[0], array_merge([
            'guest_name' => 'Amaka Bello',
            'booking_status' => BookingStatus::CheckedIn,
            'payment_status' => PaymentStatus::Succeeded,
            'checkin_date' => '2026-10-21', 'checkout_date' => '2026-10-25', 'nights' => 4,
            'room_total' => 120_000, 'total_amount' => 120_000, 'balance_due' => 0,
        ], $attrs));
        hostReserveNights($b);

        return $b;
    };
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

function blockDates(Booking $b): array
{
    return AvailabilityBlock::query()->where('booking_id', $b->id)->orderBy('date')->get()
        ->map(fn ($r) => $r->room_id.'@'.Carbon::parse($r->date)->toDateString())->all();
}

// ── Move room ────────────────────────────────────────────────────

it('lists rooms free for the rest of the stay with the rate difference', function (): void {
    $b = ($this->inHouse)();
    $busy = hostBooking($this->rooms[1], ['checkin_date' => '2026-10-23', 'checkout_date' => '2026-10-24', 'nights' => 1]);
    hostReserveNights($busy);
    $this->rooms[2]->forceFill(['housekeeping_status' => HousekeepingStatus::Dirty->value])->save();

    $res = $this->getJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-options"), ($this->h)())->assertOk();

    expect(collect($res->json('data'))->pluck('room_number')->all())->toBe(['103', '104', '201'])
        ->and($res->json('remaining_nights'))->toBe(2)
        ->and($res->json('from'))->toBe('2026-10-23')
        ->and($res->json('current'))->toBe(['room_id' => $this->rooms[0]->id, 'room_number' => '101', 'rate' => 30_000])
        ->and($res->json('can_move_free'))->toBeFalse();
    $suite = collect($res->json('data'))->firstWhere('room_number', '201');
    expect($suite)->toMatchArray([
        'rate' => 50_000, 'rate_difference_per_night' => 20_000, 'total_difference' => 40_000,
        'category' => ['id' => $this->suite->room_category_id, 'name' => 'Ikoyi Suite'],
        'housekeeping' => ['state' => 'clean', 'label' => HousekeepingStatus::Clean->label()],
    ]);
    expect(collect($res->json('data'))->firstWhere('room_number', '103')['housekeeping']['state'])->toBe('dirty');
});

it('moves an in-house guest to a dearer room, charging the rest of the stay and dirtying the old room', function (): void {
    $b = ($this->inHouse)();

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-room"), [
        'room_id' => $this->suite->id, 'reason' => 'AC broken', 'charge' => 'difference',
    ], ($this->w)())->assertOk();

    $res->assertJsonPath('move', [
        'from_room' => '101', 'to_room' => '201', 'remaining_nights' => 2, 'rate_delta' => 40_000,
        'charged' => 40_000, 'free' => false, 'old_room_dirty' => true,
    ])
        ->assertJsonPath('booking.room_number', '201')
        ->assertJsonPath('booking.total_amount', 160_000)
        ->assertJsonPath('booking.outstanding', 40_000);
    expect($this->rooms[0]->fresh()->housekeeping_status)->toBe(HousekeepingStatus::Dirty)
        ->and(blockDates($b))->toBe([
            $this->rooms[0]->id.'@2026-10-21', $this->rooms[0]->id.'@2026-10-22',
            $this->suite->id.'@2026-10-23', $this->suite->id.'@2026-10-24',
        ]);
    expect(collect($res->json('booking.history'))->pluck('text')->first())->toBe('Moved from room 101 to 201 (+₦40,000)');
});

it('gives a free upgrade only to an approve-capable operator', function (): void {
    $b = ($this->inHouse)();
    $body = ['room_id' => $this->suite->id, 'charge' => 'free'];

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-room"), $body, ($this->w)())
        ->assertStatus(403)->assertJsonPath('error', 'needs_manager');
    expect($b->fresh()->room_id)->toBe($this->rooms[0]->id);

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-room"), $body, ($this->w)($this->manager))->assertOk();
    $res->assertJsonPath('move.free', true)->assertJsonPath('move.charged', 0)
        ->assertJsonPath('booking.total_amount', 120_000)
        ->assertJsonPath('booking.outstanding', 0);
    expect(collect($res->json('booking.history'))->pluck('text')->all())->toContain('Free upgrade, ₦40,000 not charged');
});

it('moves a guest to a cheaper room without approval, and a same-rate move costs nothing', function (): void {
    $b = hostBooking($this->suite, ['checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-26', 'nights' => 2,
        'room_total' => 100_000, 'total_amount' => 100_000, 'balance_due' => 0, 'payment_status' => PaymentStatus::Pending, 'booking_status' => BookingStatus::Pending]);
    hostReserveNights($b);

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-room"), ['room_id' => $this->rooms[3]->id, 'charge' => 'free'], ($this->w)())->assertOk();
    $res->assertJsonPath('move.rate_delta', -40_000)->assertJsonPath('move.old_room_dirty', false)
        ->assertJsonPath('booking.total_amount', 60_000);
    expect($this->suite->fresh()->housekeeping_status)->not->toBe(HousekeepingStatus::Dirty);
});

it('refuses a taken room (409), another branch room (422) and other branches or tenants (404)', function (): void {
    $b = ($this->inHouse)();
    $busy = hostBooking($this->suite, ['checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-25', 'nights' => 1]);
    hostReserveNights($busy);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-room"), ['room_id' => $this->suite->id], ($this->w)())
        ->assertStatus(409)->assertJsonPath('error', 'not_available');
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/move-room"), ['room_id' => $this->abujaRoom->id], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'room_not_found');

    $abuja = hostBooking($this->abujaRoom);
    $this->getJson(hostUrl($this->tenant, "bookings/{$abuja->booking_ref}/move-options"), ($this->h)())->assertNotFound();
    $this->postJson(hostUrl($this->tenant, "bookings/{$abuja->booking_ref}/move-room"), ['room_id' => $this->rooms[1]->id], ($this->w)())->assertNotFound();

    $other = Tenant::factory()->create(['slug' => 'other-folio']);
    $other->makeCurrent();
    $otherBooking = hostBooking(hostBranch($other, 'Other', 1, '5')['rooms'][0]);
    $this->tenant->makeCurrent();
    $this->getJson(hostUrl($this->tenant, "bookings/{$otherBooking->booking_ref}/move-options"), ($this->h)())->assertNotFound();
});

// ── Dates ────────────────────────────────────────────────────────

it('moves a pinned booking to new dates and reprices it', function (): void {
    $b = hostBooking($this->rooms[2], ['checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-26', 'nights' => 2,
        'room_total' => 60_000, 'total_amount' => 60_000, 'balance_due' => 0, 'payment_status' => PaymentStatus::Pending, 'booking_status' => BookingStatus::Pending]);
    hostReserveNights($b);

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/dates"), [
        'checkin_date' => '2026-10-25', 'checkout_date' => '2026-10-28',
    ], ($this->w)())->assertOk();

    $res->assertJsonPath('change', ['old_total' => 60_000, 'new_total' => 90_000, 'delta' => 30_000])
        ->assertJsonPath('booking.checkin', '2026-10-25')
        ->assertJsonPath('booking.nights', 3)
        ->assertJsonPath('booking.balance_due', 90_000);
    $id = $this->rooms[2]->id;
    expect(blockDates($b))->toBe(["{$id}@2026-10-25", "{$id}@2026-10-26", "{$id}@2026-10-27"]);

    // Drag onto the suite row: room changes too, priced at the suite rate.
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/dates"), ['room_id' => $this->suite->id], ($this->w)())
        ->assertOk()->assertJsonPath('booking.room_number', '201')->assertJsonPath('booking.total_amount', 150_000);
});

it('moves the balance only once the guest has paid, and refuses clashes and guests in house', function (): void {
    $b = hostBooking($this->rooms[2], ['checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-26', 'nights' => 2,
        'room_total' => 60_000, 'total_amount' => 60_000, 'balance_due' => 30_000, 'payment_status' => PaymentStatus::Succeeded, 'booking_status' => BookingStatus::OnHold]);
    hostReserveNights($b);
    $clash = hostBooking($this->rooms[3], ['checkin_date' => '2026-10-27', 'checkout_date' => '2026-10-28', 'nights' => 1]);
    hostReserveNights($clash);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/dates"), ['checkout_date' => '2026-10-27'], ($this->w)())
        ->assertOk()->assertJsonPath('booking.balance_due', 60_000)->assertJsonPath('booking.total_amount', 90_000);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/dates"), ['room_id' => $this->rooms[3]->id, 'checkout_date' => '2026-10-28'], ($this->w)())
        ->assertStatus(409)->assertJsonPath('error', 'not_available');
    expect($b->fresh()->room_id)->toBe($this->rooms[2]->id);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/dates"), ['checkin_date' => '2026-10-20'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'invalid_dates');

    $in = ($this->inHouse)();
    $this->postJson(hostUrl($this->tenant, "bookings/{$in->booking_ref}/dates"), ['checkout_date' => '2026-10-27'], ($this->w)())
        ->assertStatus(422)->assertJsonPath('error', 'action_failed');
});

it('moves a category hold through Edit reservation and pins the room it was dropped on', function (): void {
    $b = Booking::factory()->for($this->tenant)->create([
        'room_id' => null, 'room_category_id' => $this->category->id, 'guest_name' => 'Uche Hold',
        'checkin_date' => '2026-10-24', 'checkout_date' => '2026-10-25', 'nights' => 1,
        'room_total' => 30_000, 'total_amount' => 30_000, 'balance_due' => 0, 'booking_status' => BookingStatus::Pending,
    ]);

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/dates"), [
        'checkin_date' => '2026-10-25', 'checkout_date' => '2026-10-27', 'room_id' => $this->rooms[3]->id,
    ], ($this->w)())->assertOk()
        ->assertJsonPath('booking.room_number', '104')
        ->assertJsonPath('booking.checkin', '2026-10-25')
        ->assertJsonPath('booking.total_amount', 60_000);
});

// ── Discount ─────────────────────────────────────────────────────

it('applies a folio discount for managers and officers, and says needs_manager to front desk', function (): void {
    $b = ($this->inHouse)(['balance_due' => 60_000, 'total_amount' => 120_000]);
    $url = hostUrl($this->tenant, "bookings/{$b->booking_ref}/discount-request");

    $this->postJson($url, ['percent' => 10, 'reason' => 'Loyal guest'], ($this->w)())
        ->assertStatus(403)->assertJsonPath('error', 'needs_manager');
    expect((int) $b->fresh()->total_amount)->toBe(120_000);

    $this->postJson($url, ['percent' => 10, 'reason' => 'Loyal guest'], ($this->w)($this->manager))
        ->assertOk()->assertJsonPath('applied', true)
        ->assertJsonPath('booking.total_amount', 108_000)
        ->assertJsonPath('booking.outstanding', 48_000);
    $this->postJson($url, ['amount' => 5_000, 'reason' => 'AC was off'], ($this->w)($this->officer))
        ->assertOk()->assertJsonPath('booking.total_amount', 103_000)->assertJsonPath('booking.outstanding', 43_000);

    $this->postJson($url, ['percent' => 5, 'amount' => 100, 'reason' => 'both'], ($this->w)($this->manager))->assertStatus(422);
    $this->postJson($url, ['reason' => 'none'], ($this->w)($this->manager))->assertStatus(422);
    $this->postJson($url, ['percent' => 5], ($this->w)($this->manager))->assertStatus(422);

    $history = $this->getJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}"), ($this->h)())->json('data.history');
    expect(collect($history)->pluck('text')->take(2)->all())->toBe(['Discount ₦5,000: AC was off', 'Discount ₦12,000: Loyal guest'])
        ->and($history[0]['by'])->toBe('Bisi Ade');
});

// ── Booking detail additions ─────────────────────────────────────

it('adds history, guest profile, keys, id document and the registration card to the detail', function (): void {
    $customer = Customer::create(['name' => 'Amaka Bello', 'phone' => '+2348011111111', 'notes' => 'Likes a quiet room', 'lifecycle' => CustomerLifecycle::Active]);
    hostBooking($this->rooms[3], ['customer_id' => $customer->id, 'booking_status' => BookingStatus::CheckedOut, 'checkin_date' => '2026-09-01', 'checkout_date' => '2026-09-02', 'nights' => 1]);
    $b = ($this->inHouse)(['customer_id' => $customer->id, 'keys_given' => 2, 'special_requests' => 'Extra pillow']);

    $d = $this->getJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}"), ($this->h)())->assertOk()->json('data');

    expect($d['guest'])->toBe([
        'customer_id' => $customer->id, 'name' => 'Amaka Bello', 'phone' => '+2348011111111', 'email' => $b->guest_email,
        'stays_count' => 2, 'returning' => true, 'is_vip' => false, 'wallet_balance' => 0, 'notes' => 'Likes a quiet room',
    ])
        ->and($d['keys'])->toBe(['given' => 2, 'returned' => null])
        ->and($d['id_document'])->toBeNull()
        ->and(end($d['history'])['text'])->toBe('Booked (Walk-in)')
        ->and($d['registration_card'])->toMatchArray([
            'booking_ref' => $b->booking_ref, 'room' => '101', 'arrival' => '2026-10-21', 'departure' => '2026-10-25',
            'nights' => 4, 'rate_per_night' => 30_000, 'total' => 120_000, 'paid' => 0, 'balance' => 0, 'keys_given' => 2,
            'special_requests' => 'Extra pillow', 'printed_by' => 'Ngozi Eze',
        ])
        ->and($d['registration_card']['hotel'])->toBe(['name' => 'Ikoyi', 'address' => '12 Bourdillon Rd, Lagos, Lagos', 'phone' => '+2348000000001', 'email' => $this->tenant->email ?: null]);
});

// ── Guest ID at check-in + keys ──────────────────────────────────

it('records the ID and keys at check-in, encrypted, and moves retention to the real check-out', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Tola Ade', 'checkin_date' => '2026-10-23', 'checkout_date' => '2026-10-25', 'nights' => 2]);
    hostReserveNights($b);

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-in"), [
        'keys_given' => 2,
        'id_document' => [
            'type' => 'passport', 'number' => 'A08812345', 'nationality' => 'Nigerian',
            'date_of_birth' => '1990-04-01', 'expires_on' => '2030-01-01', 'image_base64' => DESK_PNG,
        ],
    ], ($this->w)())->assertOk();

    $res->assertJsonPath('booking.status', 'checked_in')
        ->assertJsonPath('booking.keys.given', 2)
        ->assertJsonPath('booking.id_document.number', 'A08812345')
        ->assertJsonPath('booking.id_document.number_masked', 'A0881****')
        ->assertJsonPath('booking.id_document.type_label', 'Passport')
        ->assertJsonPath('booking.id_document.date_of_birth', '1990-04-01')
        ->assertJsonPath('booking.id_document.has_image', true)
        ->assertJsonPath('booking.id_document.retain_until', '2027-10-25')
        ->assertJsonPath('booking.id_document.captured_by', 'Ngozi Eze')
        ->assertJsonPath('booking.registration_card.guest.id_number_masked', 'A0881****');

    // Encrypted at rest: neither the DB row nor the stored scan holds the plain data.
    $raw = DB::table('guest_id_documents')->first();
    expect($raw->number)->not->toContain('A08812345')->and($raw->date_of_birth)->not->toContain('1990');
    $stored = Storage::disk((string) config('media-library.disk_name'))->get($raw->image_path);
    expect($stored)->not->toContain(base64_decode(DESK_PNG));

    $img = $this->get(hostUrl($this->tenant, "bookings/{$b->booking_ref}/id-document/image"), ($this->h)())->assertOk();
    expect($img->getContent())->toBe(base64_decode(DESK_PNG))->and($img->headers->get('Content-Type'))->toBe('image/png');

    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-out"), ['keys_returned' => 1, 'early_mode' => 'keep'], ($this->w)())
        ->assertOk()->assertJsonPath('booking.keys', ['given' => 2, 'returned' => 1])
        ->assertJsonPath('booking.id_document.retain_until', '2027-10-23');
});

it('refuses a bad scan without checking the guest in, and validates the ID fields', function (): void {
    $b = hostBooking($this->rooms[0], ['checkin_date' => '2026-10-23', 'checkout_date' => '2026-10-24', 'nights' => 1]);
    hostReserveNights($b);
    $url = hostUrl($this->tenant, "bookings/{$b->booking_ref}/check-in");

    $this->postJson($url, ['id_document' => ['type' => 'passport', 'number' => 'A123456', 'image_base64' => base64_encode('not an image')]], ($this->w)())
        ->assertStatus(422)->assertJsonValidationErrors(['id_document.image_base64']);
    $this->postJson($url, ['id_document' => ['type' => 'library_card', 'number' => 'A123456']], ($this->w)())
        ->assertStatus(422)->assertJsonValidationErrors(['id_document.type']);
    $this->postJson($url, ['id_document' => ['type' => 'national_id']], ($this->w)())
        ->assertStatus(422)->assertJsonValidationErrors(['id_document.number']);
    expect($b->fresh()->booking_status)->toBe(BookingStatus::Confirmed)
        ->and(GuestIdDocument::query()->count())->toBe(0);
});

it('records the ID after check-in through a desk operator token, logged at the desk', function (): void {
    $b = ($this->inHouse)();
    $desk = makeDesk($this->frontdesk, $this->property->id);
    $token = $this->postJson(deskUrl($this->tenant, 'unlock'), ['operator_id' => $this->frontdesk->id, 'pin' => '2222'], deskBearer($desk['token'], deskKey()))
        ->assertOk()->json('token');

    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/id-document"), [
        'type' => 'national_id', 'number' => '12345678901',
    ], deskBearer($token, deskKey()))->assertOk();

    $res->assertJsonPath('booking.id_document.number_masked', '123456*****');
    expect($res->json('booking.history.0'))->toMatchArray(['text' => 'ID recorded (National ID (NIN))', 'by' => 'Ngozi Eze', 'via' => 'desk']);
    $this->get(hostUrl($this->tenant, "bookings/{$b->booking_ref}/id-document/image"), ($this->h)())->assertNotFound();
});

it('masks numbers', function (string $in, string $out): void {
    expect(GuestIdDocument::mask($in))->toBe($out);
})->with([
    ['A08812345', 'A0881****'],
    ['12345678901', '123456*****'],
    ['AB12', 'A***'],
    ['7', '*'],
]);

it('purges ID data after the retention period but keeps that an ID was seen', function (): void {
    config(['keverna.guest_id_retention_months' => 6]);
    $ids = app(GuestIdDocuments::class);
    $old = hostBooking($this->rooms[1], ['booking_status' => BookingStatus::CheckedOut, 'checkin_date' => '2026-01-10', 'checkout_date' => '2026-01-12', 'nights' => 2]);
    $doc = $ids->record($old, $this->manager, ['type' => 'drivers_licence', 'number' => 'LAG12345AB', 'nationality' => 'Nigerian', 'image_base64' => DESK_PNG]);
    expect($doc->retain_until->toDateString())->toBe('2026-07-12');
    $path = $doc->image_path;
    $stillIn = ($this->inHouse)();
    $kept = $ids->record($stillIn, $this->manager, ['type' => 'passport', 'number' => 'B7654321']);
    $kept->forceFill(['retain_until' => '2026-01-01'])->save();
    $fresh = $ids->record(hostBooking($this->rooms[2], ['booking_status' => BookingStatus::CheckedOut, 'checkin_date' => '2026-10-01', 'checkout_date' => '2026-10-02', 'nights' => 1]), $this->manager, ['type' => 'passport', 'number' => 'C1111111']);

    // Another tenant's expired ID is purged by the same run.
    $other = Tenant::factory()->create(['slug' => 'other-purge']);
    $other->makeCurrent();
    $otherOp = Operator::factory()->for($other)->create(['role' => OperatorRole::Manager]);
    $otherDoc = $ids->record(hostBooking(hostBranch($other, 'Other', 1, '5')['rooms'][0], ['booking_status' => BookingStatus::CheckedOut, 'checkin_date' => '2026-01-01', 'checkout_date' => '2026-01-02', 'nights' => 1]), $otherOp, ['type' => 'passport', 'number' => 'Z9999999']);
    Tenant::forgetCurrent();

    $this->artisan('guests:purge-id-documents')->assertSuccessful()->expectsOutputToContain('purged: 2');

    $this->tenant->makeCurrent();
    $doc->refresh();
    expect($doc->purged_at)->not->toBeNull()
        ->and($doc->type)->toBe('drivers_licence')
        ->and($doc->number)->toBeNull()->and($doc->nationality)->toBeNull()->and($doc->image_path)->toBeNull()
        ->and($doc->maskedNumber())->toBeNull()
        ->and(Storage::disk((string) config('media-library.disk_name'))->exists($path))->toBeFalse()
        ->and($kept->fresh()->purged_at)->toBeNull()
        ->and($fresh->fresh()->purged_at)->toBeNull();
    expect(GuestIdDocument::query()->withoutGlobalScopes()->find($otherDoc->id)->purged_at)->not->toBeNull();

    $res = $this->getJson(hostUrl($this->tenant, "bookings/{$old->booking_ref}"), ($this->h)())->assertOk();
    $res->assertJsonPath('data.id_document.purged', true)->assertJsonPath('data.id_document.number', null)
        ->assertJsonPath('data.id_document.type_label', "Driver's licence");
});

it('shows the ID masked on the web reservation page', function (): void {
    $b = ($this->inHouse)();
    app(GuestIdDocuments::class)->record($b, $this->manager, ['type' => 'passport', 'number' => 'A08812345', 'nationality' => 'Ghanaian']);

    Livewire::actingAs($this->manager, 'web')
        ->test(ReservationShow::class, ['bookingRef' => $b->booking_ref])
        ->assertSee('A0881****')
        ->assertSee('Ghanaian')
        ->assertDontSee('A08812345');
});

// ── Receipts + folio ─────────────────────────────────────────────

it('returns a structured 80 mm receipt, with a web pdf link only for roles that can open it', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Amaka Bello', 'total_amount' => 60_000, 'balance_due' => 20_000, 'booking_status' => BookingStatus::OnHold]);
    $p = Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'paystack_reference' => 'manual-r1', 'amount' => 40_000,
        'type' => PaymentType::Deposit, 'method' => 'transfer', 'status' => PaymentStatus::Succeeded, 'paid_at' => now(),
        'collected_by_operator_id' => $this->manager->id, 'reference_note' => 'GTB 0012',
    ]);

    $res = $this->getJson(hostUrl($this->tenant, "payments/{$p->id}/receipt"), ($this->h)())->assertOk();
    expect($res->json('data'))->toMatchArray([
        'hotel' => ['name' => 'Ikoyi', 'address' => '12 Bourdillon Rd, Lagos, Lagos', 'phone' => '+2348000000001', 'email' => $this->tenant->email ?: null],
        'number' => 'manual-r1', 'guest' => 'Amaka Bello', 'room' => '101', 'booking_ref' => $b->booking_ref,
        'lines' => [['label' => 'Deposit', 'amount' => 40_000]],
        'method' => 'transfer', 'method_label' => 'Bank transfer', 'reference' => 'GTB 0012',
        'paid' => 40_000, 'balance_after' => 20_000, 'balance_now' => 20_000, 'served_by' => 'Chidi Okafor',
        'printed_by' => 'Ngozi Eze', 'pdf_url' => null,
    ]);
    $url = $this->getJson(hostUrl($this->tenant, "payments/{$p->id}/receipt"), ($this->h)($this->manager))->json('data.pdf_url');
    expect($url)->toContain('/auth/app-handoff?code=');

    $abuja = hostBooking($this->abujaRoom);
    $ap = Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $abuja->id, 'paystack_reference' => 'manual-r2', 'amount' => 1_000,
        'type' => PaymentType::Full, 'method' => 'cash', 'status' => PaymentStatus::Succeeded, 'paid_at' => now(),
    ]);
    $this->getJson(hostUrl($this->tenant, "payments/{$ap->id}/receipt"), ($this->h)())->assertNotFound();
    $this->getJson(hostUrl($this->tenant, 'payments/999999/receipt'), ($this->h)())->assertNotFound();
});

it('returns the folio with lines that add up, payments and the balance', function (): void {
    $b = hostBooking($this->rooms[0], ['guest_name' => 'Amaka Bello', 'total_amount' => 60_000, 'room_total' => 60_000, 'balance_due' => 20_000, 'booking_status' => BookingStatus::CheckedIn]);
    Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'booking_id' => $b->id, 'paystack_reference' => 'manual-f1', 'amount' => 40_000,
        'type' => PaymentType::Deposit, 'method' => 'cash', 'status' => PaymentStatus::Succeeded, 'paid_at' => now(),
    ]);
    app(AddBookingCharge::class)->handle($b, $this->manager, BookingChargeType::Laundry, 'Laundry', 3_000);
    $waived = app(AddBookingCharge::class)->handle($b, $this->manager, BookingChargeType::Damage, 'Broken glass', 5_000);
    app(SettleCharges::class)->settleOne($waived, $this->manager, 'waived');

    $d = $this->getJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/invoice"), ($this->h)())->assertOk()->json('data');

    expect(collect($d['lines'])->map(fn ($l) => [$l['label'], $l['amount']])->all())->toBe([['Room 101, 2 nights', 60_000], ['Laundry', 3_000]])
        ->and($d['total'])->toBe(63_000)
        ->and($d['paid'])->toBe(40_000)
        ->and($d['balance'])->toBe(23_000)
        ->and($d['payments'][0])->toMatchArray(['method_label' => 'Cash', 'amount' => 40_000])
        ->and($d['invoice'])->toBeNull()
        ->and($d['pdf_url'])->toBeNull()
        ->and($d['guest']['name'])->toBe('Amaka Bello');

    $abuja = hostBooking($this->abujaRoom);
    $this->getJson(hostUrl($this->tenant, "bookings/{$abuja->booking_ref}/invoice"), ($this->h)())->assertNotFound();
});

// ── Bookings list views ──────────────────────────────────────────

it('lists in-house guests with balance, nights left and flags', function (): void {
    $owing = ($this->inHouse)(['guest_name' => 'Owing Guest', 'balance_due' => 15_000, 'manual_discount_amount' => 2_000]);
    $late = hostBooking($this->rooms[1], ['guest_name' => 'Late Leaver', 'booking_status' => BookingStatus::CheckedIn, 'checkin_date' => '2026-10-20', 'checkout_date' => '2026-10-22', 'nights' => 2]);
    GuestRequest::query()->create(['tenant_id' => $this->tenant->id, 'booking_id' => $late->id, 'room_id' => $this->rooms[1]->id, 'kind' => GuestRequestKind::cases()[0], 'status' => 'open']);
    hostBooking($this->abujaRoom, ['booking_status' => BookingStatus::CheckedIn, 'checkin_date' => '2026-10-22', 'checkout_date' => '2026-10-24']);

    $res = $this->getJson(hostUrl($this->tenant, 'bookings?view=in_house'), ($this->h)())->assertOk();

    expect(collect($res->json('data'))->pluck('guest_name')->all())->toBe(['Late Leaver', 'Owing Guest']);
    // Overdue: the unbilled overstay night is owed.
    expect($res->json('data.0'))->toMatchArray(['overdue' => true, 'nights_left' => 0, 'balance_due' => 30_000])
        ->and($res->json('data.0.flags'))->toBe(['owing' => true, 'overdue' => true, 'company' => false, 'discount' => false, 'request_open' => true])
        ->and($res->json('data.1'))->toMatchArray(['balance_due' => 15_000, 'nights_left' => 2])
        ->and($res->json('data.1.flags'))->toBe(['owing' => true, 'overdue' => false, 'company' => false, 'discount' => true, 'request_open' => false])
        ->and($res->json('meta'))->toBe(['view' => 'in_house', 'page' => 1, 'per_page' => 50, 'total' => 2, 'has_more' => false]);
});

it('filters bookings by view, search, source and dates, with paging', function (): void {
    hostBooking($this->rooms[0], ['guest_name' => 'Future One', 'checkin_date' => '2026-10-30', 'checkout_date' => '2026-11-01', 'source' => 'phone']);
    hostBooking($this->rooms[1], ['guest_name' => 'Future Two', 'guest_phone' => '+234 803 777 1234', 'checkin_date' => '2026-11-20', 'checkout_date' => '2026-11-21']);
    hostBooking($this->rooms[2], ['guest_name' => 'Gone Guest', 'booking_status' => BookingStatus::CheckedOut, 'checkin_date' => '2026-10-01', 'checkout_date' => '2026-10-03']);
    hostBooking($this->rooms[3], ['guest_name' => 'No Show', 'booking_status' => BookingStatus::NoShow, 'checkin_date' => '2026-10-10', 'checkout_date' => '2026-10-11']);
    hostBooking($this->rooms[3], ['guest_name' => 'Called Off', 'booking_status' => BookingStatus::Cancelled, 'checkin_date' => '2026-10-28', 'checkout_date' => '2026-10-29']);

    $res = $this->getJson(hostUrl($this->tenant, 'bookings?view=upcoming'), ($this->h)())->assertOk();
    expect(collect($res->json('data'))->pluck('guest_name')->all())->toBe(['Future One', 'Future Two'])
        ->and($res->json('counts'))->toBe(['upcoming' => 2, 'in_house' => 0, 'past' => 2, 'cancelled' => 1, 'all' => 5]);

    expect(collect($this->getJson(hostUrl($this->tenant, 'bookings?view=past'), ($this->h)())->json('data'))->pluck('guest_name')->all())->toBe(['No Show', 'Gone Guest'])
        ->and($this->getJson(hostUrl($this->tenant, 'bookings?view=all&q=7771234'), ($this->h)())->json('data.0.guest_name'))->toBe('Future Two')
        ->and($this->getJson(hostUrl($this->tenant, 'bookings?view=all&source=phone'), ($this->h)())->json('meta.total'))->toBe(1)
        ->and(collect($this->getJson(hostUrl($this->tenant, 'bookings?view=all&from=2026-10-25&to=2026-10-31'), ($this->h)())->json('data'))->pluck('guest_name')->sort()->values()->all())->toBe(['Called Off', 'Future One']);

    $page = $this->getJson(hostUrl($this->tenant, 'bookings?view=all&per_page=2&page=2'), ($this->h)())->assertOk();
    expect($page->json('meta'))->toBe(['view' => 'all', 'page' => 2, 'per_page' => 2, 'total' => 5, 'has_more' => true])
        ->and(count($page->json('data')))->toBe(2);

    $this->getJson(hostUrl($this->tenant, 'bookings?view=nope'), ($this->h)())->assertStatus(422);
});

it('returns the new payment ids so the desk prints the right receipt', function (): void {
    $b = ($this->inHouse)(['balance_due' => 30_000]);
    $res = $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/payments"), ['amount' => 30_000, 'method' => 'cash'], ($this->w)())
        ->assertOk();

    $ids = $res->json('payment_ids');
    expect($ids)->toBeArray()->not->toBeEmpty();
    expect(Payment::query()->whereIn('id', $ids)->sum('amount'))->toEqual(30_000);
    $this->getJson(hostUrl($this->tenant, 'payments/'.end($ids).'/receipt'), ($this->h)())->assertOk()->assertJsonPath('data.paid', 30_000);
});

it('resends the confirmation for a live booking and refuses a cancelled one', function (): void {
    Event::fake([BookingConfirmed::class]);
    $b = ($this->inHouse)();
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/resend-confirmation"), [], ($this->w)())
        ->assertOk()->assertJson(['status' => 'ok', 'sent' => true]);
    Event::assertDispatched(BookingConfirmed::class);

    $b->forceFill(['booking_status' => BookingStatus::Cancelled])->save();
    $this->postJson(hostUrl($this->tenant, "bookings/{$b->booking_ref}/resend-confirmation"), [], ($this->w)())
        ->assertStatus(422)->assertJson(['error' => 'action_failed']);
});
