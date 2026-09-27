<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Property;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Follows the RoomCategoryFactory convention: independent Tenant and
 * Property factories by default; tests that care about the join pass
 * tenant_id/property_id explicitly.
 *
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    protected $model = Store::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'property_id' => Property::factory(),
            'name'        => 'Main store',
            'slug'        => null, // Store::booted() generates a unique slug
            'notes'       => null,
            'active'      => true,
        ];
    }
}
