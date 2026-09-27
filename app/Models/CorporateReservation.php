<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorporateReservationStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $corporate_id
 * @property int $property_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property CorporateReservationStatus $status
 * @property string|null $notes
 * @property int|null $created_by_operator_id
 */
class CorporateReservation extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at'   => 'date',
            'status'    => CorporateReservationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<CorporateReservationCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(CorporateReservationCategory::class);
    }

    /**
     * @return HasMany<CorporateReservationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CorporateReservationMember::class);
    }

    public function nights(): int
    {
        return (int) $this->starts_at->diffInDays($this->ends_at);
    }

    public function totalRoomsAllocated(): int
    {
        return (int) $this->categories()->sum('rooms_count');
    }

    /**
     * Find a live (Pending or Active) reservation for this corp + member
     * at the given property whose window covers the requested stay
     * dates. Returns the reservation with `categories` + a `.member`
     * relation preloaded so the caller can render the allocation
     * summary without a follow-up query.
     *
     * "Covers" is a half-open interval overlap:
     *   reservation.starts_at ≤ requested checkout AND
     *   reservation.ends_at   ≥ requested checkin
     *
     * @return static|null
     */
    public static function activeForMemberInWindow(
        int $corporateId,
        int $memberId,
        int $propertyId,
        \Illuminate\Support\Carbon $checkin,
        \Illuminate\Support\Carbon $checkout,
    ): ?self {
        return self::query()
            ->with(['categories.roomCategory', 'property:id,name'])
            ->where('corporate_id', $corporateId)
            ->where('property_id', $propertyId)
            ->whereIn('status', [
                CorporateReservationStatus::Pending->value,
                CorporateReservationStatus::Active->value,
            ])
            ->whereDate('starts_at', '<=', $checkout->toDateString())
            ->whereDate('ends_at', '>=', $checkin->toDateString())
            ->whereHas('members', fn ($q) => $q->where('corporate_member_id', $memberId))
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Any upcoming / live reservation for this corp + member + property
     * regardless of a specific window. Powers the quick-reserve sheet
     * when the operator has typed the phone but hasn't picked dates
     * yet — we still want to tell them "this member has a Sep 12-14
     * reservation ahead" so they know which window to book against.
     *
     * "Upcoming" = ends_at >= today (a reservation that closes today
     * is still eligible because the guest can still check in).
     *
     * @return static|null
     */
    public static function upcomingForMember(
        int $corporateId,
        int $memberId,
        int $propertyId,
        ?\Illuminate\Support\Carbon $asOf = null,
    ): ?self {
        $asOf ??= \Illuminate\Support\Carbon::today();

        return self::query()
            ->with(['categories.roomCategory', 'property:id,name'])
            ->where('corporate_id', $corporateId)
            ->where('property_id', $propertyId)
            ->whereIn('status', [
                CorporateReservationStatus::Pending->value,
                CorporateReservationStatus::Active->value,
            ])
            ->whereDate('ends_at', '>=', $asOf->toDateString())
            ->whereHas('members', fn ($q) => $q->where('corporate_member_id', $memberId))
            ->orderBy('starts_at')
            ->first();
    }
}
