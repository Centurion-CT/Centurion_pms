<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Mail\Bookings\ReviewPromptMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('emails a CheckedOut booking whose checkout was yesterday', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkout_date' => '2030-09-14',
        'guest_email' => 'review-me@x.test',
    ]);

    $this->artisan('bookings:send-review-prompts')->assertSuccessful();

    Mail::assertQueued(ReviewPromptMail::class, fn ($m) => $m->hasTo('review-me@x.test'));
    expect($b->fresh()->review_prompt_sent_at)->not->toBeNull();
});

it('does NOT email a booking whose checkout was today', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkout_date' => '2030-09-15',
    ]);

    $this->artisan('bookings:send-review-prompts');

    Mail::assertNotQueued(ReviewPromptMail::class);
});

it('does NOT email a booking that is still Confirmed', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkout_date' => '2030-09-14',
    ]);

    $this->artisan('bookings:send-review-prompts');

    Mail::assertNotQueued(ReviewPromptMail::class);
});

it('does NOT email a booking that already has a review', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));

    $b = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkout_date' => '2030-09-14',
    ]);
    Review::factory()->for($this->tenant)->for($this->property)->forBooking($b)->create();

    $this->artisan('bookings:send-review-prompts');

    Mail::assertNotQueued(ReviewPromptMail::class);
});

it('is idempotent — second run does not re-mail', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-15 10:00:00'));
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'checkout_date' => '2030-09-14',
        'guest_email' => 'once@x.test',
    ]);

    $this->artisan('bookings:send-review-prompts');
    $this->artisan('bookings:send-review-prompts');

    Mail::assertQueued(ReviewPromptMail::class, 1);
});
