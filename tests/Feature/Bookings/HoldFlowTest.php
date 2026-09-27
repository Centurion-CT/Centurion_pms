<?php

declare(strict_types=1);

use App\Actions\Bookings\ConfirmBookingPayment;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Actions\Bookings\InitiateBalancePayment;
use App\Enums\AvailabilityBlockType;
use App\Enums\BookingStatus;
use App\Enums\DepositType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Mail\Bookings\BalanceReminderMail;
use App\Models\AvailabilityBlock;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config()->set('services.paystack.base_url', 'https://api.paystack.test');

    $this->tenant = Tenant::factory()->create([
        'slug' => 'hold-flow', 'paystack_secret_key' => 'sk_op',
    ]);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    // Pricing fields live on RoomCategory now; Room only holds the
    // per-unit fields (room_number, status, qr_slug).
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000,
        'deposit_type' => DepositType::Percent,
        'deposit_value' => 30,
        'min_nights' => 1,
        'capacity_adults' => 2,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* --------------------------------------------------------------------------
 *  CreateBooking populates hold fields
 * ------------------------------------------------------------------------ */

it('populates balance_due_at = checkin_date when paymentType=Deposit', function (): void {
    $checkin = Carbon::parse('2030-09-10');
    $b = app(CreateBooking::class)->handle($this->room, new CreateBookingRequest(
        guestName: 'A', guestEmail: 'a@x.test', guestPhone: '+2348012345678',
        adults: 1, children: 0,
        checkin: $checkin, checkout: $checkin->copy()->addDays(3),
        paymentType: PaymentType::Deposit,
    ));

    expect($b->balance_due_at)->not->toBeNull();
    expect($b->balance_due_at->toDateString())->toBe('2030-09-10');
    expect($b->deposit_amount)->toBeGreaterThan(0);
    expect($b->balance_due)->toBe($b->total_amount - $b->deposit_amount);
});

it('leaves balance_due_at null on Full-payment bookings', function (): void {
    $b = app(CreateBooking::class)->handle($this->room, new CreateBookingRequest(
        guestName: 'A', guestEmail: 'a@x.test', guestPhone: '+2348012345678',
        adults: 1, children: 0,
        checkin: Carbon::tomorrow(), checkout: Carbon::tomorrow()->addDays(2),
        paymentType: PaymentType::Full,
    ));

    expect($b->balance_due_at)->toBeNull();
});

/* --------------------------------------------------------------------------
 *  Webhook: deposit -> OnHold; balance -> Confirmed
 * ------------------------------------------------------------------------ */

it('Deposit payment success flips booking to OnHold and preserves balance_due', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 100_000, 'deposit_amount' => 30_000, 'balance_due' => 70_000,
        'balance_due_at' => Carbon::parse('2030-09-10'),
    ]);
    $p = Payment::factory()->for($this->tenant)->for($b)->create([
        'amount' => 30_000, 'type' => PaymentType::Deposit,
    ]);

    app(ConfirmBookingPayment::class)->handle($p);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::OnHold);
    expect($b->fresh()->balance_due)->toBe(70_000);
});

it('Balance payment success flips booking to Confirmed and clears balance_due', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'total_amount' => 100_000, 'deposit_amount' => 30_000, 'balance_due' => 70_000,
        'balance_due_at' => Carbon::parse('2030-09-10'),
        'booking_status' => BookingStatus::OnHold,
        'payment_status' => PaymentStatus::Succeeded,
    ]);
    $bal = Payment::factory()->for($this->tenant)->for($b)->create([
        'amount' => 70_000, 'type' => PaymentType::Balance,
    ]);

    app(ConfirmBookingPayment::class)->handle($bal);

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
    expect($b->fresh()->balance_due)->toBe(0);
});

/* --------------------------------------------------------------------------
 *  InitiateBalancePayment
 * ------------------------------------------------------------------------ */

it('initiates a Balance payment using the operator key (Paystack call)', function (): void {
    Http::fake(['https://api.paystack.test/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.test/BAL', 'access_code' => 'ac', 'reference' => 'r'],
    ])]);

    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'total_amount' => 100_000,
        'balance_due_at' => Carbon::parse('2030-09-10'),
    ]);

    $url = app(InitiateBalancePayment::class)->handle($b);

    expect($url)->toBe('https://checkout.paystack.test/BAL');

    $p = Payment::query()->where('booking_id', $b->id)->where('type', PaymentType::Balance->value)->first();
    expect($p)->not->toBeNull();
    expect($p->amount)->toBe($b->balance_due);
    expect($p->status)->toBe(PaymentStatus::Pending);

    Http::assertSent(fn ($r) => $r->header('Authorization')[0] === 'Bearer sk_op'
        && $r['amount'] === $b->balance_due * 100);
});

it('refuses InitiateBalancePayment on a non-OnHold booking', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    expect(fn () => app(InitiateBalancePayment::class)->handle($b))
        ->toThrow(RuntimeException::class);
});

it('refuses InitiateBalancePayment when balance_due is zero', function (): void {
    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold, 'balance_due' => 0,
    ]);

    expect(fn () => app(InitiateBalancePayment::class)->handle($b))
        ->toThrow(RuntimeException::class);
});

