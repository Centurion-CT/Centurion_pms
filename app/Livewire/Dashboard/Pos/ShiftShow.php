<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Actions\Pos\CloseOutletShift;
use App\Enums\OperatorRole;
use App\Enums\PosSaleStatus;
use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\Operator;
use App\Models\OutletShift;
use App\Models\PosSale;
use App\Models\PosSaleLine;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Single-shift report. Rolls up:
 *
 *   - Shift metadata (operator, times, close reason)
 *   - Sales summary (count, gross, net after voids, comp value)
 *   - Per-tender breakdown (cash / transfer / card / room / wallet)
 *   - Per-product stock reconciliation:
 *       opening qty → sold in window → other ± movements → closing qty
 *       → variance (closing_reported vs closing_expected)
 *
 * All figures live on the Livewire page for role-scoped view. The
 * matching CSV export routes to OutletShiftExportController with the
 * same shape.
 */
class ShiftShow extends Component
{
    use UsesPosKioskLayout;

    public int $shiftId;

    public string $closeNotes = '';

    public string $closeReason = '';

    public function mount(OutletShift $shift): void
    {
        $shift->load(['outlet', 'operator', 'closedBy', 'snapshots']);
        $this->authorise($shift);
        $this->shiftId = $shift->id;
    }

    public function closeShift(CloseOutletShift $action): void
    {
        $shift = $this->loadShift();
        $operator = Operator::query()->findOrFail(auth('web')->id());
        try {
            $action->handle($shift, $operator, $this->closeNotes ?: null, $this->closeReason ?: null);
            $this->dispatch('notify', title: 'Shift closed', message: 'Report is ready to download.', kind: 'ok');
            $this->closeNotes = '';
            $this->closeReason = '';
        } catch (RuntimeException $e) {
            $this->dispatch('notify', title: "Couldn't close", message: $e->getMessage(), kind: 'err');
        }
    }

    public function render(\App\Services\Pos\ShiftReportBuilder $builder): View
    {
        $shift = $this->loadShift();
        $this->authorise($shift);

        $report = $builder->build($shift);

        return $this->applyKioskLayout(view('livewire.dashboard.pos.shift-show', [
            'shift' => $shift,
            'report' => $report,
        ]));
    }

    private function loadShift(): OutletShift
    {
        $shift = OutletShift::query()
            ->with(['outlet', 'operator', 'closedBy', 'snapshots'])
            ->find($this->shiftId);
        if ($shift === null) {
            throw new NotFoundHttpException();
        }

        return $shift;
    }

    private function authorise(OutletShift $shift): void
    {
        $operator = auth('web')->user();
        if ($operator === null) {
            throw new NotFoundHttpException();
        }
        $isManager = $operator->role === OperatorRole::Manager;
        $isOwn = $shift->operator_id === $operator->id;
        if (! $isManager && ! $isOwn) {
            throw new NotFoundHttpException();
        }
    }
}
