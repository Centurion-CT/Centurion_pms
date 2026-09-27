<?php

declare(strict_types=1);

use App\Enums\BookingSource;
use App\Livewire\Dashboard\Bookings\ManualCreate;
use App\Livewire\Dashboard\Calendar\PropertyView;
use App\Livewire\Dashboard\Payments\Index as PaymentsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Reports\ReportSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'channel-tracking']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create();
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- Enum coverage ---------- */

it('exposes real OTA channels in the picker but hides Online + legacy Manual', function (): void {
    $values = array_map(fn (BookingSource $c) => $c->value, BookingSource::pickerCases());

    expect($values)
        ->toContain('walk_in', 'phone', 'whatsapp', 'booking_com', 'airbnb', 'hotels_ng', 'jumia_travel', 'other')
        ->and($values)->not->toContain('online')
        ->and($values)->not->toContain('manual');
});

it('labels every enum case for the reports UI', function (): void {
    foreach (BookingSource::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
});

/* ---------- ManualCreate persists the picked channel ---------- */

it('records the picked channel + free-text note on a manual booking', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('roomId', $this->room->id)
        ->set('guest_name', 'Chinyere Okoye')
        ->set('guest_email', 'chi@example.test')
        ->set('guest_phone', '+2348011112222')
        ->set('adults', 2)
        ->set('children', 0)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(3)->toDateString())
        ->set('source', BookingSource::BookingCom->value)
        ->set('source_note', 'Second account · Ikoyi listing')
        ->call('save')
        ->assertHasNoErrors();

    /** @var Booking $booking */
    $booking = Booking::query()->latest('id')->first();
    expect($booking)->not->toBeNull();
    expect($booking->source)->toBe(BookingSource::BookingCom);
    expect($booking->source_note)->toBe('Second account · Ikoyi listing');
});

it('accepts an "Other" channel with a custom note', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('roomId', $this->room->id)
        ->set('guest_name', 'Emeka Nwosu')
        ->set('guest_email', 'emeka@example.test')
        ->set('guest_phone', '+2348011113333')
        ->set('adults', 1)
        ->set('children', 0)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(2)->toDateString())
        ->set('source', BookingSource::Other->value)
        ->set('source_note', 'Facebook Marketplace DM')
        ->call('save');

    $booking = Booking::query()->latest('id')->first();
    expect($booking->source)->toBe(BookingSource::Other);
    expect($booking->source_note)->toBe('Facebook Marketplace DM');
});

it('normalises a blank note to null so exports do not carry empty strings', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ManualCreate::class)
        ->set('roomId', $this->room->id)
        ->set('guest_name', 'Blank Note')
        ->set('guest_email', 'bn@example.test')
        ->set('guest_phone', '+2348011114444')
        ->set('adults', 1)
        ->set('children', 0)
        ->set('checkin', Carbon::tomorrow()->toDateString())
        ->set('checkout', Carbon::tomorrow()->addDays(2)->toDateString())
        ->set('source', BookingSource::WalkIn->value)
        ->set('source_note', '   ')
        ->call('save');

    $booking = Booking::query()->latest('id')->first();
    expect($booking->source)->toBe(BookingSource::WalkIn);
    expect($booking->source_note)->toBeNull();
});

/* ---------- Calendar sheet persists the picked channel ---------- */

it('records the picked channel from the calendar new-reservation sheet', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(PropertyView::class)
        ->call('openSheet', $this->room->id, Carbon::tomorrow()->toDateString(), Carbon::tomorrow()->addDays(2)->toDateString())
        ->set('guest_name', 'Fatima Bello')
        ->set('guest_email', 'fatima@example.test')
        ->set('guest_phone', '+2348022223333')
        ->set('source', BookingSource::Airbnb->value)
        ->set('source_note', 'Superhost Ikeja listing')
        ->call('saveReservation');

    $booking = Booking::query()->latest('id')->first();
    expect($booking)->not->toBeNull();
    expect($booking->source)->toBe(BookingSource::Airbnb);
    expect($booking->source_note)->toBe('Superhost Ikeja listing');
});

/* ---------- Reports & payments breakdowns use enum labels ---------- */

it('groups revenue by channel using real enum labels', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'source' => BookingSource::BookingCom,
        'checkin_date' => Carbon::today()->subDays(3)->toDateString(),
        'room_total' => 100_000,
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'source' => BookingSource::Airbnb,
        'checkin_date' => Carbon::today()->subDays(2)->toDateString(),
        'room_total' => 200_000,
    ]);

    $snapshot = app(ReportSnapshot::class)->revenue(
        Carbon::today()->subDays(7),
        Carbon::today(),
    );

    $labels = collect($snapshot['bySource'])->pluck('label')->all();
    expect($labels)->toContain('Booking.com', 'Airbnb');
});

it('filters payments by booking channel + shows a channel breakdown KPI', function (): void {
    $airbnbBooking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'source' => BookingSource::Airbnb,
    ]);
    $bookingComBooking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'source' => BookingSource::BookingCom,
    ]);

    Payment::factory()->for($this->tenant)->for($airbnbBooking)->succeeded()->create([
        'amount' => 50_000,
        'paid_at' => Carbon::now()->subDay(),
    ]);
    Payment::factory()->for($this->tenant)->for($bookingComBooking)->succeeded()->create([
        'amount' => 80_000,
        'paid_at' => Carbon::now()->subDay(),
    ]);

    // Filter narrows to just the Airbnb payment.
    Livewire::actingAs($this->operator, 'web')
        ->test(PaymentsIndex::class)
        ->set('sourceFilter', BookingSource::Airbnb->value)
        ->assertSee($airbnbBooking->booking_ref)
        ->assertDontSee($bookingComBooking->booking_ref);

    // Breakdown lists both channels with labels the operator recognises.
    Livewire::actingAs($this->operator, 'web')
        ->test(PaymentsIndex::class)
        ->assertSeeHtml('data-testid="payments-by-channel"')
        ->assertSeeHtml('data-testid="payments-channel-row-airbnb"')
        ->assertSeeHtml('data-testid="payments-channel-row-booking_com"');
});
