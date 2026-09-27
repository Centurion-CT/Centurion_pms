<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\OperatorRole;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * JSON feed of an outlet's active products for the client-side POS
 * cache. Called by resources/js/pos-db.js on terminal load and via the
 * service worker's stale-while-revalidate strategy so the phone can
 * browse + barcode-scan offline.
 *
 * Access rules mirror the terminal:
 *  - Managers see every outlet's products.
 *  - PosStaff must be assigned to the outlet — otherwise 404 (mirrors
 *    Terminal::mount so a phone can't sniff around by outlet id).
 *
 * Response is cacheable by the SW; the client uses `updated_at` for
 * invalidation, so no ETag / If-Modified-Since round-trip here.
 */
class PosProductsJsonController extends Controller
{
    public function __invoke(Request $request, Outlet $outlet): JsonResponse
    {
        $operator = $request->user('web');
        if ($operator === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if ($operator->role !== OperatorRole::Manager) {
            $assigned = $operator->outlets()->where('outlets.id', $outlet->id)->exists();
            if (! $assigned) {
                throw new NotFoundHttpException;
            }
        }

        $products = $outlet->products()
            ->where('active', true)
            ->orderBy('name')
            ->get([
                'id', 'outlet_id', 'sku', 'barcode', 'name',
                'price', 'track_stock', 'quantity_on_hand', 'updated_at',
            ])
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'outlet_id' => (int) $p->outlet_id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'price' => (int) $p->price,
                'track_stock' => (bool) $p->track_stock,
                'quantity_on_hand' => (int) $p->quantity_on_hand,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'outlet' => [
                'id' => (int) $outlet->id,
                'name' => $outlet->name,
                'tax_rate' => (float) $outlet->tax_rate,
            ],
            'products' => $products,
            'synced_at' => now()->toIso8601String(),
        ]);
    }
}
