<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DepositType;
use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomCategory>
 */
class RoomCategoryFactory extends Factory
{
    protected $model = RoomCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'property_id' => Property::factory(),
            'name' => $this->faker->randomElement(['Deluxe Suite', 'Studio', 'Garden Loft', 'King Room']),
            'type' => $this->faker->randomElement(['Single', 'Double', 'Twin', 'Suite', 'Studio', 'Entire Unit']),
            'description' => $this->faker->paragraph(),
            'capacity_adults' => $this->faker->numberBetween(1, 4),
            'capacity_children' => $this->faker->numberBetween(0, 2),
            'base_rate' => $this->faker->numberBetween(15_000, 75_000),
            'monthly_rate' => null,
            'min_nights' => 1,
            'weekly_discount_percent' => 0,
            'monthly_discount_percent' => 0,
            'deposit_type' => DepositType::Percent,
            'deposit_value' => 30,
            'booking_type' => RoomBookingType::ShortLet,
            'status' => RoomStatus::Active,
            'rules' => null,
        ];
    }
}
