<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\RoomInspection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomInspection
 */
class InspectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $room = $this->room;
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->orderBy('sort_order')->get();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'waiting_on' => $this->status->waitingOn(),
            'readiness_score' => (int) $this->readiness_score,
            'summary_note' => $this->summary_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'housekeeper_at' => $this->housekeeper_at?->toIso8601String(),
            'porter_at' => $this->porter_at?->toIso8601String(),
            'supervisor_at' => $this->supervisor_at?->toIso8601String(),
            'room' => $room ? [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'category_name' => $room->category?->name,
            ] : null,
            'triggered_by' => $this->triggeredByBooking ? [
                'booking_ref' => $this->triggeredByBooking->booking_ref,
                'guest_name' => $this->triggeredByBooking->guest_name,
            ] : null,
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'label' => $item->label,
                'checked' => (bool) $item->checked,
                'damage_note' => $item->damage_note,
                'sort_order' => (int) $item->sort_order,
            ])->values(),
        ];
    }
}
