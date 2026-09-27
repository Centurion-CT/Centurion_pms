<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\CreateBookingRequest;
use App\Enums\BookingSource;
use App\Enums\PaymentType;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Paystack + Keverna platform fees ride on top of the room subtotal
 * ONLY when the booking settles through the guest-facing engine
 * (BookingSource::Online). For walk-in / phone / OTA / etc. the
 * operator collects directly, so bolting those fees on over-bills
 * the guest — "this room is ₦50k, why is the total ₦51,100?".
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'fees-by-source']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0,
        'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id,
        'status' => RoomStatus::Active->value,
    ]);
});

afterEach(fn () => Tenant::forgetCurrent());

function makeReq(BookingSource $source, Room $room): CreateBookingRequest
{
    return new CreateBookingRequest(
        guestName: 'Chi Guest',
        guestEmail: 'chi@x.test',
        guestPhone: '08012345678',
        adults: 1,
        children: 0,
        checkin: Carbon::today()->addDay(),
        checkout: Carbon::today()->addDays(2),   // 1 night × ₦50,000
        paymentType: PaymentType::Full,
        source: $source,
    );
}

it('does NOT charge Paystack / Keverna fees on a walk-in booking', function (): void {
    $b = app(CreateBooking::class)->handle($this->room, makeReq(BookingSource::WalkIn, $this->room));

    expect((int) $b->paystack_fee)->toBe(0);
    expect((int) $b->keverna_fee)->toBe(0);
    expect((int) $b->total_amount)->toBe(50_000);   // ← the number in the summary card
});

it('does NOT charge platform fees on an OTA / phone / WhatsApp booking', function (): void {
    $sources = [BookingSource::Phone, BookingSource::WhatsApp, BookingSource::BookingCom, BookingSource::Airbnb, BookingSource::Direct];
    foreach ($sources as $i => $src) {
        // Stagger dates so each iteration has a fresh availability window.
        $checkin = Carbon::today()->addDays(10 + ($i * 3));
        $req = new CreateBookingRequest(
            guestName: 'Chi Guest',
            guestEmail: 'chi@x.test',
            guestPhone: '08012345678',
            adults: 1,
            children: 0,
            checkin: $checkin,
            checkout: $checkin->copy()->addDay(),
            paymentType: PaymentType::Full,
            source: $src,
        );
        $b = app(CreateBooking::class)->handle($this->room, $req);
        expect((int) $b->total_amount)->toBe(50_000, "source: {$src->value}");
        expect((int) $b->paystack_fee)->toBe(0, "source: {$src->value}");
        expect((int) $b->keverna_fee)->toBe(0, "source: {$src->value}");
    }
});

it('DOES charge Paystack + Keverna fees on an Online (guest-engine) booking', function (): void {
    $b = app(CreateBooking::class)->handle($this->room, makeReq(BookingSource::Online, $this->room));

    // Fee model post-marketplace launch (per config/keverna.php):
    //   Paystack = 1.5% × 50,000 (no flat portion) = 750
    //   Keverna  = 5.0% × 50,000                    = 2,500
    expect((int) $b->paystack_fee)->toBe(750);
    expect((int) $b->keverna_fee)->toBe(2_500);
    expect((int) $b->total_amount)->toBe(50_000 + 750 + 2_500);
});
