<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Pos\CompletePosSale;
use App\Actions\Pos\CreatePosSaleDraft;
use App\Enums\OperatorRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Outlet;
use App\Models\PosSale;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * JSON endpoints backing the POS terminal's offline queue.
 *
 * The Livewire terminal is online-first: the cart is built with server
 * round-trips. But when a Complete-sale request fails mid-flight
 * (network drops, kiosk WiFi flakes), JS captures the payload and
 * pushes it to localStorage. On reconnect (or on load), the queue
 * flushes here — each entry recreates the sale via the same actions
 * the live path uses.
 *
 * Two endpoints:
 *   - GET  /dashboard/pos/heartbeat  — cheap OK for connectivity probes
 *   - POST /dashboard/pos/queue      — recreate one queued sale
 *
 * A per-sale client_key is echoed back so JS can dedupe reliably.
 */
class PosOfflineQueueController extends Controller
{
    public function heartbeat(): Response
    {
        return response('ok', 200);
    }

    public function flush(
        Request $request,
        CreatePosSaleDraft $createDraft,
        CompletePosSale $complete,
    ): JsonResponse {
        $operator = $request->user('web');
        if ($operator === null) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        $data = $request->validate([
            'client_key' => ['required', 'string', 'max:64'],
            'outlet_id' => ['required', 'integer', 'exists:outlets,id'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'cart.*.quantity' => ['required', 'integer', 'min:1'],
            'cart.*.is_complimentary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:240'],
            'booking_ref' => ['nullable', 'string', 'max:32'],
            'tenders' => ['sometimes', 'array'],
            'tenders.*.method' => ['required_with:tenders.*.amount', 'string', 'max:20'],
            'tenders.*.amount' => ['required_with:tenders.*.method', 'integer', 'min:1'],
            'is_complimentary' => ['sometimes', 'boolean'],
            'complimentary_reason' => ['required_if:is_complimentary,true', 'nullable', 'string', 'max:200'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'client_completed_at' => ['nullable', 'date'],
        ]);

        // Complimentary sales don't need tenders — the sale total is
        // floored to ₦0 by CreatePosSaleDraft, and CompletePosSale
        // skips the tender loop entirely. Guard here so an empty
        // tenders payload only reaches the flow when it's supposed to.
        $isComplimentary = (bool) ($data['is_complimentary'] ?? false);
        if (! $isComplimentary && empty($data['tenders'])) {
            return response()->json(['ok' => false, 'error' => 'tenders_required'], 422);
        }

        $outlet = Outlet::query()->find((int) $data['outlet_id']);
        if (! $outlet instanceof Outlet) {
            return response()->json(['ok' => false, 'error' => 'outlet_missing'], 422);
        }

        // Non-managers must actually be assigned to the outlet — mirrors
        // the check in Terminal::mount so an offline replay can't grant
        // access it wouldn't have had online.
        if ($operator->role !== OperatorRole::Manager) {
            $assigned = $operator->outlets()->where('outlets.id', $outlet->id)->exists();
            if (! $assigned) {
                return response()->json(['ok' => false, 'error' => 'not_assigned_to_outlet'], 403);
            }
        }

        // Idempotency — if the server has already written this client_key,
        // return the existing sale. Protects against retries after a
        // network drop that killed the response but not the write.
        $tenantId = (int) ($outlet->tenant_id ?? Tenant::current()?->id);
        $existing = PosSale::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('client_key', $data['client_key'])
            ->first();
        if ($existing !== null) {
            return response()->json([
                'ok' => true,
                'dedup' => true,
                'client_key' => $data['client_key'],
                'sale_id' => $existing->id,
                'reference' => $existing->reference,
                'total' => (int) $existing->total,
            ]);
        }

        // A room / wallet tender needs a folio anchor — either a
        // booking OR a credit line. Wallet still requires a booking
        // (it spends the customer's wallet balance); room can go
        // either way. Front-end sends `credit_line_id` when the
        // operator picked a house-account tab; that wins over the
        // booking ref, but both cannot be sent for the same sale.
        $bookingId = null;
        $creditLineId = null;
        $tendersColl = collect((array) ($data['tenders'] ?? []));
        $hasRoom = $tendersColl->contains(fn ($t) => (is_array($t) ? ($t['method'] ?? '') : '') === 'room');
        $hasWallet = $tendersColl->contains(fn ($t) => (is_array($t) ? ($t['method'] ?? '') : '') === 'wallet');
        $rawCreditLineId = isset($data['credit_line_id']) && $data['credit_line_id'] !== ''
            ? (int) $data['credit_line_id'] : null;

        if ($rawCreditLineId !== null) {
            $line = \App\Models\CreditLine::query()->find($rawCreditLineId);
            if ($line === null) {
                return response()->json(['ok' => false, 'error' => 'credit_line_not_found'], 422);
            }
            if (! $line->status->acceptsCharges()) {
                return response()->json(['ok' => false, 'error' => 'credit_line_wrong_status', 'status' => $line->status->value], 422);
            }
            $creditLineId = $line->id;
        }

        // Wallet always needs a booking anchor. Room needs one only
        // if no credit line was picked.
        if ($hasWallet || ($hasRoom && $creditLineId === null)) {
            $ref = trim((string) ($data['booking_ref'] ?? ''));
            if ($ref === '') {
                return response()->json(['ok' => false, 'error' => 'booking_ref_required'], 422);
            }
            $booking = Booking::query()->where('booking_ref', $ref)->first();
            if ($booking === null) {
                return response()->json(['ok' => false, 'error' => 'booking_not_found', 'ref' => $ref], 422);
            }
            if (! in_array($booking->booking_status->value, ['confirmed', 'checked_in', 'on_hold'], true)) {
                return response()->json(['ok' => false, 'error' => 'booking_wrong_status', 'ref' => $ref], 422);
            }
            $bookingId = $booking->id;
        }

        try {
            $sale = DB::transaction(function () use ($createDraft, $complete, $outlet, $operator, $data, $bookingId, $creditLineId, $tenantId): PosSale {
                // Race-safe: another concurrent flush of the same
                // client_key would try to draft a second sale and
                // trip the (tenant_id, client_key) unique index. Lock
                // the tenant row-space with a re-check inside the txn.
                $racedIn = PosSale::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenantId)
                    ->where('client_key', $data['client_key'])
                    ->lockForUpdate()
                    ->first();
                if ($racedIn !== null) {
                    return $racedIn;
                }

                $draft = $createDraft->handle(
                    outlet: $outlet,
                    operator: $operator,
                    cart: array_values(array_map(fn ($l) => [
                        'product_id' => (int) $l['product_id'],
                        'quantity' => (int) $l['quantity'],
                        'is_complimentary' => (bool) ($l['is_complimentary'] ?? false),
                    ], $data['cart'])),
                    bookingId: $bookingId,
                    customerId: isset($data['customer_id']) && $data['customer_id'] !== ''
                        ? (int) $data['customer_id'] : null,
                    notes: ($data['notes'] ?? '') !== '' ? $data['notes'] : null,
                    clientKey: $data['client_key'],
                    creditLineId: $creditLineId,
                    saleIsComplimentary: (bool) ($data['is_complimentary'] ?? false),
                    complimentaryReason: $data['complimentary_reason'] ?? null,
                    customerPhoneSnapshot: $data['customer_phone'] ?? null,
                    customerNameSnapshot: $data['customer_name'] ?? null,
                );

                return $complete->handleTenders(
                    sale: $draft,
                    operator: $operator,
                    tenders: array_values(array_map(fn ($t) => [
                        'method' => (string) $t['method'],
                        'amount' => (int) $t['amount'],
                    ], (array) ($data['tenders'] ?? []))),
                );
            });
        } catch (Throwable $e) {
            Log::warning('POS offline flush failed', [
                'client_key' => $data['client_key'],
                'operator' => $operator->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'server_error',
                'message' => $e->getMessage(),
                'client_key' => $data['client_key'],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'client_key' => $data['client_key'],
            'sale_id' => $sale->id,
            'reference' => $sale->reference,
            'total' => (int) $sale->total,
        ]);
    }
}
