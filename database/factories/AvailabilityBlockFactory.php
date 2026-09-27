<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AvailabilityBlockType;
use App\Models\AvailabilityBlock;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityBlock>
 */
class AvailabilityBlockFactory extends Factory
{
    protected $model = AvailabilityBlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'room_id' => Room::factory(),
            'date' => $this->faker->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'type' => AvailabilityBlockType::Blocked,
            'reason' => 'Maintenance',
            'booking_id' => null,
        ];
    }

    public function booked(): self
    {
        return $this->state(fn () => [
            'type' => AvailabilityBlockType::Booked,
            'reason' => null,
        ]);
    }
}
