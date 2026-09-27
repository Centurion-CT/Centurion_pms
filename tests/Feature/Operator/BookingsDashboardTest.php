<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Livewire\Dashboard\Bookings\Index as BookingsIndex;
use App\Livewire\Dashboard\Bookings\ManualCreate as BookingsManualCreate;
use App\Livewire\Dashboard\Bookings\Show as BookingsShow;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'op-dash']);
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create();
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'Maitama']);
    $this->room2 = Room::factory()->for($this->tenant)->for($this->property)->create(['name' => 'Asokoro']);
    $this->operator = Operator::factory()->for($this->tenant)->create();
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

/* ---------- Index ---------- */

it('renders the bookings index for an onboarded operator', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://op-dash.keverna.test/dashboard/bookings');

    $response->assertOk();
    $response->assertSeeLivewire(BookingsIndex::class);
});

it('renders the empty state when no bookings exist', function (): void {
    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->assertSeeHtml('data-testid="no-bookings"');
});

it('filters by status', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create(['guest_name' => 'Conf One']);
    Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create(['guest_name' => 'Cancel One']);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->set('statusFilter', BookingStatus::Confirmed->value)
        ->assertSee('Conf One')
        ->assertDontSee('Cancel One');
});

it('filters by room', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create(['guest_name' => 'Room A Guest']);
    Booking::factory()->for($this->tenant)->for($this->room2)->confirmed()->create(['guest_name' => 'Room B Guest']);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->set('roomFilter', $this->room2->id)
        ->assertSee('Room B Guest')
        ->assertDontSee('Room A Guest');
});

it('filters by search across guest name/email/ref', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Aisha Bello', 'guest_email' => 'aisha@x.test',
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'guest_name' => 'Bola Tinubu', 'guest_email' => 'bola@x.test',
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->set('search', 'aisha')
        ->assertSee('Aisha Bello')
        ->assertDontSee('Bola Tinubu');
});

it('isolates from another tenant\'s bookings', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create(['guest_name' => 'Mine']);

    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'op-dash-other']);
    $other->makeCurrent();
    $otherProp = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    Booking::factory()->for($other)->for($otherRoom)->confirmed()->create(['guest_name' => 'Theirs']);
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsIndex::class)
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

/* ---------- Show ---------- */

it('404s when an operator opens a foreign tenant\'s booking_ref', function (): void {
    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'op-dash-foreign']);
    $other->makeCurrent();
    $otherProp = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    $foreignBooking = Booking::factory()->for($other)->for($otherRoom)->confirmed()->create([
        'booking_ref' => 'KV-FOREIGN1',
    ]);
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://op-dash.keverna.test/dashboard/bookings/'.$foreignBooking->booking_ref);

    $response->assertNotFound();
});

it('renders the booking detail with action buttons for a Confirmed booking', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'booking_ref' => 'KV-DETAIL01',
    ]);

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://op-dash.keverna.test/dashboard/bookings/'.$booking->booking_ref);

    $response->assertOk();
    $response->assertSeeLivewire(BookingsShow::class);
    $response->assertSeeHtml('data-testid="action-checkin"');
    $response->assertSeeHtml('data-testid="action-cancel"');
});

it('calls the Confirm action through Livewire and updates state', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsShow::class, ['bookingRef' => $booking->booking_ref])
        ->call('confirm');

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
});

it('surfaces an error when the operator tries to check-in a future booking', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    $component = Livewire::actingAs($this->operator, 'web')
        ->test(BookingsShow::class, ['bookingRef' => $booking->booking_ref])
        ->call('checkIn');

    expect($component->get('errorMessage'))->toContain('checkin date');
    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Confirmed);
});

it('cancels via Livewire with the refund-requested flag set', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'checkin_date' => '2030-09-15',
        'checkout_date' => '2030-09-17',
    ]);

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsShow::class, ['bookingRef' => $booking->booking_ref])
        ->set('refundRequested', true)
        ->call('cancel');

    expect($booking->fresh()->booking_status)->toBe(BookingStatus::Cancelled);
    $audit = Activity::query()->where('subject_id', $booking->id)->where('event', 'cancelled')->first();
    expect($audit->properties->get('refund_requested'))->toBeTrue();
});

/* ---------- Manual create ---------- */

it('renders the manual booking form', function (): void {
    $response = $this->actingAs($this->operator, 'web')
        ->get('http://op-dash.keverna.test/dashboard/bookings/create');

    $response->assertOk();
    $response->assertSeeLivewire(BookingsManualCreate::class);
});

it('creates a manual booking marked Confirmed when paid in full', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsManualCreate::class)
        ->set('roomId', $this->room->id)
        ->set('guest_name', 'Walk In Guest')
        ->set('guest_email', 'walkin@x.test')
        ->set('guest_phone', '+2348012345678')
        ->set('checkin', '2030-09-15')
        ->set('checkout', '2030-09-17')
        ->set('payment_settlement', 'succeeded')
        ->call('save');

    $b = Booking::query()->where('guest_email', 'walkin@x.test')->first();
    expect($b)->not->toBeNull();
    expect($b->source->value)->toBe('manual');
    expect($b->booking_status)->toBe(BookingStatus::Confirmed);
});

it('creates a manual booking marked Pending when not yet paid', function (): void {
    Carbon::setTestNow(Carbon::parse('2030-09-01 10:00:00'));

    Livewire::actingAs($this->operator, 'web')
        ->test(BookingsManualCreate::class)
        ->set('roomId', $this->room->id)
        ->set('guest_name', 'Promise Pay')
        ->set('guest_email', 'promise@x.test')
        ->set('guest_phone', '+2348012345678')
        ->set('checkin', '2030-09-15')
        ->set('checkout', '2030-09-17')
        ->set('payment_settlement', 'pending')
        ->call('save');

    $b = Booking::query()->where('guest_email', 'promise@x.test')->first();
    expect($b->booking_status)->toBe(BookingStatus::Pending);
});

/* ---------- CSV export ---------- */

it('streams a CSV with the right rows and respects the status filter', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'guest_name' => 'CSV Conf', 'booking_ref' => 'KV-CONF0001',
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->cancelled()->create([
        'guest_name' => 'CSV Cancel', 'booking_ref' => 'KV-CANC0001',
    ]);

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://op-dash.keverna.test/dashboard/bookings/export.csv?status=confirmed');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $response->streamedContent();
    expect($body)->toContain('booking_ref,guest_name');
    expect($body)->toContain('KV-CONF0001');
    expect($body)->toContain('CSV Conf');
    expect($body)->not->toContain('KV-CANC0001');
});

it('CSV export does NOT include another tenant\'s bookings', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->confirmed()->create([
        'booking_ref' => 'KV-MINE0001', 'guest_name' => 'Mine',
    ]);

    Tenant::forgetCurrent();
    $other = Tenant::factory()->create(['slug' => 'op-dash-csv-other']);
    $other->makeCurrent();
    $otherProp = Property::factory()->for($other)->create();
    $otherRoom = Room::factory()->for($other)->for($otherProp)->create();
    Booking::factory()->for($other)->for($otherRoom)->confirmed()->create([
        'booking_ref' => 'KV-THEIRS001', 'guest_name' => 'Theirs',
    ]);
    Tenant::forgetCurrent();
    $this->tenant->makeCurrent();

    $response = $this->actingAs($this->operator, 'web')
        ->get('http://op-dash.keverna.test/dashboard/bookings/export.csv');

    $body = $response->streamedContent();
    expect($body)->toContain('KV-MINE0001');
    expect($body)->not->toContain('KV-THEIRS001');
});
