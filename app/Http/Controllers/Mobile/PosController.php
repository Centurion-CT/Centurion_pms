<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OperatorRole;
use App\Enums\PosSaleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Mobile\OutletResource;
use App\Http\Resources\Mobile\PosSaleResource;
use App\Http\Resources\Mobile\ProductResource;
use App\Models\Booking;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * POS surface for the mobile app.
 *
 * Design notes:
 * - Sale creation is one-shot: cart + tenders in a single POST. The
 *   client owns the cart until submit, which matches how a phone
 *   POS terminal actually behaves (no server-side draft persistence
 *   between taps). The action layer still uses CreatePosSaleDraft
 *   + CompletePosSale internally so the ledger flow is identical to
 *   the web kiosk.
 * - Idempotency comes from two layers: MobileIdempotency middleware
 *   (via Idempotency-Key header, 24h response cache), AND the sale's
 *   own client_key (a longer-lived guard against replaying an old
 *   receipt after the middleware cache TTL). Same key = same
 *   PosSale row on retry.
 * - Operator visibility follows the same rule as the web kiosk:
 *   managers see every outlet; anyone else sees only outlets in the
 *   outlet_operators pivot.
 */
class PosController extends Controller
{
    public function outlets(Request $request): AnonymousResourceCollection
    {
        $operator = $this->operator($request);
        $query = Outlet::query()->where('active', true);
        if ($operator->role !== OperatorRole::Manager) {
            $query->whereHas('operators', fn ($q) => $q->where('operators.id', $operator->id));
        }

        return OutletResource::collection($query->orderBy('name')->get());
    }

    public function products(Request $request, int $outlet): AnonymousResourceCollection
    {
        $out = Outlet::query()->findOrFail($outlet);
        $this->guardOutletAccess($this->operator($request), $out);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'barcode' => ['nullable', 'string', 'max:64'],
        ]);

        $query = Product::query()
            ->where('outlet_id', $outlet)
            ->where('active', true);

        if (! empty($data['barcode'])) {
            $query->where('barcode', $data['barcode']);
        } elseif (! empty($data['q'])) {
            $needle = '%'.mb_strtolower($data['q']).'%';
            $query->where(function ($q) use ($needle, $data) {
                $q->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(sku) LIKE ?', [$needle])
                    ->orWhere('barcode', $data['q']);
            });
        }

        return ProductResource::collection($query->orderBy('name')->limit(200)->get());
    }

    /**
     * One-shot: build the draft from the cart, complete it with the
     * tenders, return the receipt. Retry with the same client_key
     * (or Idempotency-Key) is safe — CreatePosSaleDraft short-
     * circuits on the client_key so we never persist two rows.
     */
    public function createSale(Request $request, int $outlet, CreatePosSaleDraft $draft, CompletePosSale $complete): JsonResponse
    {
        $data = $request->validate([
            'client_key' => ['required', 'string', 'min:8', 'max:64'],
            'booking_ref' => ['nullable', 'string', 'max:32'],
            'customer_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.product_id' => ['nullable', 'integer'],
            'cart.*.name' => ['nullable', 'string', 'max:160'],
            'cart.*.quantity' => ['required', 'integer', 'min:1'],
            'cart.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.method' => ['required', 'string'],
            'tenders.*.amount' => ['required', 'integer', 'min:1'],
            'tenders.*.reference_note' => ['nullable', 'string', 'max:200'],
        ]);

        $out = Outlet::query()->findOrFail($outlet);
        $operator = $this->operator($request);
        $this->guardOutletAccess($operator, $out);

        // Resolve booking_ref → booking_id if the client is
        // charging-to-room. The Flutter cart flow captures the ref
        // (human-typeable, stable across renames) but the action
        // wants the ID.
        $bookingId = null;
        if (! empty($data['booking_ref'])) {
            $bookingId = Booking::query()->where('booking_ref', $data['booking_ref'])->value('id');
            if ($bookingId === null) {
                return response()->json([
                    'error' => 'booking_not_found',
                    'detail' => "Booking {$data['booking_ref']} isn't on this property.",
                ], 422);
            }
        }

        try {
            $sale = DB::transaction(function () use ($out, $operator, $data, $bookingId, $draft, $complete) {
                // Short-circuit on duplicate client_key: if a sale
                // with this key already exists on this outlet, return
                // it directly (idempotent replay).
                $existing = PosSale::query()
                    ->where('outlet_id', $out->id)
                    ->where('client_key', $data['client_key'])
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                $sale = $draft->handle(
                    outlet: $out,
                    operator: $operator,
                    cart: $data['cart'],
                    bookingId: $bookingId,
                    customerId: $data['customer_id'] ?? null,
                    notes: $data['notes'] ?? null,
                    clientKey: $data['client_key'],
                );

                if ($sale->status === PosSaleStatus::Completed) {
                    return $sale;
                }

                return $complete->handleTenders(
                    sale: $sale,
                    operator: $operator,
                    tenders: $data['tenders'],
                );
            });
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'sale_failed',
                'detail' => $e->getMessage(),
            ], 422);
        }

        $sale->load(['lines', 'payments']);

        return response()->json([
            'status' => 'ok',
            'sale' => new PosSaleResource($sale),
        ]);
    }

    public function showSale(Request $request, string $reference): PosSaleResource
    {
        $sale = PosSale::query()
            ->with(['lines', 'payments'])
            ->where('reference', $reference)
            ->firstOrFail();

        $out = Outlet::query()->findOrFail($sale->outlet_id);
        $this->guardOutletAccess($this->operator($request), $out);

        return new PosSaleResource($sale);
    }

    // ── helpers ──

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
