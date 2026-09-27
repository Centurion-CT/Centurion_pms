<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Database\Factories\BookingAccessTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property-read Tenant $tenant
 * @property-read Booking $booking
 */
class BookingAccessToken extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingAccessTokenFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BookingAccessTokenFactory
    {
        return BookingAccessTokenFactory::new();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isExpired(): bool
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }
}
