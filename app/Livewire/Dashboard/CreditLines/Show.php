<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\CreditLines;

use App\Actions\CreditLines\AddCreditLineCharge;
use App\Actions\CreditLines\RecordCreditLineSettlement;
use App\Enums\BookingChargeType;
use App\Models\CreditLine;
use App\Models\Operator;
use App\Models\Room;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Credit-line detail, running totals, charges + settlements ledger,
 * inline forms to add a new charge or record an offline settlement.
 */
#[Layout('layouts.tenant-auth')]
class Show extends Component
{
    #[Locked]
    public int $creditLineId;

    // Date-range filter for the report card + ledger.
    #[Url(as: 'from')]
    public string $fromDate = '';

    #[Url(as: 'to')]
    public string $toDate = '';

    // Add-charge form
    public string $newChargeType = 'food_beverage';

    public string $newChargeDescription = '';

    public ?int $newChargeAmount = null;

    public ?int $newChargeRoomId = null;

    // Record-settlement form
    public ?int $settlementAmount = null;

    public string $settlementMethod = 'cash';

    public string $settlementNote = '';

    public function mount(CreditLine $creditLine): void
    {
        $this->creditLineId = $creditLine->id;
    }

    public function addCharge(AddCreditLineCharge $action): void
    {
        $this->validate([
            'newChargeType' => ['required', 'string'],
            'newChargeDescription' => ['required', 'string', 'max:240'],
            'newChargeAmount' => ['required', 'integer', 'min:1'],
            'newChargeRoomId' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        $this->run(function () use ($action): void {
            $type = BookingChargeType::tryFrom($this->newChargeType) ?? BookingChargeType::Other;
            $room = $this->newChargeRoomId !== null
                ? Room::query()->find($this->newChargeRoomId)
                : null;
            $action->handle(
                line: $this->line(),
                type: $type,
                description: $this->newChargeDescription,
                amount: (int) $this->newChargeAmount,
                operator: $this->operator(),
                room: $room,
            );
        }, 'Charge added.');

        $this->newChargeDescription = '';
        $this->newChargeAmount = null;
    }

    public function recordSettlement(RecordCreditLineSettlement $action): void
    {
        $this->validate([
            'settlementAmount' => ['required', 'integer', 'min:1'],
            'settlementMethod' => ['required', 'string'],
            'settlementNote' => ['nullable', 'string', 'max:240'],
        ]);

        $this->run(function () use ($action): void {
            $action->handle(
                line: $this->line(),
                amount: (int) $this->settlementAmount,
                method: $this->settlementMethod,
                referenceNote: $this->settlementNote,
                operator: $this->operator(),
            );
        }, 'Settlement recorded.');

        $this->settlementAmount = null;
        $this->settlementNote = '';
    }

    public function clearRangeFilter(): void
    {
        $this->fromDate = '';
        $this->toDate = '';
    }

    public function render(): View
    {
        $line = CreditLine::query()
            ->with(['customer', 'rooms.category'])
            ->findOrFail($this->creditLineId);

        // Ledger, charges + settlements interleaved, filtered by
        // the optional date range. Both queries respect BelongsToTenant.
        $chargesQ = $line->charges()->with('room')->orderByDesc('created_at');
        $settlementsQ = $line->settlements()->orderByDesc('settled_at');
        if ($this->fromDate !== '') {
            $chargesQ->whereDate('created_at', '>=', $this->fromDate);
            $settlementsQ->whereDate('settled_at', '>=', $this->fromDate);
        }
        if ($this->toDate !== '') {
            $chargesQ->whereDate('created_at', '<=', $this->toDate);
            $settlementsQ->whereDate('settled_at', '<=', $this->toDate);
        }
        $charges = $chargesQ->get();
        $settlements = $settlementsQ->get();

        // Report card, always shows lifetime totals so the operator
        // sees the actual outstanding regardless of the filter.
        $chargedTotal = $line->chargedTotal();
        $settledTotal = $line->settledTotal();
        $outstandingTotal = max(0, $chargedTotal - $settledTotal);

        // Rooms available to attach as a charge origin, the currently
        // attached set.
        $attachedRooms = $line->rooms;

        return view('livewire.dashboard.credit-lines.show', [
            'line' => $line,
            'charges' => $charges,
            'settlements' => $settlements,
            'chargedTotal' => $chargedTotal,
            'settledTotal' => $settledTotal,
            'outstandingTotal' => $outstandingTotal,
            'attachedRooms' => $attachedRooms,
            'chargeTypeOptions' => BookingChargeType::manualOptions(),
            'settlementMethods' => RecordCreditLineSettlement::METHODS,
        ]);
    }

    private function line(): CreditLine
    {
        return CreditLine::query()->findOrFail($this->creditLineId);
    }

    private function operator(): ?Operator
    {
        $op = auth('web')->user();

        return $op instanceof Operator ? $op : null;
    }

    private function run(callable $fn, string $successMessage): void
    {
        try {
            $fn();
            $this->dispatch('notify', title: 'Done', message: $successMessage, kind: 'ok');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->dispatch('notify', title: 'Couldn\'t complete', message: $e->getMessage(), kind: 'err');
        }
    }
}
