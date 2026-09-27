<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Database\Factories\BookingChangeRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property Carbon $requested_checkin
 * @property Carbon $requested_checkout
 * @property string|null $guest_note
 * @property string $status
 * @property string|null $operator_response
 * @property Carbon|null $decided_at
 * @property-read Booking $booking
 */
class BookingChangeRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingChangeRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_checkin' => 'date',
            'requested_checkout' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BookingChangeRequestFactory
    {
        return BookingChangeRequestFactory::new();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
