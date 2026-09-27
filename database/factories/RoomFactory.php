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
     * Category fields that legacy callers still hand to Room's factory
     * via `->create(['base_rate' => 30_000, 'capacity_adults' => 2, ...])`.
     * `newModel()` below intercepts these, routes them to the category
     * row instead, and hands Room only the columns it actually has.
     * Saves rewriting every test's setUp block.
     */
    private const CATEGORY_ATTRIBUTES = [
        'name', 'type', 'description',
        'base_rate', 'monthly_rate', 'min_nights',
        'capacity_adults', 'capacity_children',
        'weekly_discount_percent', 'monthly_discount_percent',
        'deposit_type', 'deposit_value',
        'booking_type', 'breakfast_included_rate',
    ];

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

    /**
     * Split category-owned fields out of the incoming attributes so
     * they land on the RoomCategory row (which they now belong to)
     * instead of blowing up on Room's `create` with "no such column".
     */
    public function newModel(array $attributes = [])
    {
        $categoryOverrides = array_intersect_key($attributes, array_flip(self::CATEGORY_ATTRIBUTES));
        $roomAttributes = array_diff_key($attributes, $categoryOverrides);

        if (! empty($categoryOverrides)) {
            $categoryId = $roomAttributes['room_category_id'] ?? null;
            if ($categoryId === null) {
                $category = RoomCategory::factory()->create(array_merge([
                    'tenant_id' => $roomAttributes['tenant_id'] ?? null,
                    'property_id' => $roomAttributes['property_id'] ?? null,
                ], $categoryOverrides));
                $roomAttributes['room_category_id'] = $category->id;
            } else {
                RoomCategory::query()->where('id', $categoryId)->update(
                    collect($categoryOverrides)
                        ->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)
                        ->all(),
                );
            }
        }

        return parent::newModel($roomAttributes);
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
