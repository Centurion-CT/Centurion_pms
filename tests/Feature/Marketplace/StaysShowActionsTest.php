<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Mail\Marketplace\PropertyReportSubmitted;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

/**
 * Actions in the title-band top-right of /stays/{slug}:
 *   Like  - icon-only, client-side (localStorage) - no server surface
 *   Share - icon-only, navigator.share / clipboard - no server surface
 *   Report - icon + label, opens modal, POSTs to /stays/{slug}/report
 *
 * Tests cover the markup + the report endpoint's validation + email
 * dispatch. Like + Share are pure client-side, no server tests to
 * write beyond confirming the button markup ships.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->tenant = Tenant::factory()->create(['slug' => 'actions-test']);
    onBusinessHost('actions-test');
    $this->tenant->makeCurrent();
    $this->property = Property::factory()->for($this->tenant)->create([
        'is_listed_publicly' => true,
        'name' => 'Actions Test Property',
    ]);
    $cat = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 30_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    Room::factory()->for($this->tenant)->for($this->property)->for($cat, 'category')
        ->create(['status' => RoomStatus::Active]);
    Tenant::forgetCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

it('renders the Like / Share / Report action bar on the show page', function (): void {
    $r = $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]));

    $r->assertOk()
      ->assertSee('data-testid="stays-show-actions"', false)
      ->assertSee('data-testid="stays-show-like"', false)
      ->assertSee('data-testid="stays-show-share"', false)
      ->assertSee('data-testid="stays-show-report"', false)
      // Report is icon+label - the "Report" text label needs to
      // ship in the button so the click target isn't guessable
      // by icon alone.
      ->assertSeeText('Report');
});

it('makes the gallery hero tile clickable when there is at least one photo path', function (): void {
    $r = $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]));

    $r->assertOk()
      ->assertSee('data-testid="stays-show-hero-tile"', false)
      // Even without an uploaded hero the tile is present; when it
      // has ANY resolvable photo URL it carries the data-mp-gallery
      // attribute so the lightbox delegated click picks it up.
      ->assertSee('data-testid="stays-show-lightbox"', false);
});

it('ships the report modal markup + CSRF meta', function (): void {
    $r = $this->get(route('marketplace.stays.show', ['slug' => $this->property->public_slug]));

    $r->assertOk()
      ->assertSee('data-testid="stays-show-report-modal"', false)
      ->assertSee('data-testid="report-reason"', false)
      ->assertSee('data-testid="report-message"', false)
      ->assertSee('data-testid="report-email"', false)
      ->assertSee('data-testid="report-submit"', false)
      ->assertSee('meta name="csrf-token"', false);
});

it('POST /stays/{slug}/report queues a support email on a valid submission', function (): void {
    $this->postJson(route('marketplace.stays.report', ['slug' => $this->property->public_slug]), [
        'reason'  => 'inaccurate',
        'message' => 'Room number 302 does not exist at the property.',
        'email'   => 'guest@example.test',
    ], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    Mail::assertQueued(PropertyReportSubmitted::class, function ($mail) {
        return $mail->propertySlug === $this->property->public_slug
            && $mail->reason === 'inaccurate'
            && $mail->reporterEmail === 'guest@example.test'
            && str_contains($mail->message, '302 does not exist');
    });
});

it('POST /stays/{slug}/report rejects invalid payloads with 422', function (): void {
    $this->postJson(route('marketplace.stays.report', ['slug' => $this->property->public_slug]), [
        'reason'  => 'unknown-reason',   // not in the enum
        'message' => 'x',                // too short
        'email'   => 'not-an-email',
    ], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    Mail::assertNothingQueued();
});

it('POST /stays/{slug}/report 404s for an off-market slug', function (): void {
    $this->property->forceFill(['is_listed_publicly' => false])->save();

    $this->postJson(route('marketplace.stays.report', ['slug' => $this->property->public_slug]), [
        'reason'  => 'inaccurate',
        'message' => 'Some valid message here.',
        'email'   => 'guest@example.test',
    ], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertNotFound();

    Mail::assertNothingQueued();
});

it('POST /stays/{slug}/report rejects non-XHR posts', function (): void {
    $this->post(route('marketplace.stays.report', ['slug' => $this->property->public_slug]), [
        'reason'  => 'inaccurate',
        'message' => 'Some valid message here.',
        'email'   => 'guest@example.test',
    ])->assertStatus(406);

    Mail::assertNothingQueued();
});