/* --------------------------------------------------------------------------
 *  SendBalanceReminders
 * ------------------------------------------------------------------------ */

it('queues 7/3/1-day reminders at the right milestones (Mail::fake + time travel)', function (): void {
    Mail::fake();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'total_amount' => 100_000,
        'balance_due_at' => Carbon::parse('2030-10-01'),
        'guest_email' => 'guest@x.test',
    ]);

    // 7 days out
    Carbon::setTestNow(Carbon::parse('2030-09-24'));
    $this->artisan('bookings:send-balance-reminders');
    Mail::assertQueued(BalanceReminderMail::class, fn ($m) => $m->daysBefore === 7);

    // 3 days out
    Carbon::setTestNow(Carbon::parse('2030-09-28'));
    $this->artisan('bookings:send-balance-reminders');
    Mail::assertQueued(BalanceReminderMail::class, fn ($m) => $m->daysBefore === 3);

    // 1 day out
    Carbon::setTestNow(Carbon::parse('2030-09-30'));
    $this->artisan('bookings:send-balance-reminders');
    Mail::assertQueued(BalanceReminderMail::class, fn ($m) => $m->daysBefore === 1);

    Mail::assertQueued(BalanceReminderMail::class, 3);
});

it('is idempotent — re-running on the same day does not re-send', function (): void {
    Mail::fake();

    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::today()->addDays(7),
    ]);

    $this->artisan('bookings:send-balance-reminders');
    $this->artisan('bookings:send-balance-reminders');

    Mail::assertQueued(BalanceReminderMail::class, 1);
});

it('does NOT remind on a Confirmed booking', function (): void {
    Mail::fake();

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'balance_due_at' => Carbon::today()->addDays(7),
    ]);

    $this->artisan('bookings:send-balance-reminders');

    Mail::assertNothingQueued();
});

it('does NOT remind on an OnHold booking with balance_due = 0 (paid early)', function (): void {
    Mail::fake();

    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold,
        'balance_due' => 0,
        'balance_due_at' => Carbon::today()->addDays(7),
    ]);

    $this->artisan('bookings:send-balance-reminders');

    Mail::assertNothingQueued();
});

/* --------------------------------------------------------------------------
 *  AutoCancelExpiredHolds
 * ------------------------------------------------------------------------ */

it('cancels an OnHold booking past its deadline and releases its Booked nights', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-10-02 10:00:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::parse('2030-10-01'),
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->createMany([
            ['date' => '2030-10-05', 'booking_id' => $b->id],
            ['date' => '2030-10-06', 'booking_id' => $b->id],
        ]);

    $this->artisan('bookings:auto-cancel-expired-holds')->assertSuccessful();

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
    expect(AvailabilityBlock::where('booking_id', $b->id)->count())->toBe(0);
});

it('leaves an OnHold booking BEFORE its deadline alone', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-30 10:00:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::parse('2030-10-01'),
    ]);

    $this->artisan('bookings:auto-cancel-expired-holds');

    expect($b->fresh()->booking_status)->toBe(BookingStatus::OnHold);
});

it('does NOT touch Confirmed bookings even if balance_due_at is past', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-10-02'));
    $b = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'balance_due_at' => Carbon::parse('2030-09-01'),
    ]);

    $this->artisan('bookings:auto-cancel-expired-holds');

    expect($b->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
});

it('preserves operator Blocked rows when auto-cancelling (only Booked deleted)', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-10-02'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::parse('2030-10-01'),
    ]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)->booked()
        ->create(['date' => '2030-10-05', 'booking_id' => $b->id]);
    AvailabilityBlock::factory()->for($this->tenant)->for($this->room)
        ->create(['date' => '2030-10-06', 'type' => AvailabilityBlockType::Blocked]);

    $this->artisan('bookings:auto-cancel-expired-holds');

    expect(AvailabilityBlock::count())->toBe(1);
    expect(AvailabilityBlock::first()->type)->toBe(AvailabilityBlockType::Blocked);
});

it('is idempotent — second run does nothing new', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-10-02'));
    Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::parse('2030-10-01'),
    ]);

    $this->artisan('bookings:auto-cancel-expired-holds');
    $this->artisan('bookings:auto-cancel-expired-holds')->assertSuccessful();

    expect(Booking::where('booking_status', BookingStatus::Cancelled->value)->count())->toBe(1);
});

it('processes expired holds across multiple tenants', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-10-02'));

    $b1 = Booking::factory()->for($this->tenant)->for($this->room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::parse('2030-10-01'),
    ]);

    Tenant::forgetCurrent();
    $other = Tenant::factory()->create();
    $other->makeCurrent();
    $prop = Property::factory()->for($other)->create();
    $room = Room::factory()->for($other)->for($prop)->create();
    $b2 = Booking::factory()->for($other)->for($room)->onHold(30_000)->create([
        'balance_due_at' => Carbon::parse('2030-10-01'),
    ]);
    Tenant::forgetCurrent();

    $this->artisan('bookings:auto-cancel-expired-holds');

    expect($b1->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
    expect($b2->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
});
