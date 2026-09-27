<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Stores;

use App\Actions\Inventory\ApproveStockRequest;
use App\Actions\Inventory\RejectStockRequest;
use App\Actions\Inventory\SavePhysicalCount;
use App\Models\Operator;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Store;
use App\Services\Inventory\DailyStockSheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Store keeper landing, daily stock sheet for the property's central
 * store on the selected date. Physical count + remark on each row
 * saves inline via {@see SavePhysicalCount}. Store scope is implicit
 * (one Store per property in MVP).
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    #[Url(as: 'date', except: '')]
    public string $date = '';

    /**
     * Per-row draft inputs, keyed by product_id.
     *
     * @var array<int, array{physical_qty: string, remark: string, note: string}>
     */
    public array $counts = [];

    /**
     * Per-request draft inputs for the approve panel, keyed by
     * request id: revised quantity + optional resolve note. Reject
     * uses the same note field.
     *
     * @var array<int, array{approved_qty: string, note: string}>
     */
    public array $requestDrafts = [];

    public function mount(): void
    {
        if ($this->date === '') {
            $this->date = Carbon::today()->toDateString();
        }
    }

    public function shiftDay(int $days): void
    {
        $this->date = Carbon::parse($this->date)->addDays($days)->toDateString();
        // Wipe draft inputs so they get re-hydrated for the new date's rows.
        $this->counts = [];
    }

    public function goToToday(): void
    {
        $this->date = Carbon::today()->toDateString();
        $this->counts = [];
    }

    public function saveCount(int $productId, SavePhysicalCount $save): void
    {
        $product = Product::query()->findOrFail($productId);
        $draft = $this->counts[$productId] ?? ['physical_qty' => '', 'remark' => '', 'note' => ''];

        if ($draft['physical_qty'] === '' || ! ctype_digit((string) $draft['physical_qty'])) {
            $this->addError('counts.'.$productId, 'Enter a whole number.');

            return;
        }

        $op = auth('web')->user();
        $operator = $op instanceof Operator ? $op : null;

        try {
            $save->handle(
                product:     $product,
                date:        Carbon::parse($this->date),
                physicalQty: (int) $draft['physical_qty'],
                remark:      $draft['remark'],
                note:        $draft['note'],
                operator:    $operator,
            );
        } catch (Throwable $e) {
            $this->addError('counts.'.$productId, $e->getMessage());

            return;
        }

        $this->dispatch('count-saved', productId: $productId);
    }

    public function approveRequest(int $requestId, ApproveStockRequest $approve): void
    {
        $request = StockRequest::query()->findOrFail($requestId);
        $op = auth('web')->user();
        if (! $op instanceof Operator) {
            return;
        }

        $draft = $this->requestDrafts[$requestId] ?? ['approved_qty' => '', 'note' => ''];
        $qty = $draft['approved_qty'] !== '' && ctype_digit((string) $draft['approved_qty'])
            ? (int) $draft['approved_qty']
            : (int) $request->quantity;

        try {
            $approve->handle(
                request:          $request,
                approvedBy:       $op,
                approvedQuantity: $qty,
                note:             $draft['note'] !== '' ? $draft['note'] : null,
            );
        } catch (Throwable $e) {
            $this->addError('requestDrafts.'.$requestId, $e->getMessage());

            return;
        }

        unset($this->requestDrafts[$requestId]);
        session()->flash('status', "Request #{$requestId} approved, issued {$qty} to the outlet.");
    }

    public function rejectRequest(int $requestId, RejectStockRequest $reject): void
    {
        $request = StockRequest::query()->findOrFail($requestId);
        $op = auth('web')->user();
        if (! $op instanceof Operator) {
            return;
        }

        $draft = $this->requestDrafts[$requestId] ?? ['approved_qty' => '', 'note' => ''];

        try {
            $reject->handle(
                request:    $request,
                rejectedBy: $op,
                note:       $draft['note'] !== '' ? $draft['note'] : null,
            );
        } catch (Throwable $e) {
            $this->addError('requestDrafts.'.$requestId, $e->getMessage());

            return;
        }

        unset($this->requestDrafts[$requestId]);
        session()->flash('status', "Request #{$requestId} rejected.");
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{
     *     id:int, occurred_at:\Illuminate\Support\Carbon,
     *     product_name:string, product_sku:?string,
     *     quantity:int, outlet_name:string, operator_name:?string,
     *     note:?string,
     * }>
     */
    private function consumptionHistory(int $storeId, \Illuminate\Support\Carbon $date): \Illuminate\Support\Collection
    {
        $storeProductIds = \App\Models\Product::query()
            ->where('store_id', $storeId)
            ->pluck('id');

        if ($storeProductIds->isEmpty()) {
            return collect();
        }

        // Store-side Issued rows for the picked date. delta < 0 filters
        // to "leaving the store", the paired outlet-side row is
        // delta > 0, referenced via source_id below.
        $rows = \App\Models\StockMovement::query()
            ->whereIn('product_id', $storeProductIds)
            ->where('reason', \App\Enums\StockMovementReason::Issued)
            ->where('delta', '<', 0)
            ->whereBetween('occurred_at', [
                $date->copy()->startOfDay(),
                $date->copy()->endOfDay(),
            ])
            ->with(['product:id,name,sku', 'operator:id,name'])
            ->orderByDesc('occurred_at')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Batch-load the paired outlet-side movements so we can join to
        // the receiving outlet. `source_id` on the store row points at
        // the outlet-side row (cross-linked by RecordStockIssuance).
        $pairIds = $rows->pluck('source_id')->filter()->unique()->all();
        $pairs = \App\Models\StockMovement::query()
            ->whereIn('id', $pairIds)
            ->with(['product:id,name,outlet_id', 'product.outlet:id,name'])
            ->get()
            ->keyBy('id');

        return $rows->map(function (\App\Models\StockMovement $r) use ($pairs) {
            $pair = $r->source_id !== null ? $pairs->get($r->source_id) : null;
            $outletName = $pair?->product?->outlet?->name ?? '-';

            return [
                'id'            => (int) $r->id,
                'occurred_at'   => $r->occurred_at,
                'product_name'  => (string) ($r->product?->name ?? '-'),
                'product_sku'   => $r->product?->sku,
                'quantity'      => abs((int) $r->delta),
                'outlet_name'   => (string) $outletName,
                'operator_name' => $r->operator?->name,
                'note'          => $r->note,
            ];
        });
    }

    public function render(DailyStockSheet $sheet): View
    {
        $store = Store::query()->orderBy('id')->first();

        $rows = $store !== null
            ? $sheet->build(Carbon::parse($this->date), storeId: $store->id)
            : collect();

        // Hydrate draft inputs from the DB for any product not yet
        // touched in this Livewire session, so refreshing the page or
        // shifting dates always shows the current stored value.
        foreach ($rows as $row) {
            $pid = $row['product_id'];
            if (! array_key_exists($pid, $this->counts)) {
                $this->counts[$pid] = [
                    'physical_qty' => $row['physical_qty'] !== null ? (string) $row['physical_qty'] : '',
                    'remark'       => $row['remark'] ?? '',
                    'note'         => $row['note'] ?? '',
                ];
            }
        }

        // Pending stock requests from every outlet, the keeper's inbox.
        $pendingRequests = StockRequest::query()
            ->pending()
            ->with(['outlet:id,name', 'product:id,name,outlet_id', 'requestedBy:id,name'])
            ->orderBy('created_at')
            ->get();

        // Hydrate approve-panel drafts (default approved qty = requested).
        foreach ($pendingRequests as $req) {
            if (! array_key_exists($req->id, $this->requestDrafts)) {
                $this->requestDrafts[$req->id] = [
                    'approved_qty' => (string) $req->quantity,
                    'note'         => '',
                ];
            }
        }

        // Rename the Carbon to `sheetDate` in the view because
        // Livewire exposes the public `$date` property (a string) as
        // `$date` in blade, which would shadow a same-named passed
        // variable and crash on ->format().
        // Movement history, which outlet got what on the selected
        // date. Same date drives the daily sheet, so scrubbing the
        // date navigator scopes both at once.
        $consumptionHistory = $store !== null
            ? $this->consumptionHistory($store->id, Carbon::parse($this->date))
            : collect();

        return view('livewire.dashboard.stores.index', [
            'store'              => $store,
            'rows'               => $rows,
            'sheetDate'          => Carbon::parse($this->date),
            'pendingRequests'    => $pendingRequests,
            'consumptionHistory' => $consumptionHistory,
        ]);
    }
}
