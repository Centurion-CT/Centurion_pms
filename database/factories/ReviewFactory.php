<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'property_id' => Property::factory(),
            'room_id' => null,    // property-wide by default; forRoom() attaches one
            'booking_id' => null, // operator-authored by default; forBooking() attaches one
            'guest_name' => $this->faker->name(),
            'body' => $this->faker->paragraph(),
            'rating' => $this->faker->numberBetween(4, 5),
            'is_displayed' => true,
            'order_column' => 0,
        ];
    }

    public function hidden(): self
    {
        return $this->state(fn () => ['is_displayed' => false]);
    }

    public function forRoom(Room $room): self
    {
        return $this->state(fn () => ['room_id' => $room->id]);
    }

    public function forBooking(Booking $booking): self
    {
        return $this->state(fn () => [
            'booking_id' => $booking->id,
            'room_id' => $booking->room_id,
        ]);
    }
}
