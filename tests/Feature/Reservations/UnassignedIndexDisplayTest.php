<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateCategoryReservation;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Livewire\Dashboard\Reservations\Index as ReservationsIndex;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Services\Dashboard\CalendarHeatmap;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The Reservations index page must surface category-only (unassigned)
 * bookings AND the year heatmap must count their held nights.
 * Previously both were filtered out: whereHas('room', ...) on the
 * arrivals queries drops room_id=NULL bookings, and the heatmap's
 * whereIn('room_id', ...) skips category-level (room_id=NULL) blocks.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'unassigned-idx']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'name' => 'Standard', 'base_rate' => 30_000,
        'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '101']);
    $this->operator = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Frontdesk,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 9, 9, 10, 0, 0));
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('lists category-only bookings on the reservations index Unassigned card', function (): void {
    // Pending category-only booking - the exact shape the operator
    // hit "isn't showing" on the index.
    $b = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Ifeanyi Amadi',
        guestEmail: '',
        guestPhone: '+2348011117777',
        adults: 1, children: 0,
    );

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->assertSee('data-testid="reservations-unassigned"', false)
        ->assertSee('data-testid="unassigned-row-'.$b->booking_ref.'"', false)
        ->assertSee('Ifeanyi Amadi')
        ->assertSee('Standard');
});

it('row-level click target navigates to reservation-show', function (): void {
    $b = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today(),
        checkout: Carbon::today()->addDay(),
        guestName: 'Row Click',
        guestEmail: '',
        guestPhone: '+2348011118888',
        adults: 1, children: 0,
    );

    $html = Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)->html();

    // data-row-href is what resources/js/rowclick.js reads to
    // dispatch a Livewire.navigate on <tr> click - the whole row
    // becomes the click target, not just the guest name.
    $expectedHref = route('tenant.reservations.show', $b->booking_ref);
    expect($html)->toContain('data-row-href="'.$expectedHref.'"');
});

it('shows the paid amount + discount on each unassigned row', function (): void {
    $b = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today()->addDay(),
        checkout: Carbon::today()->addDays(2),
        guestName: 'Amount Guest',
        guestEmail: '',
        guestPhone: '+2348011119999',
        adults: 1, children: 0,
    );
    // Stamp a discount + a paid ₦20k on the booking.
    $b->forceFill(['manual_discount_amount' => 5_000])->save();
    \App\Models\Payment::factory()->for($this->tenant)->for($b)->succeeded()->create([
        'amount' => 20_000, 'method' => 'cash',
        'type' => \App\Enums\PaymentType::Deposit,
        'paid_at' => Carbon::now(),
    ]);

    $html = Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)->html();

    // Paid cell + discount cell both render with the amount.
    preg_match(
        '/data-testid="unassigned-paid-'.$b->booking_ref.'"[^>]*>(.*?)<\/td>/s',
        $html, $paidCell,
    );
    expect($paidCell[1] ?? '')->toContain('20,000');

    preg_match(
        '/data-testid="unassigned-discount-'.$b->booking_ref.'"[^>]*>(.*?)<\/td>/s',
        $html, $discCell,
    );
    expect($discCell[1] ?? '')->toContain('5,000');
});

it('renders em-dash on the Paid + Discount cells for a Pending booking with no receipts + no discount', function (): void {
    $b = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::today()->addDay(),
        checkout: Carbon::today()->addDays(2),
        guestName: 'Bare Guest',
        guestEmail: '',
        guestPhone: '+2348011118888',
        adults: 1, children: 0,
    );

    $html = Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)->html();

    preg_match(
        '/data-testid="unassigned-paid-'.$b->booking_ref.'"[^>]*>(.*?)<\/td>/s',
        $html, $paidCell,
    );
    expect($paidCell[1] ?? '')->not->toContain('₦');
    expect($paidCell[1] ?? '')->not->toContain('&#8358;');

    preg_match(
        '/data-testid="unassigned-discount-'.$b->booking_ref.'"[^>]*>(.*?)<\/td>/s',
        $html, $discCell,
    );
    expect($discCell[1] ?? '')->not->toContain('₦');
});

it('does NOT render the Unassigned card when every booking has a room pinned', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => Carbon::today(), 'checkout_date' => Carbon::today()->addDay(),
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->assertDontSee('data-testid="reservations-unassigned"', false);
});

