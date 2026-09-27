<?php

declare(strict_types=1);

use App\Actions\Bookings\ApplyPostCheckinDiscount;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\OperatorRole;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot correction for bookings whose manual_discount_amount got
 * inflated by an extend/discount/cancel cycle before the reversal
 * fix shipped. Walks the activity log for the pattern:
 *
 *   extended → post_checkin_discount_applied(*) → extension_cancelled
 *
 * ...and reverses any discount amounts that leaked past the old
 * (pre-fix) cancel.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'correct-disc']);
    $this->tenant->makeCurrent();

    $this->property = Property::factory()->for($this->tenant)->create();
    $this->category = RoomCategory::factory()->for($this->tenant)->for($this->property)->create([
        'base_rate' => 100_000, 'capacity_adults' => 2, 'capacity_children' => 0,
    ]);
    $this->room = Room::factory()->for($this->tenant)->for($this->property)
        ->for($this->category, 'category')
        ->create(['status' => RoomStatus::Active, 'room_number' => '201']);
    $this->operator = Operator::factory()->for($this->tenant)->create(['role' => OperatorRole::Manager]);
});

afterEach(function (): void {
    Tenant::forgetCurrent();
    Carbon::setTestNow();
});

it('detects a leaked post-checkin discount and previews the correction in dry-run', function (): void {
    // Simulate the exact state the buggy cancel left the booking in:
    //   pre-extend: total=₦89,810, discount=₦10,190, room_total=₦100k
    //   after extend + ₦10,190 discount + old cancel: total=₦79,620,
    //     discount=₦20,380, room_total=₦100k
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-CORRECT01',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'room_total' => 100_000,
        'total_amount' => 79_620,
        'manual_discount_amount' => 20_380,
        'balance_due' => 0,
    ]);

    // Stamp the historical activity log the way the sequence would
    // have written it in production:
    //   1. extended
    //   2. post_checkin_discount_applied (amount=10190)
    //   3. extension_cancelled  (no post_extend_discount_reversed key
    //      because this was pre-fix)
    activity('booking')->performedOn($booking)->event('extended')
        ->withProperties(['extension_charged' => 100_000])->log('t1');
    activity('booking')->performedOn($booking)->event('post_checkin_discount_applied')
        ->withProperties(['amount' => 10_190])->log('t2');
    activity('booking')->performedOn($booking)->event('extension_cancelled')
        ->withProperties(['amount_voided' => 100_000])->log('t3');

    Artisan::call('bookings:correct-discount-after-cancel', ['ref' => 'KV-CORRECT01']);
    $output = Artisan::output();

    expect($output)->toContain('Leaked ₦10,190');
    expect($output)->toContain('Dry-run');

    // Booking untouched in dry-run.
    $b = $booking->refresh();
    expect((int) $b->total_amount)->toBe(79_620);
    expect((int) $b->manual_discount_amount)->toBe(20_380);
});

it('applies the correction when --apply is passed', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-CORRECT02',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'room_total' => 100_000,
        'total_amount' => 79_620,
        'manual_discount_amount' => 20_380,
        'balance_due' => 0,
    ]);

    activity('booking')->performedOn($booking)->event('extended')
        ->withProperties(['extension_charged' => 100_000])->log('t1');
    activity('booking')->performedOn($booking)->event('post_checkin_discount_applied')
        ->withProperties(['amount' => 10_190])->log('t2');
    activity('booking')->performedOn($booking)->event('extension_cancelled')
        ->withProperties(['amount_voided' => 100_000])->log('t3');

    Artisan::call('bookings:correct-discount-after-cancel', [
        'ref' => 'KV-CORRECT02', '--apply' => true,
    ]);

    $b = $booking->refresh();
    expect((int) $b->total_amount)->toBe(79_620 + 10_190);      // 89,810
    expect((int) $b->manual_discount_amount)->toBe(10_190);
    // balance_due deliberately untouched by the correction - it
    // can't safely reverse without a per-event pre-discount balance
    // snapshot (would over-restore on already-paid bookings, which
    // is exactly what broke KV-XJZWA3WU on the first pass).
    expect((int) $b->balance_due)->toBe(0);

    // Correction stamped its own activity row.
    $stamp = \App\Models\Activity::query()
        ->where('subject_id', $b->id)
        ->where('event', 'discount_correction_after_extension_cancel')
        ->first();
    expect($stamp)->not->toBeNull();
});

