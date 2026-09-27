<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\OperatorRole;
use App\Models\Operator;
use App\Models\OutletShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Close a shift. The operator who opened the shift or a Manager can
 * close it. On close we snapshot closing stock and stamp closed_at +
 * closed_by_operator_id so the report knows who did it.
 *
 * `reason` is optional but strongly recommended when a Manager
 * force-closes someone else's shift ("stale overnight", "till
 * handoff"). It surfaces on the report + the CSV.
 */
class CloseOutletShift
{
    public function __construct(private readonly OpenOutletShift $opener)
    {
    }

    public function handle(OutletShift $shift, Operator $closer, ?string $notes = null, ?string $reason = null): OutletShift
    {
        return DB::transaction(function () use ($shift, $closer, $notes, $reason): OutletShift {
            // Re-read inside the transaction with a lock to avoid two
            // concurrent close attempts (e.g. operator closes while the
            // manager force-closes at the same moment) writing conflicting
            // closed_at timestamps.
            $shift = OutletShift::query()->lockForUpdate()->find($shift->id);
            if ($shift === null) {
                throw new RuntimeException('Shift no longer exists.');
            }
            if ($shift->closed_at !== null) {
                throw new RuntimeException('That shift is already closed.');
            }

            $isOwn = $shift->operator_id === $closer->id;
            $isManager = $closer->role === OperatorRole::Manager;
            if (! $isOwn && ! $isManager) {
                throw new RuntimeException('Only the salesperson who opened the shift or a Manager can close it.');
            }

            $now = Carbon::now();

            $this->opener->snapshotStock($shift, kind: 'closing', capturedAt: $now);

            $shift->forceFill([
                'closed_at' => $now,
                'closed_by_operator_id' => $closer->id,
                'close_reason' => $reason !== null && $reason !== '' ? substr(trim($reason), 0, 200) : null,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : $shift->notes,
            ])->save();

            activity('outlet_shift')
                ->causedBy($closer)
                ->performedOn($shift)
                ->event('shift_closed')
                ->withProperties([
                    'outlet' => $shift->outlet?->name,
                    'closed_at' => $now->toIso8601String(),
                    'by' => $closer->name,
                    'reason' => $reason,
                    'force' => ! $isOwn,
                ])
                ->log(sprintf(
                    '%s closed shift #%d on %s%s.',
                    $closer->name,
                    $shift->id,
                    $shift->outlet?->name ?? 'outlet',
                    ! $isOwn ? ' (force-close)' : '',
                ));

            return $shift->fresh(['snapshots', 'operator', 'closedBy', 'outlet']);
        });
    }
}
