<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomBookingType;
use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Post-Phase 2 the Room row is lean — only per-unit fields live
 * here. Shared fields (pricing, capacity, etc.) belong to the linked
 * RoomCategory which the factory lazily provisions if the caller
 * didn't supply one.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Resolve the tenant+property first so the category inherits
        // the same scope. The closures defer evaluation until the row
        // is actually inserted, so explicit overrides from the
        // calling test still take precedence.
        return [
            'tenant_id' => Tenant::factory(),
            'property_id' => Property::factory(),
            'room_category_id' => function (array $attributes) {
                return RoomCategory::factory()->create([
                    'tenant_id' => $attributes['tenant_id'],
                    'property_id' => $attributes['property_id'],
                ])->id;
            },
            'room_number' => (string) $this->faker->unique()->numberBetween(100, 999),
            'internal_notes' => null,
            'status' => RoomStatus::Active,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['status' => RoomStatus::Inactive]);
    }

    public function longStay(): self
    {
        // Push the booking-type knobs into the category — the room
        // itself no longer carries them.
        return $this->state(function (array $attributes) {
            $catId = $attributes['room_category_id'] ?? null;
            if ($catId === null) {
                $catId = RoomCategory::factory()->create([
                    'tenant_id' => $attributes['tenant_id'],
                    'property_id' => $attributes['property_id'],
                    'booking_type' => RoomBookingType::LongStay,
                    'monthly_rate' => $this->faker->numberBetween(400_000, 1_200_000),
                    'min_nights' => 28,
                ])->id;
            } else {
                RoomCategory::query()->where('id', $catId)->update([
                    'booking_type' => RoomBookingType::LongStay->value,
                    'monthly_rate' => $this->faker->numberBetween(400_000, 1_200_000),
                    'min_nights' => 28,
                ]);
            }

            return ['room_category_id' => $catId];
        });
    }

    public function both(): self
    {
        return $this->state(function (array $attributes) {
            $catId = $attributes['room_category_id'] ?? null;
            if ($catId === null) {
                $catId = RoomCategory::factory()->create([
                    'tenant_id' => $attributes['tenant_id'],
                    'property_id' => $attributes['property_id'],
                    'booking_type' => RoomBookingType::Both,
                    'monthly_rate' => $this->faker->numberBetween(400_000, 1_200_000),
                ])->id;
            } else {
                RoomCategory::query()->where('id', $catId)->update([
                    'booking_type' => RoomBookingType::Both->value,
                    'monthly_rate' => $this->faker->numberBetween(400_000, 1_200_000),
                ]);
            }

            return ['room_category_id' => $catId];
        });
    }
}
