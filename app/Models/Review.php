<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $property_id
 * @property int|null $room_id
 * @property int|null $booking_id
 * @property string $guest_name
 * @property string $body
 * @property string|null $response_body
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property int|null $responded_by_operator_id
 * @property int $rating
 * @property \App\Enums\ReviewChannel $channel
 * @property string|null $guest_country
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property string|null $photo_path
 * @property bool $is_displayed
 * @property int $order_column
 * @property-read Tenant $tenant
 * @property-read Property $property
 * @property-read Room|null $room
 * @property-read Booking|null $booking
 * @property-read Operator|null $respondedBy
 */
class Review extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_displayed' => 'boolean',
            'responded_at' => 'datetime',
            'published_at' => 'datetime',
            'channel'      => \App\Enums\ReviewChannel::class,
        ];
    }

    protected static function newFactory(): ReviewFactory
    {
        return ReviewFactory::new();
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Reviews may target a specific room (room_id set) or be
     * property-wide (room_id null).
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Reviews submitted via the guest portal are tied to the booking
     * they slept on — used to render a "Verified stay" badge publicly
     * and to enforce one-review-per-booking.
     *
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isVerifiedStay(): bool
    {
        return $this->booking_id !== null;
    }

    public function hasResponse(): bool
    {
        return $this->response_body !== null && $this->response_body !== '';
    }

    /**
     * Public URL for the reviewer photo, resolved through the same disk
     * that Spatie media-library uses (`MEDIA_DISK`, defaults to `public`
     * locally, `dokwe` in prod). Kept as a helper so the editor +
     * dashboard index + tenant landing + marketplace stays/show all
     * agree on which storage backend the file actually lives on.
     */
    public function photoUrl(): ?string
    {
        if (empty($this->photo_path)) {
            return null;
        }

        $disk = config('media-library.disk_name', 'public');

        return \Illuminate\Support\Facades\Storage::disk($disk)->url($this->photo_path);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'responded_by_operator_id');
    }

    /**
     * Public-facing scope used by LandingController (PRD §6.4.1).
     *
     * @param  Builder<Review>  $query
     * @return Builder<Review>
     */
    public function scopeDisplayed(Builder $query): Builder
    {
        return $query->where('is_displayed', true)->orderBy('order_column');
    }
}
