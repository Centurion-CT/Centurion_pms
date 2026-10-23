<?php

declare(strict_types=1);

namespace App\Models;

use App\Console\Commands\Guests\PurgeGuestIdDocuments;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The guest's ID document seen at check-in (Keverna Desk phase 2).
 *
 * Personal data (NDPA): number and date of birth are encrypted at rest,
 * only host roles read it, lists show the masked number, and after
 * `retain_until` the purge command wipes everything but the type and
 * the fact it was seen ({@see PurgeGuestIdDocuments}).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $customer_id
 * @property string $type
 * @property string|null $number
 * @property string|null $nationality
 * @property string|null $date_of_birth Y-m-d, encrypted
 * @property Carbon|null $expires_on
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property int|null $captured_by_operator_id
 * @property Carbon $retain_until
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property-read Booking $booking
 * @property-read Operator|null $capturedBy
 */
class GuestIdDocument extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'passport' => 'Passport',
        'national_id' => 'National ID (NIN)',
        'drivers_licence' => "Driver's licence",
        'voters_card' => "Voter's card",
        'other' => 'Other ID',
    ];

    protected $guarded = ['id'];

    /** Never serialise the raw fields by accident. */
    protected $hidden = ['number', 'date_of_birth', 'image_path', 'image_disk'];

    protected function casts(): array
    {
        return [
            'number' => 'encrypted',
            'date_of_birth' => 'encrypted',
            'expires_on' => 'date',
            'retain_until' => 'date',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Operator, $this> */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'captured_by_operator_id');
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? 'ID';
    }

    /**
     * "A0881****": the first half of the number (rounded up) then stars
     * for the rest. Numbers of 4 characters or less keep only their
     * first character. Null once purged.
     */
    public function maskedNumber(): ?string
    {
        if ($this->isPurged() || $this->number === null || $this->number === '') {
            return null;
        }

        return self::mask((string) $this->number);
    }

    public static function mask(string $number): string
    {
        $clean = preg_replace('/\s+/', '', $number) ?? $number;
        $len = mb_strlen($clean);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        $keep = $len <= 4 ? 1 : intdiv($len + 1, 2);

        return mb_substr($clean, 0, $keep).str_repeat('*', $len - $keep);
    }
}
