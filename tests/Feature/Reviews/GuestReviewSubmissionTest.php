<?php

declare(strict_types=1);

use App\Actions\Reviews\SubmitGuestReview;
use App\Enums\BookingStatus;
use App\Livewire\Guest\Review as GuestReview;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Bookings\GuestPortalTokenService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'guest-rev']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->live()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Reviewed Suite',
    ]);
    $this->booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'booking_ref' => 'KV-REVIEW01',
        'guest_name' => 'Guest Reviewer',
        'guest_email' => 'guest@example.com',
    ]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
});

/* ---------- SubmitGuestReview action ---------- */

it('creates a verified-stay review for a CheckedOut booking', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();

    $review = app(SubmitGuestReview::class)->handle($this->booking, 5, 'Loved everything about it.');

    expect($review->booking_id)->toBe($this->booking->id);
    expect($review->room_id)->toBe($this->room->id);
    expect($review->rating)->toBe(5);
    expect($review->is_displayed)->toBeTrue();
    expect($review->isVerifiedStay())->toBeTrue();
});

it('rejects submission before check-out', function (): void {
    // booking_status is Confirmed (factory default)
    expect(fn () => app(SubmitGuestReview::class)->handle($this->booking, 5, 'too early'))
        ->toThrow(RuntimeException::class);
});

it('rejects a second review on the same booking', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();
    app(SubmitGuestReview::class)->handle($this->booking, 5, 'first review');

    expect(fn () => app(SubmitGuestReview::class)->handle($this->booking, 4, 'sneaking another in'))
        ->toThrow(RuntimeException::class);
});

it('rejects out-of-range ratings', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();

    expect(fn () => app(SubmitGuestReview::class)->handle($this->booking, 0, 'body'))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(SubmitGuestReview::class)->handle($this->booking, 6, 'body'))
        ->toThrow(RuntimeException::class);
});

it('rejects an empty body', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();

    expect(fn () => app(SubmitGuestReview::class)->handle($this->booking, 5, '   '))
        ->toThrow(RuntimeException::class);
});

/* ---------- Guest portal Livewire ---------- */

it('renders the review form for a CheckedOut booking', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();
    $token = app(GuestPortalTokenService::class)->issue($this->booking)['plaintext'];

    $component = Livewire::withQueryParams(['token' => $token])
        ->test(GuestReview::class, ['bookingRef' => $this->booking->booking_ref])
        ->set('rating', 5)
        ->set('body', 'A thoughtful piece of feedback.')
        ->call('submit')
        ->assertHasNoErrors();

    expect($component->get('submitted'))->toBeTrue();
    expect(Review::query()->where('booking_id', $this->booking->id)->exists())->toBeTrue();
});

it('shows an informational state before check-out', function (): void {
    $token = app(GuestPortalTokenService::class)->issue($this->booking)['plaintext'];

    Livewire::withQueryParams(['token' => $token])
        ->test(GuestReview::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertSee('Reviews unlock once your stay is complete');
});

it('shows the existing review when one already exists', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();
    Review::factory()->for($this->tenant)->for($this->property)->forBooking($this->booking)
        ->create(['body' => 'My previously-submitted thoughts.']);
    $token = app(GuestPortalTokenService::class)->issue($this->booking)['plaintext'];

    Livewire::withQueryParams(['token' => $token])
        ->test(GuestReview::class, ['bookingRef' => $this->booking->booking_ref])
        ->assertSee('You already submitted a review')
        ->assertSee('My previously-submitted thoughts.');
});

/* ---------- Public visibility ---------- */

it('renders the Verified stay badge on reviews tied to a booking', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();
    Review::factory()->for($this->tenant)->for($this->property)->forBooking($this->booking)
        ->create();

    $response = $this->get('http://guest-rev.keverna.test/');
    $response->assertOk();
    $response->assertSeeHtml('data-testid="verified-stay"');
});

it('does NOT render the Verified stay badge on operator-authored reviews', function (): void {
    Review::factory()->for($this->tenant)->for($this->property)->create();

    $response = $this->get('http://guest-rev.keverna.test/');
    $response->assertOk();
    $response->assertDontSeeHtml('data-testid="verified-stay"');
});

it('exposes a "Leave a review" link on the guest portal Show after checkout', function (): void {
    $this->booking->forceFill(['booking_status' => BookingStatus::CheckedOut])->save();
    $token = app(GuestPortalTokenService::class)->issue($this->booking)['plaintext'];

    $response = $this->get('http://guest-rev.keverna.test/guest/booking/'.$this->booking->booking_ref.'?token='.$token);

    $response->assertOk();
    $response->assertSeeHtml('data-testid="review-link"');
});
