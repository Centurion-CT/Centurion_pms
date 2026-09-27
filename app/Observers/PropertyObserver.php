<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;

/**
 * Auto-provisioning for single-unit properties (standalone
 * apartments / condos / bungalows). The whole booking + availability
 * + pricing engine downstream expects a Property → RoomCategory →
 * Room chain, so a single_unit property still needs one of each
 * — just hidden from the operator UI. The operator manages the
 * property directly; this observer keeps the underlying skeleton
 * consistent.
 */
class PropertyObserver
{
    public function created(Property $property): void
    {
        if (! $property->isSingleUnit()) return;
        $this->ensureSingleUnitSkeleton($property);
    }

    public function updated(Property $property): void
    {
        // Late flip: existing multi-room property switched to
        // single_unit. Only spin up the skeleton if there isn't one.
        // Front-desk callers rarely flip this at runtime — the flag
        // is meant to be picked at property creation — but we keep
        // the code idempotent so admin backfills don't double-write.
        if (! $property->isSingleUnit()) return;
        $this->ensureSingleUnitSkeleton($property);
    }

    private function ensureSingleUnitSkeleton(Property $property): void
    {
        $existingCategory = RoomCategory::query()
            ->where('property_id', $property->id)
            ->orderBy('id')
            ->first();

        $category = $existingCategory ?: RoomCategory::query()->create([
            'tenant_id'         => $property->tenant_id,
            'property_id'       => $property->id,
            'name'              => $property->name,
            'type'              => 'single_unit', // free-form label; hidden from the operator UI
            'description'       => null,
            'base_rate'         => 0,
            'capacity_adults'   => 2,
            'capacity_children' => 0,
            'booking_type'      => 'short_let',
        ]);

        $hasRoom = Room::query()
            ->where('room_category_id', $category->id)
            ->exists();
        if ($hasRoom) return;

        Room::query()->create([
            'tenant_id'        => $property->tenant_id,
            'property_id'      => $property->id,
            'room_category_id' => $category->id,
            'room_number'      => 'unit-1',
            'status'           => RoomStatus::Active->value,
        ]);
    }
}
