<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\SearchCacheBookingResource;
use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Push signal for the dashboard's IndexedDB search cache. Broadcast
 * per-tenant whenever a booking's search-cache-relevant fields
 * change, so the operator's local store can upsert / delete the row
 * without waiting for the next 5-minute poll.
 *
 * Two shapes carried by the same event:
 *   * kind='upsert' → payload is the SearchCacheBookingResource row
 *   * kind='delete' → payload is { id } only
 *
 * Payload is intentionally the same shape the API returns so the
 * frontend can shove it straight into IDB with no translation.
 */
class BookingSearchCacheMutated implements ShouldBroadcastNow, NotTenantAware
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public int $tenantId,
        public string $kind,
        public array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('tenant.'.$this->tenantId.'.bookings');
    }

    public function broadcastAs(): string
    {
        return 'booking.search-cache.mutated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'kind'    => $this->kind,
            'payload' => $this->payload,
        ];
    }

    /**
     * Build the upsert event from a hydrated Booking model.
     */
    public static function upsertFor(Booking $booking): self
    {
        // Eager-load the room + property so the resource can shape
        // room_name + property_name without a lazy load surprise.
        $booking->loadMissing(['room', 'room.property']);

        $payload = (new SearchCacheBookingResource($booking))
            ->toArray(request());

        return new self((int) $booking->tenant_id, 'upsert', $payload);
    }

    /**
     * Build the delete event from a booking id and tenant id.
     */
    public static function deleteFor(int $tenantId, int $bookingId): self
    {
        return new self($tenantId, 'delete', ['id' => $bookingId]);
    }
}
