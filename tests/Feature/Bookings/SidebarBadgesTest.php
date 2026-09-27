<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\PropertyType;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Sidebar count badges for Reservations / In-house / Bookings.
 * Rendered by the tenant-auth layout, so tests hit the full route.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'sidebar-badges']);
    $this->tenant->makeCurrent();
    // Pin the property type — PropertyFactory picks randomly and the
    // Reservations / In-house / Bookings badges only render for
    // stay-type properties (exposesModule() filter). Without this the
    // suite flakes any time the factory rolls Workspace / Residential.
    $this->property = Property::factory()->for($this->tenant)->create([
        'type' => PropertyType::Hotel,
    ]);
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'capacity_adults' => 2, 'capacity_children' => 0, 'base_rate' => 50_000, 'min_nights' => 1,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)->create([
        'room_category_id' => $this->category->id, 'status' => RoomStatus::Active->value,
    ]);
    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function bookingsUrl(Tenant $tenant): string
{
    return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').'/dashboard/bookings';
}

it('reservations badge counts arrivals expected today', function (): void {
    // Two arriving today.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold,
        'checkin_date'   => Carbon::today(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    // Arriving tomorrow — should NOT count.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);

    $response = $this->get(bookingsUrl($this->tenant));
    $response->assertOk()->assertSee('nav-badge-reservations', false);
    expect($response->getContent())->toMatch(
        '/data-testid="nav-badge-reservations"[^>]*>\s*2\s*</s'
    );
});

it('in_house badge counts currently CheckedIn bookings only', function (): void {
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => Carbon::today()->subDay(),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date'   => Carbon::today()->subDays(2),
        'checkout_date'  => Carbon::today()->addDay(),
    ]);
    // Not CheckedIn — should NOT count.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);

    $response = $this->get(bookingsUrl($this->tenant));
    $response->assertOk();
    expect($response->getContent())->toMatch(
        '/data-testid="nav-badge-in_house"[^>]*>\s*2\s*</s'
    );
});

it('bookings badge counts Pending + OnHold (needs attention)', function (): void {
    // Pending → counts
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);
    // OnHold → counts
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::OnHold,
        'checkin_date'   => Carbon::today()->addDays(4),
        'checkout_date'  => Carbon::today()->addDays(5),
    ]);
    // Confirmed → doesn't count (no admin action needed)
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Confirmed,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);

    $response = $this->get(bookingsUrl($this->tenant));
    $response->assertOk();
    expect($response->getContent())->toMatch(
        '/data-testid="nav-badge-bookings"[^>]*>\s*3\s*</s'
    );
});

it('no badges when nothing pending / arriving / in-house', function (): void {
    $response = $this->get(bookingsUrl($this->tenant));
    $response->assertOk()
        ->assertDontSee('nav-badge-reservations', false)
        ->assertDontSee('nav-badge-in_house', false)
        ->assertDontSee('nav-badge-bookings', false);
});

it('Invoices nav row NEVER renders a badge — no such badge_module is populated', function (): void {
    // Seed 3 Pending bookings so the Bookings badge lights up. Prior
    // bug: Invoices used module=bookings and inherited the same count.
    // Fix: badge_module=invoices on the Invoices row, which nothing
    // populates, so no `nav-badge-invoices` testid should exist.
    Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_status' => BookingStatus::Pending,
        'checkin_date'   => Carbon::today()->addDay(),
        'checkout_date'  => Carbon::today()->addDays(2),
    ]);

    $response = $this->get(bookingsUrl($this->tenant));
    $response->assertOk()
        ->assertDontSee('nav-badge-invoices', false);
});
