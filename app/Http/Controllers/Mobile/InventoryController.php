<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Actions\Inventory\ApproveStockRequest;
use App\Actions\Inventory\CreateStockRequest;
use App\Actions\Inventory\RejectStockRequest;
use App\Enums\OperatorRole;
use App\Enums\StockRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\ProductResource;
use App\Http\Resources\Mobile\StockRequestResource;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Inventory surface for the phone. Two workflows:
 *
 * - List the store catalogue (source products) so an outlet operator
 *   knows what's available to request. Read-through cache lands on
 *   the mobile side; this endpoint is a straight paginated list.
 * - File a stock request (outlet → central store). Delegates to
 *   CreateStockRequest so the same throw-on-invalid rules the web
 *   uses fire here too. Approval / receive-stock stays manager-only
 *   on web for now — mobile just consumes the request lifecycle.
 * - List my recent requests (mine + pending) for a small tracker.
 */
class InventoryController extends Controller
{
    /**
     * List store products (products where store_id is set). These are
     * the source SKUs that stock requests target — outlet products
     * get provisioned at approval time from the same store product.
     */
    public function storeProducts(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $query = Product::query()
            ->whereNotNull('store_id')
            ->where('active', true)
            ->where('track_stock', true);

        if (! empty($data['q'])) {
            $needle = '%'.mb_strtolower($data['q']).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(sku) LIKE ?', [$needle]);
            });
        }

        return ProductResource::collection($query->orderBy('name')->limit(200)->get());
    }

    public function createRequest(Request $request, CreateStockRequest $action): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $outlet = Outlet::query()->findOrFail($data['outlet_id']);
        $this->guardOutletAccess($this->operator($request), $outlet);

        $product = Product::query()->findOrFail($data['product_id']);

        try {
            $stockRequest = $action->handle(
                storeProduct: $product,
                outlet: $outlet,
                quantity: $data['quantity'],
                requestedBy: $this->operator($request),
                note: $data['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'stock_request_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        $stockRequest->load(['outlet', 'product']);

        return response()->json([
            'status' => 'ok',
            'stock_request' => new StockRequestResource($stockRequest),
        ]);
    }

    /**
     * filter=mine     — requests raised by the current operator
     * filter=pending  — every Pending request (visible to manager
     *                   AND to any operator who works the target outlet)
     * filter=all      — everything (manager-only)
     */
    public function requestsIndex(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in(['mine', 'pending', 'all'])],
        ]);
        $filter = $data['filter'] ?? 'mine';

        $operator = $this->operator($request);
        $query = StockRequest::query()
            ->with(['outlet:id,name', 'product:id,name'])
            ->orderByDesc('created_at')
            ->limit(50);

        if ($filter === 'mine') {
            $query->where('requested_by_operator_id', $operator->id);
        } elseif ($filter === 'pending') {
            $query->where('status', StockRequestStatus::Pending->value);
            if ($operator->role !== OperatorRole::Manager) {
                $outletIds = $operator->outlets()->pluck('outlets.id');
                $query->whereIn('outlet_id', $outletIds);
            }
        } elseif ($filter === 'all') {
            if ($operator->role !== OperatorRole::Manager) {
                abort(response()->json(['error' => 'forbidden'], 403));
            }
        }

        return StockRequestResource::collection($query->get());
    }

    /**
     * Manager-only. Body: {approved_quantity?, note?}. When
     * approved_quantity is omitted the action uses the originally
     * requested quantity. Delegates to ApproveStockRequest so the
     * ledger update + outlet-product provisioning happens the
     * same way as web.
     */
    public function approveRequest(Request $request, int $id, ApproveStockRequest $action): JsonResponse
    {
        $operator = $this->operator($request);
        $this->guardManager($operator);

        $data = $request->validate([
            'approved_quantity' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $stockRequest = StockRequest::query()->findOrFail($id);

        try {
            $fresh = $action->handle(
                request: $stockRequest,
                approvedBy: $operator,
                approvedQuantity: $data['approved_quantity'] ?? null,
                note: $data['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'approve_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        $fresh->load(['outlet', 'product']);

        return response()->json([
            'status' => 'ok',
            'stock_request' => new StockRequestResource($fresh),
        ]);
    }

    /**
     * Manager-only. Body: {note} — reason is required so the
     * requester sees why. Delegates to RejectStockRequest.
     */
    public function rejectRequest(Request $request, int $id, RejectStockRequest $action): JsonResponse
    {
        $operator = $this->operator($request);
        $this->guardManager($operator);

        $data = $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $stockRequest = StockRequest::query()->findOrFail($id);

        try {
            $fresh = $action->handle(
                request: $stockRequest,
                rejectedBy: $operator,
                note: $data['note'],
            );
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'reject_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        $fresh->load(['outlet', 'product']);

        return response()->json([
            'status' => 'ok',
            'stock_request' => new StockRequestResource($fresh),
        ]);
    }

    // ── helpers ──

    private function guardManager(Operator $operator): void
    {
        if ($operator->role !== OperatorRole::Manager) {
            abort(response()->json(['error' => 'manager_only'], 403));
        }
    }

    private function operator(Request $request): Operator
    {
        $op = $request->user();
        if (! $op instanceof Operator) {
            abort(401);
        }

        return $op;
    }

    private function guardOutletAccess(Operator $operator, Outlet $outlet): void
    {
        if ($operator->role === OperatorRole::Manager) {
            return;
        }
        $granted = $outlet->operators()->where('operators.id', $operator->id)->exists();
        if (! $granted) {
            abort(response()->json(['error' => 'outlet_forbidden'], 403));
        }
    }
}
