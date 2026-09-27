<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingAccessToken;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingAccessToken>
 */
class BookingAccessTokenFactory extends Factory
{
    protected $model = BookingAccessToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => Booking::factory(),
            'token_hash' => hash('sha256', Str::random(48)),
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
        ];
    }
}
