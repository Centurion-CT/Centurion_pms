<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Bookings\MarkCheckedOut;
use App\Enums\BookingChargeType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\ManualDiscountType;
use App\Enums\PaymentStatus;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $room_id
 * @property string $booking_ref
 * @property string|null $offline_key
 * @property string $guest_name
 * @property string $guest_email
 * @property string $guest_phone
 * @property int $adults
 * @property int $children
 * @property string|null $special_requests
 * @property Carbon $checkin_date
 * @property Carbon $checkout_date
 * @property int $nights
 * @property int $room_total
 * @property bool $breakfast_included
 * @property int $packages_total
 * @property int $paystack_fee
 * @property int $keverna_fee
 * @property int $total_amount
 * @property ManualDiscountType $manual_discount_type
 * @property float $manual_discount_percent
 * @property int $manual_discount_amount
 * @property int|null $manual_discount_by_operator_id
 * @property int $deposit_amount
 * @property int $balance_due
 * @property Carbon|null $balance_due_at
 * @property array<int,int>|null $balance_reminders_sent
 * @property Carbon|null $checkin_reminder_sent_at
 * @property Carbon|null $review_prompt_sent_at
 * @property BookingStatus $booking_status
 * @property PaymentStatus $payment_status
 * @property BookingSource $source
 * @property string|null $source_note
 * @property-read Tenant $tenant
 * @property-read Room $room
 */
