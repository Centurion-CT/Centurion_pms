<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomPrice>
 */
class RoomPriceFactory extends Factory
{
    protected $model = RoomPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('now', '+6 months');
        $end = (clone $start)->modify('+'.$this->faker->numberBetween(2, 14).' days');

        return [
            'tenant_id' => Tenant::factory(),
            'room_id' => Room::factory(),
            'start_date' => $start,
            'end_date' => $end,
            'rate' => $this->faker->numberBetween(30_000, 150_000),
            'label' => $this->faker->randomElement(['Festive Season', 'Easter Weekend', 'Peak Week', null]),
        ];
    }
}
