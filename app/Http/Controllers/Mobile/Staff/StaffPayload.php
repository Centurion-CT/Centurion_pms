<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Staff;

use App\Enums\BookingStatus;
use App\Enums\RoomInspectionStatus;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\RoomInspection;
use App\Support\Housekeeping\ChecklistPictures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * JSON shapes for the staff app (docs/staff-app/PLAN.md) and the few
 * queries every staff endpoint shares. Times go out in Lagos time with
 * the offset, and "today" is the Lagos day, since the app server runs
 * on UTC.
 */
final class StaffPayload
{
    public const TZ = 'Africa/Lagos';

    public static function now(): Carbon
    {
        return Carbon::now(self::TZ);
    }

    /** Start of today in Lagos, as a UTC instant for column comparisons. */
    public static function todayStart(): Carbon
    {
        return self::now()->startOfDay()->utc();
    }

    public static function iso(?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone(self::TZ)->toIso8601String();
    }

    /** @return array<string, mixed> */
    public static function operator(Operator $op): array
    {
        return [
            'id' => $op->id,
            'name' => $op->name,
            'first_name' => $op->firstName(),
            'initials' => $op->initials(),
            'role' => $op->role?->value,
            'role_label' => $op->role?->label(),
            'phone' => $op->phone,
        ];
    }

    /**
     * Inspections on the properties this operator works. No branch rows
     * means every property (same rule as Operator::hasAccessToBranch).
     *
     * @return Builder<RoomInspection>
     */
    public static function inspectionsFor(Operator $op): Builder
    {
        $branchIds = $op->accessibleBranchIds();

        return RoomInspection::query()
            ->with(['room.category', 'category', 'items'])
            ->when($branchIds !== [], fn (Builder $q) => $q->whereHas(
                'room',
                fn (Builder $r) => $r->whereIn('property_id', $branchIds),
            ));
    }

    /**
     * Rooms with a guest checking in today (Lagos date) who isn't in yet.
     *
     * @param  iterable<int>  $roomIds
     * @return array<int, bool> room_id => true
     */
    public static function arrivingToday(iterable $roomIds): array
    {
        $ids = collect($roomIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Booking::query()
            ->whereIn('room_id', $ids)
            ->whereDate('checkin_date', self::now()->toDateString())
            ->whereIn('booking_status', [
                BookingStatus::Pending->value,
                BookingStatus::Confirmed->value,
                BookingStatus::OnHold->value,
            ])
            ->pluck('room_id')
            ->unique()
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    public static function state(RoomInspection $i): string
    {
        return match ($i->status) {
            RoomInspectionStatus::Pending => match (true) {
                $i->sent_back_at !== null => 'sent_back',
                $i->started_at !== null => 'cleaning',
                default => 'to_clean',
            },
            RoomInspectionStatus::CleaningDone => 'with_porter',
            RoomInspectionStatus::PorterVerified, RoomInspectionStatus::Passed => 'ready',
            RoomInspectionStatus::Failed => 'to_clean',
        };
    }

    /**
     * @param  array<int, bool>  $arriving
     * @return array<string, mixed>
     */
    public static function room(RoomInspection $i, Operator $me, array $arriving, bool $doFirst = false): array
    {
        $state = self::state($i);
        $items = $i->items;
        $sentBack = null;
        if ($state === 'sent_back') {
            $reasons = array_values((array) ($i->sent_back_reasons ?? []));
            $by = $i->sent_back_by_operator_id !== null ? Operator::query()->find($i->sent_back_by_operator_id) : null;
            $sentBack = [
                'by' => $by?->firstName(),
                'at' => self::iso($i->sent_back_at),
                'reasons' => $reasons,
                'reasons_label' => ChecklistPictures::reasonsLabel($reasons),
                'note' => $i->sent_back_note,
            ];
        }

        return [
            'inspection_id' => $i->id,
            'room_id' => $i->room_id,
            'number' => (string) ($i->room?->room_number ?? $i->room_id),
            'category' => $i->room?->category?->name ?? $i->category?->name,
            'state' => $state,
            'do_first' => $doFirst,
            'arriving_today' => isset($arriving[$i->room_id]),
            'arriving_at' => null,
            'guest_left_at' => self::iso($i->created_at),
            'started_at' => self::iso($i->started_at),
            'assigned_to_me' => $i->assigned_operator_id === $me->id,
            'progress' => [
                'done' => $items->where('checked', true)->count(),
                'total' => $items->count(),
            ],
            'sent_back' => $sentBack,
        ];
    }

    /**
     * The checklist in Bathroom / Bedroom / Extras order; empty groups
     * are left out.
     *
     * @return list<array<string, mixed>>
     */
    public static function groups(RoomInspection $i): array
    {
        $byGroup = $i->items
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->groupBy(fn ($item) => ChecklistPictures::group($item->label));

        $groups = [];
        foreach (ChecklistPictures::GROUPS as $key => $label) {
            $items = $byGroup->get($key);
            if ($items === null || $items->isEmpty()) {
                continue;
            }
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'items' => $items->map(fn ($item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'icon' => ChecklistPictures::icon($item->label),
                    'checked' => (bool) $item->checked,
                ])->values()->all(),
            ];
        }

        return $groups;
    }

    /**
     * @param  array<int, bool>  $arriving
     * @return array<string, mixed>
     */
    public static function check(RoomInspection $i, array $arriving): array
    {
        $cleaner = $i->housekeeper_by_operator_id !== null
            ? Operator::query()->find($i->housekeeper_by_operator_id)
            : null;
        $minutes = $i->started_at !== null && $i->housekeeper_at !== null && $i->housekeeper_at->gte($i->started_at)
            ? (int) $i->started_at->diffInMinutes($i->housekeeper_at)
            : null;

        return [
            'inspection_id' => $i->id,
            'number' => (string) ($i->room?->room_number ?? $i->room_id),
            'category' => $i->room?->category?->name ?? $i->category?->name,
            'cleaned_by' => $cleaner === null ? null : [
                'name' => $cleaner->name,
                'first_name' => $cleaner->firstName(),
                'initials' => $cleaner->initials(),
            ],
            'cleaned_at' => self::iso($i->housekeeper_at),
            'minutes' => $minutes,
            'score' => (int) $i->readiness_score,
            'arriving_today' => isset($arriving[$i->room_id]),
            'arriving_at' => null,
            'note' => $i->summary_note,
            'send_back_count' => (int) $i->send_back_count,
        ];
    }

    /**
     * @param  Collection<int, RoomInspection>  $inspections
     * @return array<int, bool>
     */
    public static function arrivingFor(Collection $inspections): array
    {
        return self::arrivingToday($inspections->pluck('room_id'));
    }
}
