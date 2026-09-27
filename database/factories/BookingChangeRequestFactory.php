<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingChangeRequest>
 */
class BookingChangeRequestFactory extends Factory
{
    protected $model = BookingChangeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkin = $this->faker->dateTimeBetween('+10 days', '+30 days');
        $checkout = (clone $checkin)->modify('+'.$this->faker->numberBetween(2, 4).' days');

        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => Booking::factory(),
            'requested_checkin' => $checkin->format('Y-m-d'),
            'requested_checkout' => $checkout->format('Y-m-d'),
            'guest_note' => $this->faker->sentence(),
            'status' => BookingChangeRequest::STATUS_PENDING,
            'operator_response' => null,
            'decided_at' => null,
        ];
    }
}
