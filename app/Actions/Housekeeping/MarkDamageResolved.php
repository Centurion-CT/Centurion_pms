<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\RoomInspectionItem;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manager / supervisor marks a damage flag resolved (item was
 * replaced / fixed). Drops the row from the property-wide Needs
 * replacement queue. Idempotent — a second call on a resolved row
 * unsets the marker so the operator can undo a hasty tick.
 */
class MarkDamageResolved
{
    public function handle(RoomInspectionItem $item, Operator $operator, bool $resolved = true): RoomInspectionItem
    {
        if (! in_array($operator->role, [OperatorRole::Manager, OperatorRole::Supervisor], true)) {
            throw new RuntimeException('Only managers or supervisors can resolve damage flags.');
        }
        if (trim((string) $item->damage_note) === '') {
            throw new InvalidArgumentException('This item carries no damage flag to resolve.');
        }

        $item->forceFill([
            'resolved_at' => $resolved ? Carbon::now() : null,
            'resolved_by_operator_id' => $resolved ? $operator->id : null,
        ])->save();

        activity('housekeeping')
            ->causedBy($operator)
            ->performedOn($item)
            ->event($resolved ? 'damage_resolved' : 'damage_reopened')
            ->log(sprintf(
                '%s %s damage on "%s" (inspection #%d).',
                $operator->name,
                $resolved ? 'resolved' : 'reopened',
                $item->label,
                $item->room_inspection_id,
            ));

        return $item->refresh();
    }
}