it('skips discounts that were already reversed by the new cancel path', function (): void {
    // Post-fix cancel stamps post_extend_discount_reversed on the
    // extension_cancelled activity - this booking is already correct
    // and must not get double-reversed.
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-CORRECT03',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'room_total' => 100_000,
        'total_amount' => 89_810,
        'manual_discount_amount' => 10_190,
        'balance_due' => 89_810,
    ]);

    activity('booking')->performedOn($booking)->event('extended')
        ->withProperties(['extension_charged' => 100_000])->log('t1');
    activity('booking')->performedOn($booking)->event('post_checkin_discount_applied')
        ->withProperties(['amount' => 10_190])->log('t2');
    activity('booking')->performedOn($booking)->event('extension_cancelled')
        ->withProperties([
            'amount_voided' => 100_000,
            'post_extend_discount_reversed' => 10_190,   // <-- new key
        ])->log('t3');

    Artisan::call('bookings:correct-discount-after-cancel', ['ref' => 'KV-CORRECT03']);
    $output = Artisan::output();

    expect($output)->toContain('Nothing to correct');

    // State unchanged.
    $b = $booking->refresh();
    expect((int) $b->manual_discount_amount)->toBe(10_190);
    expect((int) $b->total_amount)->toBe(89_810);
});

it('ignores discounts applied BEFORE an extension (they were legitimate)', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-CORRECT04',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'room_total' => 100_000,
        'total_amount' => 85_000,
        'manual_discount_amount' => 15_000,
        'balance_due' => 85_000,
    ]);

    // Discount applied FIRST (pre-extension) - orthogonal, keep it.
    activity('booking')->performedOn($booking)->event('post_checkin_discount_applied')
        ->withProperties(['amount' => 15_000])->log('t0');
    activity('booking')->performedOn($booking)->event('extended')
        ->withProperties(['extension_charged' => 100_000])->log('t1');
    activity('booking')->performedOn($booking)->event('extension_cancelled')
        ->withProperties(['amount_voided' => 100_000])->log('t2');

    Artisan::call('bookings:correct-discount-after-cancel', ['ref' => 'KV-CORRECT04']);
    $output = Artisan::output();

    expect($output)->toContain('Nothing to correct');
    expect((int) $booking->refresh()->manual_discount_amount)->toBe(15_000);
});

it('--undo rolls back the last correction using the before-snapshot', function (): void {
    $booking = Booking::factory()->for($this->tenant)->for($this->room)->create([
        'booking_ref' => 'KV-CORRECT05',
        'booking_status' => BookingStatus::CheckedIn,
        'checkin_date' => Carbon::today()->subDay(),
        'checkout_date' => Carbon::today(),
        'nights' => 1,
        'room_total' => 100_000,
        'total_amount' => 79_620,
        'manual_discount_amount' => 20_380,
        'balance_due' => 0,
    ]);

    activity('booking')->performedOn($booking)->event('extended')
        ->withProperties(['extension_charged' => 100_000])->log('t1');
    activity('booking')->performedOn($booking)->event('post_checkin_discount_applied')
        ->withProperties(['amount' => 10_190])->log('t2');
    activity('booking')->performedOn($booking)->event('extension_cancelled')
        ->withProperties(['amount_voided' => 100_000])->log('t3');

    // First apply the correction.
    Artisan::call('bookings:correct-discount-after-cancel', [
        'ref' => 'KV-CORRECT05', '--apply' => true,
    ]);
    expect((int) $booking->refresh()->manual_discount_amount)->toBe(10_190);

    // Then undo it.
    Artisan::call('bookings:correct-discount-after-cancel', [
        'ref' => 'KV-CORRECT05', '--undo' => true, '--apply' => true,
    ]);

    $b = $booking->refresh();
    expect((int) $b->total_amount)->toBe(79_620);
    expect((int) $b->manual_discount_amount)->toBe(20_380);
    expect((int) $b->balance_due)->toBe(0);
});

it('errors on a missing booking_ref', function (): void {
    $exit = Artisan::call('bookings:correct-discount-after-cancel', ['ref' => 'KV-NOSUCH']);
    expect($exit)->not->toBe(0);
    expect(Artisan::output())->toContain('not found');
});
