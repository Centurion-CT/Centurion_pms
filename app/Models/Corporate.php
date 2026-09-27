<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorporateKycStatus;
use App\Enums\CorporateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A company onboarded by Keverna whose staff can book any participating
 * property using the company's wallet at negotiated rates.
 *
 * Central — never tenant-scoped. Properties opt in to accepting
 * corporate bookings via the `accepts_corporates` flag on Property.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property CorporateStatus $status
 * @property string $primary_email
 * @property string|null $primary_phone
 * @property string|null $billing_email
 * @property string|null $address
 * @property string|null $city
 * @property string $country
 * @property string|null $registration_number
 * @property string|null $tax_id
 * @property CorporateKycStatus $kyc_status
 * @property string|null $kyc_notes
 * @property bool $accepts_reservations
 * @property Carbon|null $contract_signed_at
 * @property int|null $approval_threshold_naira
 * @property string|null $notes
 */
class Corporate extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'               => CorporateStatus::class,
            'kyc_status'           => CorporateKycStatus::class,
            'accepts_reservations' => 'bool',
            'contract_signed_at'   => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasOne<CorporateWallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(CorporateWallet::class);
    }

    /**
     * @return HasMany<CorporateMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CorporateMember::class);
    }

    /**
     * @return HasMany<CorporateDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(CorporateDomain::class);
    }

    /**
     * @return HasMany<CorporateRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(CorporateRate::class);
    }

    /**
     * @return HasMany<CorporateWalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CorporateWalletTransaction::class);
    }

    /**
     * Books-of-record on bookable tables.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<DeskBooking, $this>
     */
    public function deskBookings(): HasMany
    {
        return $this->hasMany(DeskBooking::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<Lease, $this>
     */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /**
     * @param  Builder<Corporate>  $query
     * @return Builder<Corporate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CorporateStatus::Active->value);
    }
}