class Booking extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'booking_status', 'payment_status', 'balance_due', 'deposit_amount',
                'source', 'source_note',
                'manual_discount_type', 'manual_discount_percent',
                'manual_discount_amount', 'manual_discount_by_operator_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking');
    }

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checkin_date' => 'date',
            'checkout_date' => 'date',
            'balance_due_at' => 'datetime',
            'balance_reminders_sent' => 'array',
            'checkin_reminder_sent_at' => 'datetime',
            'extend_prompt_sent_at' => 'datetime',
            'review_prompt_sent_at' => 'datetime',
            'review_invite_sent_at' => 'datetime',
            'checkout_policy_snapshot' => 'array',
            'no_show_at' => 'datetime',
            'booking_status' => BookingStatus::class,
            'payment_status' => PaymentStatus::class,
            'source' => BookingSource::class,
            'breakfast_included' => 'boolean',
            'manual_discount_percent' => 'decimal:2',
            'manual_discount_amount' => 'integer',
            'manual_discount_type' => ManualDiscountType::class,
        ];
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Room category this booking targets. Always set for category-
     * level reservations (room_id NULL); ALSO set for specific-room
     * bookings (denormalised at create time) so per-category
     * reports don't have to hop through the Room relation.
     *
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'room_category_id');
    }

    /**
     * A booking is "unassigned" when it holds a slot against a
     * category without a specific room pinned yet - front desk
     * needs to run AssignRoomToBooking before check-in.
     */
    public function isUnassigned(): bool
    {
        return $this->room_id === null && $this->room_category_id !== null;
    }

    /**
     * The CRM Customer this booking is attached to. CustomerLinker
     * attaches it inside the create transaction; historical rows are
     * filled by the BackfillCustomersFromBookings command.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Front-desk operator who applied the ad-hoc manual discount at
     * booking time, if any. Null for bookings created without a
     * manual discount, or when the operator has since been deleted.
     *
     * @return BelongsTo<Operator, $this>
     */
    public function manualDiscountBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'manual_discount_by_operator_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Optional corporate context - set by CorporateBookingService at
     * create time when the booker's email maps to a Corporate.
     *
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<CorporateMember, $this>
     */
    public function corporateMember(): BelongsTo
    {
        return $this->belongsTo(CorporateMember::class, 'corporate_member_id');
    }

    /**
     * True when this booking sits under an Active Corporate whose
     * profile of this specific member is also Active — i.e. the corp
     * has already vetted this staff member as a guest they'll settle
     * the folio for. Front desk can check them in without collecting
     * payment upfront; the outstanding goes onto the corp's tab and
     * gets billed at checkout.
     *
     * We look up freshly (not from the eager-loaded relations) so a
     * status change on the Corporate / CorporateMember between page
     * load + action can't leak a stale "pre-authorized" into the
     * check-in flow.
     */
    public function isCorporatePreAuthorized(): bool
    {
        if ($this->corporate_id === null || $this->corporate_member_id === null) {
            return false;
        }
        $corp = Corporate::query()->find($this->corporate_id);
        if ($corp === null || $corp->status !== \App\Enums\CorporateStatus::Active) {
            return false;
        }
        $member = CorporateMember::query()->find($this->corporate_member_id);
        if ($member === null || $member->status !== \App\Enums\CorporateMemberStatus::Active) {
            return false;
        }
        return true;
    }

    /**
     * @return HasMany<AvailabilityBlock, $this>
     */
    public function availabilityBlocks(): HasMany
    {
        return $this->hasMany(AvailabilityBlock::class);
    }

    /**
     * Extra charges (extension nights, room-service, damage…) that
     * accrued after the booking was created.
     *
     * @return HasMany<BookingCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(BookingCharge::class)->orderByDesc('created_at');
    }

    /**
     * Packages (breakfast, airport pickup, etc.) the guest chose at
     * booking time or added later via the portal. Snapshot columns on
     * the pivot make this safe to read on historical bookings.
     *
     * @return HasMany<BookingPackage, $this>
     */
    public function selectedPackages(): HasMany
    {
        return $this->hasMany(BookingPackage::class)->orderBy('id');
    }

    /**
     * Applied discount(s). Phase 1 enforces one-per-booking at the
     * application layer, so calling `->first()` is safe today.
     *
     * @return HasMany<BookingDiscount, $this>
     */
    public function appliedDiscounts(): HasMany
    {
        return $this->hasMany(BookingDiscount::class);
    }

    /**
     * Total amount still owed by the guest right now - balance_due
     * (deposit / on-hold remainder) + every unsettled extra charge
     * + any overstay nights that have accrued but haven't been billed
     * to the ledger yet.
     *
     * Including the projected overstay was a deliberate change: front
     * desk kept asking "why does 'Outstanding' show a smaller number
     * than the overstay banner says the guest owes?". The answer used
     * to be "because outstanding is ledger-only." That distinction
     * doesn't map to how operators think about a guest who's five
     * nights over their booked checkout, so the number now reflects
     * the true amount. {@see totalOwed()} still exists as an alias
     * for callers that were being explicit.
     */
    public function outstandingTotal(): int
    {
        $balance = (int) ($this->balance_due ?? 0);
        $charges = (int) $this->charges()->unsettled()->sum('amount');
        $unbilledOverstay = $this->unchargedOverstayAmount();

        return $balance + $charges + $unbilledOverstay;
    }

    /**
     * Nights the guest is currently past their booked checkout, or 0
     * when they're not overstaying. A guest with `checkout_date` in the
     * future returns 0. A CheckedOut / NoShow / Cancelled guest also
     * returns 0 - overstay only applies to bookings that are still
     * physically in-house.
     *
     * Half-open date math matches {@see MarkCheckedOut}
     * - the day-of-checkout is NOT an overstay night; the day after IS.
     */
    public function projectedOverstayNights(?Carbon $asOf = null): int
    {
        if ($this->booking_status !== BookingStatus::CheckedIn) {
            return 0;
        }
        $today = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $checkoutStart = $this->checkout_date->copy()->startOfDay();
        if (! $today->greaterThan($checkoutStart)) {
            return 0;
        }

        return (int) $checkoutStart->diffInDays($today, absolute: true);
    }

    /**
     * ₦ amount that would be added to the ledger as overstay if this
     * guest checked out right now - the still-uncharged remainder of
     * `nights past checkout × room base rate`. Zero for guests who are
     * on-time, pre-arrival, or already fully billed for their overstay.
     *
     * Nets out any Overstay charges the booking already carries
     * (settled or unsettled) so `outstandingTotal() + projectedOverstayAmount()`
     * never double-counts. That's what {@see totalOwed()} relies on.
     *
     * This used to bail out and return 0 the moment ANY overstay charge
     * existed, which under-reported when the nightly {@see AutoDeductFromWallet}
     * command missed days and left the guest partially billed - the
     * banner would say "already charged" while the ledger held less
     * than the full amount owed.
     */
    public function projectedOverstayAmount(?Carbon $asOf = null): int
    {
        return $this->unchargedOverstayAmount($asOf);
    }

    /**
     * The nightly rate the guest committed to at booking time -
     * the breakfast-included tier when opted in, else the room's
     * base rate. Every downstream money calc that has to price
     * an EXTRA night for THIS guest (overstay, late checkout,
     * extension defaults) should use this, not the raw base rate,
     * so a breakfast-tier guest doesn't get billed cheaper for an
     * overstay night than for every other night they've stayed.
     */
    public function committedNightlyRate(): int
    {
        $category = $this->room?->category;
        if ($category !== null) {
            return $category->effectiveNightlyRate((bool) $this->breakfast_included);
        }
        return (int) ($this->room?->base_rate ?? 0);
    }

    /**
     * The checkout policy this booking is bound by - the snapshot
     * frozen at check-in time when present, else the property's live
     * config. Pre-snapshot bookings still work through the fallback.
     *
     * Shape merges Property::checkoutPolicy() with the check_out_to
     * house rule + a snapshot_at timestamp so the fee calculator has
     * everything it needs from one place.
     *
     * @return array<string,mixed>
     */
    public function effectiveLateCheckoutPolicy(): array
    {
        if (is_array($this->checkout_policy_snapshot) && $this->checkout_policy_snapshot !== []) {
            return $this->checkout_policy_snapshot;
        }
        $property = $this->room?->property;
        if ($property === null) return [];
        return array_merge(
            $property->checkoutPolicy(),
            ['check_out_to' => (string) ($property->houseRules()['check_out_to'] ?? '')],
        );
    }

    /**
     * Build the snapshot payload from a property's current config.
     * Used by MarkCheckedIn (fresh snapshot) and the settings form's
     * pre-change backfill (locking the OLD policy on in-house guests).
     *
     * @return array<string,mixed>
     */
    public static function buildCheckoutPolicySnapshot(Property $property, string $source = 'checkin'): array
    {
        return array_merge(
            $property->checkoutPolicy(),
            [
                'check_out_to' => (string) ($property->houseRules()['check_out_to'] ?? ''),
                'snapshot_at' => \Illuminate\Support\Carbon::now()->toIso8601String(),
                'snapshot_source' => $source,
            ],
        );
    }

    /**
     * True when today is the booking's checkout date AND the wall
     * clock is past the property's check-out cutoff + grace period.
     * Used by the in-house sheet to flag guests already accruing a
     * late-checkout fee before the operator hits "check out".
     */
    public function isSameDayLate(\Illuminate\Support\Carbon $now): bool
    {
        if ($this->checkout_date === null) return false;
        if (! $now->copy()->startOfDay()->equalTo($this->checkout_date->copy()->startOfDay())) {
            return false;
        }
        $policy = $this->effectiveLateCheckoutPolicy();
        if (empty($policy['late_checkout_enabled'])) return false;
        $checkoutTo = (string) ($policy['check_out_to'] ?? '');
        if ($checkoutTo === '') return false;

        [$hh, $mm] = array_pad(explode(':', $checkoutTo), 2, '0');
        $deadline = $now->copy()->startOfDay()
            ->setTime((int) $hh, (int) $mm)
            ->addMinutes((int) ($policy['late_checkout_grace_minutes'] ?? 0));

        return $now->greaterThan($deadline);
    }

    /**
     * Projected late-checkout fee if the guest were checked out right
     * now. Uses the same calculator MarkCheckedOut uses so the desk
     * sees the exact number that will land on the folio.
     */
    public function projectedLateCheckoutFee(\Illuminate\Support\Carbon $now): array
    {
        $property = $this->room?->property;
        if ($property === null || ! $this->isSameDayLate($now)) {
            return ['kind' => 'none', 'amount' => 0, 'hours_late' => 0, 'description' => ''];
        }
        return app(\App\Services\Bookings\LateCheckoutFeeCalculator::class)
            ->quote($this, $property, $now);
    }

    /**
     * The furthest checkout date this booking can be extended to
     * without stepping on the next reservation for the same room.
     *
     * Returns the next reservation's `checkin_date` when there is one
     * (same-day turnover is allowed - this guest checks out on the
     * same day the next guest checks in), or NULL when the room is
     * unbooked forward-of-checkout, meaning the extension has no
     * upper bound apart from operator sanity.
     *
     * Ignores Cancelled / NoShow / CheckedOut rows since those aren't
     * live reservations. Used by the extend modal + guest portal to
     * cap the datepicker BEFORE the guest picks a date that would
     * collide, instead of failing the reserve at submit time.
     */
    public function maxExtendableCheckoutDate(): ?\Illuminate\Support\Carbon
    {
        if ($this->room_id === null || $this->checkout_date === null) {
            return null;
        }

        $next = static::query()
            ->where('room_id', $this->room_id)
            ->where('id', '!=', $this->id)
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
                BookingStatus::CheckedIn->value,
            ])
            ->where('checkin_date', '>=', $this->checkout_date->toDateString())
            ->orderBy('checkin_date')
            ->first(['id', 'checkin_date']);

        return $next?->checkin_date;
    }

    /**
     * How many nights of overstay have already been recorded as
     * BookingCharge rows. Rounded down against this booking's
     * committed nightly rate so the reverse math agrees with the
     * forward projection ({@see unchargedOverstayAmount}) - a
     * partial-rate historical charge (rate change mid-stay) is
     * floored to avoid double-charging.
     *
     * ExtendBooking keeps prior SETTLED Overstay charges on the
     * ledger for audit (they're credited against the extension via
     * `overstay_already_paid`), but the nights they represent are
     * now folded into the extended stay and no longer belong to any
     * open overstay period. If we counted them here, an in-house
     * guest who was billed for overstay pre-extend then goes overdue
     * AGAIN post-extend would show as "already charged" - and the
     * OVERDUE badge would fire without an ACCRUED amount, exactly the
     * bug operators reported on Glory + Godspower on 2026-09-09.
     * Filter out anything older than the last `extended` activity.
     */
    public function overstayNightsCharged(): int
    {
        $rate = $this->committedNightlyRate();
        if ($rate <= 0) {
            return 0;
        }

        $q = $this->charges()->where('type', BookingChargeType::Overstay->value);

        $lastExtendedAt = \App\Models\Activity::query()
            ->where('subject_type', $this->getMorphClass())
            ->where('subject_id', $this->id)
            ->where('event', 'extended')
            ->latest('created_at')
            ->value('created_at');

        if ($lastExtendedAt !== null) {
            $q->where('created_at', '>=', $lastExtendedAt);
        }

        return (int) floor(((int) $q->sum('amount')) / $rate);
    }

    /**
     * Nights of overstay that have accrued but haven't been billed yet.
     * Drives the catch-up path in {@see AutoDeductFromWallet} and
     * {@see MarkCheckedOut}: if the cron missed a day (or five), this
     * is the delta the next run needs to bill in one aggregate charge.
     */
    public function unchargedOverstayNights(?Carbon $asOf = null): int
    {
        return max(0, $this->projectedOverstayNights($asOf) - $this->overstayNightsCharged());
    }

    /**
     * ₦ equivalent of {@see unchargedOverstayNights()}: still-uncharged
     * nights × committed nightly rate (breakfast tier or base). Zero
     * when the guest is on-time, pre-arrival, or already fully billed.
     */
    public function unchargedOverstayAmount(?Carbon $asOf = null): int
    {
        return $this->unchargedOverstayNights($asOf) * $this->committedNightlyRate();
    }

    /**
     * Alias for {@see outstandingTotal()} kept for callers that were
     * being explicit about "include projected overstay too". Since the
     * base method now includes it, this is a no-op wrapper.
     */
    public function totalOwed(?Carbon $asOf = null): int
    {
        return $this->outstandingTotal();
    }

    /**
     * The guest-submitted review for this booking, if any. One-to-one
     * (unique on `reviews.booking_id`) - guests can post at most one
     * review per stay via the portal.
     *
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Front-desk-authored invoices attached to this booking. Ordered
     * newest first so the show page surfaces the most recent one first.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('id');
    }
}
