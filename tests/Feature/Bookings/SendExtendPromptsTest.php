<?php

declare(strict_types=1);

use App\Console\Commands\Bookings\SendExtendPrompts;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\GuestPortal\GuestNotifier;
use Illuminate\Support\Carbon;

/**
 * Day-before extend nudge - in-house guests checking out tomorrow
 * get a WhatsApp with a portal link. Idempotent via the
 * extend_prompt_sent_at timestamp on bookings.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'ext-nudge']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create([
        'portal_qr_slug' => 'test-portal-slug',
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 40_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->for($this->category, 'category')->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('sends an extend prompt to guests checking out tomorrow', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_name' => 'Amara Okafor',
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::tomorrow(),
        'nights' => 2, 'total_amount' => 80_000, 'room_total' => 80_000, 'balance_due' => 0,
    ]);

    // Spy on the notifier - we don't want a real WhatsApp attempt.
    $notifier = Mockery::mock(GuestNotifier::class);
    $notifier->shouldReceive('sendExtendPrompt')->once()->withArgs(
        fn ($phone, $name, $property, $url) =>
            $phone === '+2348012345678'
            && $name === 'Amara Okafor'
            && str_contains($url, '/portal/test-portal-slug'),
    );
    app()->instance(GuestNotifier::class, $notifier);

    $this->artisan(SendExtendPrompts::class)->assertOk();
    expect($booking->fresh()->extend_prompt_sent_at)->not->toBeNull();
});

it('skips a guest who has already been prompted', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::tomorrow(),
        'nights' => 2, 'total_amount' => 80_000, 'room_total' => 80_000, 'balance_due' => 0,
        'extend_prompt_sent_at' => Carbon::yesterday(),
    ]);
    $originalStamp = $booking->extend_prompt_sent_at;

    $notifier = Mockery::mock(GuestNotifier::class);
    $notifier->shouldNotReceive('sendExtendPrompt');
    app()->instance(GuestNotifier::class, $notifier);

    $this->artisan(SendExtendPrompts::class)->assertOk();
    expect($booking->fresh()->extend_prompt_sent_at->toDateTimeString())
        ->toBe($originalStamp->toDateTimeString());
});

it('stamps but does not send when the room is booked back-to-back', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::tomorrow(),
        'nights' => 2, 'total_amount' => 80_000, 'room_total' => 80_000, 'balance_due' => 0,
    ]);
    // Next guest checks in tomorrow (same day this one checks out).
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date' => Carbon::tomorrow(),
        'checkout_date' => Carbon::tomorrow()->addDays(2),
    ]);

    $notifier = Mockery::mock(GuestNotifier::class);
    $notifier->shouldNotReceive('sendExtendPrompt');
    app()->instance(GuestNotifier::class, $notifier);

    $this->artisan(SendExtendPrompts::class)->assertOk();
    // Stamped so we don't reconsider on the next run.
    expect($booking->fresh()->extend_prompt_sent_at)->not->toBeNull();
});

it('does not prompt bookings that check out in more than a day', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'guest_phone' => '+2348012345678',
        'checkin_date' => Carbon::today(),
        'checkout_date' => Carbon::today()->addDays(3),
        'nights' => 3, 'total_amount' => 120_000, 'room_total' => 120_000, 'balance_due' => 0,
    ]);

    $notifier = Mockery::mock(GuestNotifier::class);
    $notifier->shouldNotReceive('sendExtendPrompt');
    app()->instance(GuestNotifier::class, $notifier);

    $this->artisan(SendExtendPrompts::class)->assertOk();
});
