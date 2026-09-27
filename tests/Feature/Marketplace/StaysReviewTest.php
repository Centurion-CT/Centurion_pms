<?php

declare(strict_types=1);

use App\Actions\Bookings\MarkCheckedOut;
use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Mail\Marketplace\StaysReviewInvite;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Post-stay review pipeline for Keverna Stays marketplace bookings.
 * Every seam is exercised: the invite dispatch at checkout, the
 * signed-URL controller, the one-review-per-booking DB guard, the
 * checkout-only gate + the render on the property detail page.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->tenant = Tenant::factory()->create(['slug' => 'review-test']);
    $this->tenant->makeCurrent();
    Subscription::factory()->for($this->tenant)->trial()->create();

    $this->property = Property::factory()->for($this->tenant)->create(['is_listed_publicly' => true]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active]);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(fn () => Tenant::forgetCurrent());

function signedReviewUrl(string $bookingRef, string $action = 'show'): string
{
    return URL::temporarySignedRoute(
        'marketplace.stays.review.'.$action,
        now()->addDays(90),
        ['booking_ref' => $bookingRef],
    );
}

it('queues a review invite when a marketplace booking is checked out', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'payment_status' => PaymentStatus::Succeeded,
        'source_note' => 'Keverna Stays marketplace',
        'guest_email' => 'guest@example.test',
        'balance_due' => 0,
        'total_amount' => 30_000, 'room_total' => 30_000,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
    ]);

    app(MarkCheckedOut::class)->handle($booking->fresh(), $this->operator);

    Mail::assertQueued(StaysReviewInvite::class, fn ($mail) => $mail->hasTo('guest@example.test'));

    expect($booking->fresh()->review_invite_sent_at)->not->toBeNull();
});

it('does NOT queue an invite for a non-marketplace booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'payment_status' => PaymentStatus::Succeeded,
        'source_note' => 'Walk-in',
        'guest_email' => 'walkin@example.test',
        'balance_due' => 0,
        'total_amount' => 30_000, 'room_total' => 30_000,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
    ]);

    app(MarkCheckedOut::class)->handle($booking->fresh(), $this->operator);

    Mail::assertNothingQueued();
});

it('does not double-queue if the checkout action is re-run', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'payment_status' => PaymentStatus::Succeeded,
        'source_note' => 'Keverna Stays marketplace',
        'guest_email' => 'twice@example.test',
        'balance_due' => 0,
        'total_amount' => 30_000, 'room_total' => 30_000,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'review_invite_sent_at' => Carbon::now()->subMinute(),
    ]);

    app(MarkCheckedOut::class)->handle($booking->fresh(), $this->operator);
    Mail::assertNothingQueued();
});

it('renders the review form for a checked-out booking via a signed URL', function (): void {
    Tenant::forgetCurrent();
    $booking = tap(Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'source_note' => 'Keverna Stays marketplace',
        'guest_name' => 'Ada N.',
        'guest_email' => 'ada@example.test',
        'total_amount' => 30_000,
    ]))->fresh();

    $this->get(signedReviewUrl($booking->booking_ref))
        ->assertOk()
        ->assertSee('How was your stay?')
        ->assertSee($booking->booking_ref);
});

it('rejects an unsigned URL with 403', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'source_note' => 'Keverna Stays marketplace',
    ]);

    $this->get(route('marketplace.stays.review.show', ['booking_ref' => $booking->booking_ref]))
        ->assertForbidden();
});

it('404s the form for a booking that hasn\'t checked out', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'source_note' => 'Keverna Stays marketplace',
    ]);

    $this->get(signedReviewUrl($booking->booking_ref))->assertNotFound();
});

it('persists a valid submission + redirects to thanks', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'source_note' => 'Keverna Stays marketplace',
        'guest_name' => 'Kingsley O.',
    ]);

    $this->post(signedReviewUrl($booking->booking_ref, 'store'), [
        'rating' => 5,
        'body'   => 'Beautiful stay, would come back.',
    ])
        ->assertRedirect(route('marketplace.stays.review.thanks', ['booking_ref' => $booking->booking_ref]));

    $review = Review::query()->withoutGlobalScope(TenantScope::class)
        ->where('booking_id', $booking->id)->firstOrFail();
    expect($review->rating)->toBe(5);
    expect($review->body)->toBe('Beautiful stay, would come back.');
    expect($review->guest_name)->toBe('Kingsley O.');
    expect((bool) $review->is_displayed)->toBeTrue();
    expect($review->tenant_id)->toBe($this->tenant->id);
});

it('rejects a review submission with an invalid rating or empty body', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'source_note' => 'Keverna Stays marketplace',
    ]);

    $this->post(signedReviewUrl($booking->booking_ref, 'store'), [
        'rating' => 9,
        'body'   => 'x',
    ])->assertSessionHasErrors(['rating', 'body']);

    expect(Review::query()->withoutGlobalScope(TenantScope::class)
        ->where('booking_id', $booking->id)->count())->toBe(0);
});

it('short-circuits to already-reviewed when a Review row exists for the booking', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'source_note' => 'Keverna Stays marketplace',
    ]);

    $this->tenant->makeCurrent();
    Review::factory()->create([
        'tenant_id' => $this->tenant->id,
        'property_id' => $this->property->id,
        'room_id' => $this->room->id,
        'booking_id' => $booking->id,
        'rating' => 4,
        'body' => 'Already left this one.',
    ]);
    Tenant::forgetCurrent();

    $this->get(signedReviewUrl($booking->booking_ref))
        ->assertOk()
        ->assertSee("already reviewed");
});

it('shows the new review on the /stays/{slug} property detail page', function (): void {
    Tenant::forgetCurrent();
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedOut,
        'source_note' => 'Keverna Stays marketplace',
        'guest_name' => 'Chika A.',
    ]);

    $this->post(signedReviewUrl($booking->booking_ref, 'store'), [
        'rating' => 5,
        'body'   => 'Verified stay, absolutely worth it.',
    ]);

    $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]))
        ->assertOk()
        ->assertSee('Chika A.')
        ->assertSee('Verified stay, absolutely worth it.')
        ->assertSee('Verified');
});
