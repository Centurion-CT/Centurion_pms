<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBooking;
use App\Events\TenantOnboarded;
use App\Jobs\Domains\ProvisionTenantSubdomain;
use App\Mail\Bookings\BookingCancelledMail;
use App\Mail\Operators\OperatorWelcomeMail;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    // ProvisionTenantSubdomain wakes up on TenantOnboarded and stamps
    // the tenant + dispatches the Dokwe attach. Tests here don't care
    // about provisioning, so fake it.
    Bus::fake([ProvisionTenantSubdomain::class]);

    $this->tenant = Tenant::factory()->create(['slug' => 'notify-mail', 'email' => 'gm@notify-mail.test']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- BookingCancelledMail ---------- */

it('queues a BookingCancelledMail to guest + operator when CancelBooking runs', function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'guest@notify.test',
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    app(CancelBooking::class)->handle($booking);

    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo('guest@notify.test'));
    Mail::assertQueued(BookingCancelledMail::class, fn ($m) => $m->hasTo('gm@notify-mail.test'));
});

it('marks depositForfeit=true on the mailable when cancelled inside 7 days', function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2030-09-10 10:00:00'));

    $booking = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
        'deposit_amount' => 30_000,
    ]);

    app(CancelBooking::class)->handle($booking);

    Mail::assertQueued(BookingCancelledMail::class, function ($m) {
        return $m->depositForfeit === true;
    });
});

/* ---------- OperatorWelcomeMail ---------- */

it('queues an OperatorWelcomeMail when TenantOnboarded fires', function (): void {
    Mail::fake();

    $operator = Operator::factory()->for($this->tenant)->create([
        'email' => 'opwelcome@notify.test',
    ]);

    TenantOnboarded::dispatch($this->tenant, $operator);

    Mail::assertQueued(OperatorWelcomeMail::class, fn ($m) => $m->hasTo('opwelcome@notify.test'));
});

/* ---------- Branded mail layout ---------- */

it('renders the brand color stripe + tenant name in the mail header', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    // Render the mail synchronously — the BindsBookingTenant trait binds
    // the booking's tenant in headers() so MailBranding picks it up.
    $rendered = (new BookingCancelledMail($booking, depositForfeit: false))->render();

    expect($rendered)->toContain('data-testid="brand-stripe"');
    expect($rendered)->toContain($this->tenant->name);
});
