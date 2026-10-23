<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile\Staff;

use App\Actions\Inventory\CreateStockRequest;
use App\Enums\OutletType;
use App\Enums\StockRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Property;
use App\Models\StockRequest;
use App\Support\Housekeeping\ChecklistPictures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Ask the store" for housekeepers and porters. Requests land on the
 * property's Housekeeping outlet (the same one cleaning deducts from)
 * through CreateStockRequest, one request per product, so managers
 * approve them on the web exactly as before. Staff don't need to be
 * attached to that outlet: it is their property's supply room.
 */
class SuppliesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Operator $me */
        $me = $request->user();
        $outlet = $this->outlet($me);

        return response()->json(['data' => [
            'outlet' => $outlet === null ? null : ['id' => $outlet->id, 'name' => $outlet->name],
            'products' => $outlet === null ? [] : $this->products($outlet)->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => null,
                'icon' => ChecklistPictures::icon($p->name),
            ])->values()->all(),
            'requests' => $this->recent($me),
        ]]);
    }

    public function store(Request $request, CreateStockRequest $create): JsonResponse
    {
        /** @var Operator $me */
        $me = $request->user();
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $outlet = $this->outlet($me);
        if ($outlet === null) {
            return response()->json([
                'error' => 'no_store',
                'detail' => "The hotel hasn't set up a housekeeping store yet. Ask a manager.",
            ], 422);
        }

        $allowed = $this->products($outlet)->keyBy('id');
        $lines = [];
        foreach (collect($data['lines'])->groupBy('product_id') as $id => $group) {
            $product = $allowed->get((int) $id);
            if ($product === null) {
                return response()->json(['error' => 'unknown_product', 'detail' => 'One of those items is no longer in the store.'], 422);
            }
            $lines[] = ['product' => $product, 'qty' => (int) $group->sum('qty')];
        }

        try {
            DB::transaction(function () use ($lines, $outlet, $me, $data, $create): void {
                foreach ($lines as $line) {
                    $create->handle($line['product'], $outlet, $line['qty'], $me, $data['note'] ?? null);
                }
            });
        } catch (Throwable $e) {
            return response()->json(['error' => 'request_failed', 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'ok', 'requests' => $this->recent($me)]);
    }

    /**
     * The first active Housekeeping outlet on the operator's property
     * (their first branch, else the hotel's first property).
     */
    private function outlet(Operator $me): ?Outlet
    {
        $propertyId = $me->accessibleBranchIds()[0] ?? Property::query()->orderBy('id')->value('id');
        if ($propertyId === null) {
            return null;
        }

        return Outlet::query()
            ->where('type', OutletType::Housekeeping)
            ->where('property_id', $propertyId)
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Store products staff can ask for. When the housekeeping outlet
     * already stocks some, only those (matched by name) are offered;
     * a fresh outlet offers the whole store.
     *
     * @return Collection<int, Product>
     */
    private function products(Outlet $outlet): Collection
    {
        $store = Product::query()
            ->whereNotNull('store_id')
            ->where('active', true)
            ->where('track_stock', true)
            ->orderBy('name')
            ->limit(200)
            ->get();

        $stocked = Product::query()
            ->where('outlet_id', $outlet->id)
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->flip();

        if ($stocked->isEmpty()) {
            return $store;
        }

        $matched = $store->filter(fn (Product $p) => $stocked->has(mb_strtolower(trim($p->name))))->values();

        return $matched->isEmpty() ? $store : $matched;
    }

    /** @return array<int, array<string, mixed>> */
    private function recent(Operator $me): array
    {
        return StockRequest::query()
            ->with(['product:id,name', 'resolvedBy:id,name'])
            ->where('requested_by_operator_id', $me->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (StockRequest $r) => [
                'id' => $r->id,
                'summary' => ($r->product?->name ?? 'Item').', '.$r->quantity,
                'created_at' => StaffPayload::iso($r->created_at),
                'status' => match ($r->status) {
                    StockRequestStatus::Pending => 'waiting',
                    StockRequestStatus::Approved => 'approved',
                    StockRequestStatus::Rejected => 'declined',
                    StockRequestStatus::Cancelled => 'cancelled',
                },
                'by' => $r->resolvedBy?->firstName(),
            ])
            ->values()
            ->all();
    }
}
