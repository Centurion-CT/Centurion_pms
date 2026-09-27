<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Housekeeping;

use App\Enums\BookingStatus;
use App\Enums\OperatorRole;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\RoomInspection;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dedicated "Inspections" page, three lanes for the sign-off
 * chain + a small recent-history tail. Sits alongside the older
 * per-room housekeeping status board so operators get a direct
 * sidebar path to the workflow the owner asked us to build.
 */
#[Layout('layouts.tenant-auth')]
class Inspections extends Component
{
    public function render(): View
    {
        $op = auth('web')->user();
        $role = $op instanceof Operator ? $op->role : null;

        $open = RoomInspection::query()
            ->with(['room.category', 'triggeredByBooking'])
            ->whereNotIn('status', [
                RoomInspectionStatus::Passed->value,
                RoomInspectionStatus::Failed->value,
            ])
            ->orderBy('created_at')
            ->get();

        $lanes = [
            'pending'          => $open->where('status', RoomInspectionStatus::Pending),
            'cleaning_done'    => $open->where('status', RoomInspectionStatus::CleaningDone),
            'porter_verified'  => $open->where('status', RoomInspectionStatus::PorterVerified),
        ];

        // Highlight the lane matching the operator's role as "my queue".
        $myLane = match ($role) {
            OperatorRole::HouseCleaner => 'pending',
            OperatorRole::Porter       => 'cleaning_done',
            OperatorRole::Supervisor   => 'porter_verified',
            default                    => null,
        };

        // Recent history, the last 15 closed inspections. Useful for
        // supervisors auditing the day, and for anyone looking up what
        // happened to a rejected room.
        $recent = RoomInspection::query()
            ->with(['room.category'])
            ->whereIn('status', [
                RoomInspectionStatus::Passed->value,
                RoomInspectionStatus::Failed->value,
            ])
            ->latest('updated_at')
            ->limit(15)
            ->get();

        // Which of the rooms in the recent list are occupied right now?
        // The blade uses this to add an "Occupied now" tag next to
        // rooms whose last inspection reads "Ready for guest" but has
        // since been claimed by a new booking, otherwise the row
        // reads as if the room were still available.
        $currentOccupantRoomIds = Booking::query()
            ->whereIn('room_id', $recent->pluck('room_id')->filter()->unique())
            ->where('booking_status', BookingStatus::CheckedIn->value)
            ->pluck('room_id')
            ->all();

        return view('livewire.dashboard.housekeeping.inspections', [
            'lanes' => $lanes,
            'myLane' => $myLane,
            'recent' => $recent,
            'currentOccupantRoomIds' => $currentOccupantRoomIds,
        ]);
    }
}
