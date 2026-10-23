<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Models\MobileDevice;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;

/*
 * Shared helpers for the host app API tests (not a test file itself:
 * Pest only collects *Test.php). Loaded with require_once.
 */

if (! function_exists('hostUrl')) {
    function hostUrl(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->slug.'.'.config('keverna.central_domain').'/api/mobile/v1/host/'.ltrim($path, '/');
    }

    function centralPinUrl(): string
    {
        return 'http://'.config('keverna.central_domain').'/api/mobile/v1/login/pin';
    }

    /**
     * Mints a device + `mobile-device-{id}` token the same way the
     * login / pair flows do. Caller must have the operator's tenant current.
     *
     * @return array<string, string>
     */
    function hostHeaders(Operator $op, array $extra = []): array
    {
        // The test app reuses one auth manager across requests, so the
        // sanctum guard would keep the previous request's operator.
        // Production builds a fresh guard per request; mirror that.
        app('auth')->forgetGuards();

        $device = MobileDevice::query()->create([
            'tenant_id' => $op->tenant_id,
            'operator_id' => $op->id,
            'platform' => 'android',
            'device_name' => 'Pixel',
            'app_version' => '1.0.0',
            'paired_at' => now(),
            'last_seen_at' => now(),
        ]);
        $token = $op->createToken('mobile-device-'.$device->id, ['mobile:*'])->plainTextToken;

        return array_merge(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $extra);
    }

    /**
     * Branch with a category + $rooms active rooms. Tenant must be current.
     *
     * @return array{property: Property, category: RoomCategory, rooms: list<Room>}
     */
    function hostBranch(Tenant $tenant, string $name, int $rooms = 4, string $prefix = '1'): array
    {
        $property = Property::factory()->for($tenant)->create(['name' => $name, 'city' => 'Lagos', 'state' => 'Lagos']);
        $category = RoomCategory::factory()->for($tenant)->for($property)->create([
            'name' => $name.' Deluxe', 'capacity_adults' => 2, 'base_rate' => 30_000, 'min_nights' => 1,
        ]);
        $list = [];
        for ($i = 1; $i <= $rooms; $i++) {
            $list[] = Room::factory()->for($tenant)->for($property)->create([
                'room_category_id' => $category->id,
                'room_number' => $prefix.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => RoomStatus::Active->value,
            ]);
        }

        return ['property' => $property, 'category' => $category, 'rooms' => $list];
    }
}

if (! function_exists('hostWriteKey')) {
    /** Fresh Idempotency-Key header for a host write. */
    function hostWriteKey(): array
    {
        return ['Idempotency-Key' => (string) \Illuminate\Support\Str::ulid()];
    }

    /**
     * A booking on $room with sensible defaults (tenant must be current).
     */
    function hostBooking(Room $room, array $attrs = []): \App\Models\Booking
    {
        return \App\Models\Booking::factory()->for(Tenant::current())->for($room)->create(array_merge([
            'guest_name' => 'Guest '.\Illuminate\Support\Str::random(4),
            'guest_phone' => '+2348011111111',
            'booking_status' => \App\Enums\BookingStatus::Confirmed,
            'checkin_date' => \Illuminate\Support\Carbon::today(),
            'checkout_date' => \Illuminate\Support\Carbon::today()->addDays(2),
            'nights' => 2,
            'room_total' => 60_000,
            'total_amount' => 60_000,
            'balance_due' => 0,
            'source' => 'walk_in',
        ], $attrs));
    }

    /**
     * Booked availability rows for each night in [checkin, checkout).
     */
    function hostReserveNights(\App\Models\Booking $b): void
    {
        for ($d = $b->checkin_date->copy(); $d->lt($b->checkout_date); $d->addDay()) {
            \App\Models\AvailabilityBlock::query()->create([
                'tenant_id' => $b->tenant_id,
                'room_id' => $b->room_id,
                'date' => $d->toDateString(),
                'type' => \App\Enums\AvailabilityBlockType::Booked->value,
                'booking_id' => $b->id,
            ]);
        }
    }
}
