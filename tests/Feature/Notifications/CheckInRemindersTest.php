<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Mail\Bookings\CheckInReminderMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('emails Confirmed + OnHold bookings checking in tomorrow', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 14:00:00'));

    $confirmed = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'conf@x.test',
        'checkin_date' => '2030-09-16',
        'checkout_date' => '2030-09-18',
    ]);
    $onHold = Booking::factory()->for($this->tenant)->for($this->room)->onHold()->create([
        'guest_email' => 'hold@x.test',
        'checkin_date' => '2030-09-16',
        'checkout_date' => '2030-09-18',
    ]);

    $this->artisan('bookings:send-checkin-reminders')->assertSuccessful();

    Mail::assertQueued(CheckInReminderMail::class, fn ($m) => $m->hasTo('conf@x.test'));
    Mail::assertQueued(CheckInReminderMail::class, fn ($m) => $m->hasTo('hold@x.test'));

    expect($confirmed->fresh()->checkin_reminder_sent_at)->not->toBeNull();
    expect($onHold->fresh()->checkin_reminder_sent_at)->not->toBeNull();
});

it('does NOT email bookings checking in today', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 14:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'today@x.test',
        'checkin_date' => '2030-09-15',
    ]);

    $this->artisan('bookings:send-checkin-reminders');

    Mail::assertNotQueued(CheckInReminderMail::class);
});

it('does NOT email bookings checking in 3 days from now', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 14:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'future@x.test',
        'checkin_date' => '2030-09-18',
    ]);

    $this->artisan('bookings:send-checkin-reminders');

    Mail::assertNotQueued(CheckInReminderMail::class);
});

it('does NOT email Pending or Cancelled bookings even if checking in tomorrow', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 14:00:00'));

    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_email' => 'pending@x.test',
        'checkin_date' => '2030-09-16',
        'booking_status' => BookingStatus::Pending,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create([
        'guest_email' => 'cancelled@x.test',
        'checkin_date' => '2030-09-16',
    ]);

    $this->artisan('bookings:send-checkin-reminders');

    Mail::assertNotQueued(CheckInReminderMail::class);
});

it('is idempotent — second run does not re-mail', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 14:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'once@x.test',
        'checkin_date' => '2030-09-16',
    ]);

    $this->artisan('bookings:send-checkin-reminders');
    $this->artisan('bookings:send-checkin-reminders');

    Mail::assertQueued(CheckInReminderMail::class, 1);
});

it('scans across tenants in a single run', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 14:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_email' => 'tenant-a@x.test', 'checkin_date' => '2030-09-16',
    ]);

    Tenant::forgetCurrent();
    $tenantB = Tenant::factory()->create();
    $tenantB->makeCurrent();
    $propB = Property::factory()->for($tenantB)->create();
    $roomB = Room::factory()->for($tenantB)->for($propB)->create();
    Booking::factory()->for($tenantB)->for($roomB)->confirmed()->create([
        'guest_email' => 'tenant-b@x.test', 'checkin_date' => '2030-09-16',
    ]);
    Tenant::forgetCurrent();

    $this->artisan('bookings:send-checkin-reminders');

    Mail::assertQueued(CheckInReminderMail::class, fn ($m) => $m->hasTo('tenant-a@x.test'));
    Mail::assertQueued(CheckInReminderMail::class, fn ($m) => $m->hasTo('tenant-b@x.test'));
});