it('day-detail modal lists category-only bookings that overlap the clicked day', function (): void {
    // Book Sep 10 → Sep 12 category-only. Clicking Sep 10 or Sep 11
    // should surface it; Sep 12 (checkout day, half-open interval)
    // should not.
    $b = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::create(2026, 9, 10),
        checkout: Carbon::create(2026, 9, 12),
        guestName: 'Day Detail Guest',
        guestEmail: '',
        guestPhone: '+2348011117777',
        adults: 1, children: 0,
    );

    // Sep 10 - guest is in-house
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->call('loadDayDetail', '2026-09-10')
        ->assertSee('data-testid="day-detail-unassigned"', false)
        ->assertSee('data-testid="day-detail-unassigned-'.$b->booking_ref.'"', false)
        ->assertSee('Day Detail Guest');

    // Sep 11 - still in-house
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->call('loadDayDetail', '2026-09-11')
        ->assertSee('data-testid="day-detail-unassigned-'.$b->booking_ref.'"', false);

    // Sep 12 - checkout day, half-open interval means the guest is
    // NOT in-house here.
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->call('loadDayDetail', '2026-09-12')
        ->assertDontSee('data-testid="day-detail-unassigned-'.$b->booking_ref.'"', false);
});

it('does NOT render the unassigned section when no category-only booking overlaps', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->call('loadDayDetail', '2026-09-10')
        ->assertDontSee('data-testid="day-detail-unassigned"', false);
});

it('surfaces unassigned bookings on the "Arriving today" table too', function (): void {
    // Pending category-only booking arriving today. Must appear on
    // the main arrivals table (not just the dedicated Unassigned
    // card below).
    $b = app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: Carbon::create(2026, 9, 9),
        checkout: Carbon::create(2026, 9, 10),
        guestName: 'Arrival Guest', guestEmail: '', guestPhone: '+2348011114444',
        adults: 1, children: 0,
    );

    Livewire::actingAs($this->operator, 'web')
        ->test(ReservationsIndex::class)
        ->assertSee('data-testid="arrival-row-'.$b->booking_ref.'"', false)
        ->assertSee('Arrival Guest')
        // Room cell should read "Standard" (the category name) with
        // an Unassigned pill, not "-".
        ->assertSee('Standard')
        ->assertSee('Unassigned');
});

it('year heatmap counts category-level held nights (room_id NULL) as occupied', function (): void {
    // Two units in the category so free-vs-occupied math is
    // decidable. Reserve one category-only booking for tonight -
    // heatmap must count it, not skip because room_id=NULL.
    Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);

    $ci = Carbon::today();
    $co = Carbon::today()->addDay();
    app(CreateCategoryReservation::class)->handle(
        category: $this->category,
        checkin: $ci, checkout: $co,
        guestName: 'Cat Guest',
        guestEmail: '',
        guestPhone: '+2348011113333',
        adults: 1, children: 0,
    );

    $heat = app(CalendarHeatmap::class)->build($ci, $ci);
    $day = $heat['days'][$ci->toDateString()];

    expect((int) $day['booked'])->toBe(1);       // one held slot
    expect((int) $day['total'])->toBe(2);        // two active rooms
    expect((int) $day['pct'])->toBe(50);
});

it('year heatmap correctly combines category-level + specific-room blocks', function (): void {
    // Two rooms in the category. One booking pins roomA, one is
    // category-only. Both count → heatmap shows 100% for the night.
    $roomB = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '102']);

    $ci = Carbon::today();
    $co = Carbon::today()->addDay();

    // Category-only booking
    app(CreateCategoryReservation::class)->handle(
        category: $this->category, checkin: $ci, checkout: $co,
        guestName: 'Cat A', guestEmail: '', guestPhone: '+2348011114444',
        adults: 1, children: 0,
    );
    // Specific-room booking on roomB via a raw block
    \App\Models\AvailabilityBlock::query()->create([
        'tenant_id' => $this->tenant->id,
        'room_id' => $roomB->id,
        'room_category_id' => $this->category->id,
        'date' => $ci->format('Y-m-d H:i:s'),
        'type' => \App\Enums\AvailabilityBlockType::Booked->value,
        'booking_id' => null,
    ]);

    $heat = app(CalendarHeatmap::class)->build($ci, $ci);
    $day = $heat['days'][$ci->toDateString()];

    expect((int) $day['booked'])->toBe(2);
    expect((int) $day['pct'])->toBe(100);
    expect($day['tier'])->toBe('full');
});
