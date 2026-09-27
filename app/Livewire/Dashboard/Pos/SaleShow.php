<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Pos;

use App\Actions\Pos\RefundPosSaleLines;
use App\Actions\Pos\VoidPosSale;
use App\Enums\OperatorRole;
use App\Enums\PosSaleStatus;
use App\Livewire\Concerns\UsesPosKioskLayout;
use App\Models\PosSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One sale's detail: lines, payment, print receipt, void action
 * (Manager only). PosStaff can view sales for their assigned outlets.
 *
 * Layout chosen dynamically via UsesPosKioskLayout, see Terminal.php.
 */
class SaleShow extends Component
{
    use UsesPosKioskLayout;

    public int $saleId;
    public bool $confirmingVoid = false;
    public string $voidReason = '';
    public ?string $voidError = null;

    /** Refund UI state. */
    public bool $refundOpen = false;
    /** @var array<int, int> line_id => qty to return */
    public array $refundQty = [];
    public string $refundReason = '';
    public string $refundMethod = 'cash';
    public ?string $refundError = null;

    public function mount(PosSale $sale): void
    {
        $operator = auth('web')->user();
        if ($operator?->role !== OperatorRole::Manager) {
            $assigned = $operator->outlets()->where('outlets.id', $sale->outlet_id)->exists();
            if (! $assigned) {
                throw new NotFoundHttpException;
            }
        }
        $this->saleId = $sale->id;
    }

    public function askVoid(): void
    {
        $this->confirmingVoid = true;
        $this->voidReason = '';
        $this->voidError = null;
    }

    public function cancelVoid(): void
    {
        $this->confirmingVoid = false;
        $this->voidReason = '';
    }

    public function confirmVoid(VoidPosSale $void): void
    {
        $this->voidError = null;
        $reason = trim($this->voidReason);
        if ($reason === '') {
            $this->voidError = 'A reason is required.';
            return;
        }

        try {
            $sale = PosSale::query()->findOrFail($this->saleId);
            $void->handle($sale, auth('web')->user(), $reason);
        } catch (RuntimeException $e) {
            $this->voidError = $e->getMessage();
            return;
        }

        session()->flash('status', "Sale voided.");
        $this->confirmingVoid = false;
        $this->voidReason = '';
    }

    /* ── Partial refund ──────────────────────────────────────────── */

    public function openRefund(): void
    {
        $this->refundOpen = true;
        $this->refundReason = '';
        $this->refundError = null;
        $this->refundQty = [];
        $sale = PosSale::query()->with('lines')->findOrFail($this->saleId);
        foreach ($sale->lines as $l) {
            $this->refundQty[$l->id] = 0;
        }
    }

    public function cancelRefund(): void
    {
        $this->refundOpen = false;
        $this->refundQty = [];
        $this->refundReason = '';
        $this->refundError = null;
    }

    public function confirmRefund(RefundPosSaleLines $action): void
    {
        $this->refundError = null;
        $reason = trim($this->refundReason);
        if ($reason === '') {
            $this->refundError = 'A reason is required.';
            return;
        }

        $returns = collect($this->refundQty)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->map(fn ($v, $lineId) => ['sale_line_id' => (int) $lineId, 'quantity' => $v])
            ->values()
            ->all();

        if ($returns === []) {
            $this->refundError = 'Enter a return quantity on at least one line.';
            return;
        }

        try {
            $sale = PosSale::query()->with('lines')->findOrFail($this->saleId);
            $return = $action->handle(
                sale: $sale,
                operator: auth('web')->user(),
                returns: $returns,
                reason: $reason,
                refundMethod: $this->refundMethod,
            );
        } catch (\Throwable $e) {
            $this->refundError = $e->getMessage();
            return;
        }

        session()->flash('status', 'Refund of ₦'.number_format((int) $return->refund_amount).' recorded.');
        $this->cancelRefund();
    }

    public function render(): View
    {
        $sale = PosSale::query()
            ->with(['outlet', 'operator', 'booking:id,booking_ref,guest_name', 'customer', 'lines', 'payments', 'returns.lines'])
            ->findOrFail($this->saleId);

        $operator = auth('web')->user();
        $canVoid = $operator?->role === OperatorRole::Manager
            && $sale->status === PosSaleStatus::Completed;
        $canRefund = $canVoid; // same policy for now

        // Per-line: how many still refundable = original qty - already-returned.
        $returnedByLine = DB::table('pos_sale_return_lines')
            ->whereIn('pos_sale_line_id', $sale->lines->pluck('id'))
            ->selectRaw('pos_sale_line_id, SUM(quantity) as total')
            ->groupBy('pos_sale_line_id')
            ->pluck('total', 'pos_sale_line_id');

        $refundableByLine = $sale->lines
            ->mapWithKeys(fn ($l) => [$l->id => max(0, (int) $l->quantity - (int) ($returnedByLine[$l->id] ?? 0))])
            ->all();
        $anyRefundable = collect($refundableByLine)->sum() > 0;

        return $this->applyKioskLayout(view('livewire.dashboard.pos.sale-show', [
            'sale'              => $sale,
            'canVoid'           => $canVoid,
            'canRefund'         => $canRefund && $anyRefundable,
            'refundableByLine'  => $refundableByLine,
        ]));
    }
}
