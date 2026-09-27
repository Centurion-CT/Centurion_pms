<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Room
 */
class AvailableRoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->category;

        return [
            'id' => $this->id,
            'room_number' => $this->room_number,
            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->name,
                'capacity_adults' => (int) $category->capacity_adults,
                'capacity_children' => (int) $category->capacity_children,
                'base_rate' => (int) $category->base_rate,
                'breakfast_rate' => $category->breakfast_rate !== null
                    ? (int) $category->breakfast_rate : null,
                'has_breakfast_option' => (bool) ($category->hasBreakfastOption() ?? false),
            ] : null,
        ];
    }
}
