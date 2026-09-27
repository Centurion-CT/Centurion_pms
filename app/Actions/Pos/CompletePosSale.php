<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PosSaleStatus;
use App\Enums\StockMovementReason;
use App\Models\BookingCharge;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Product;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finalise a draft sale:
 *
 *   1. Decrement stock (via {@see RecordStockMovement}) for every line
 *      whose product tracks stock. Composite products (with a recipe)
 *      decrement their component ingredients instead of themselves.
 *   2. Create one `Payment` row per tender (type=PosSale, status=Succeeded)
 *      so Finance / payouts pick each split up individually.
 *   3. If any tender is "room", create a matching `BookingCharge` for
 *      that tender's amount so the guest folio picks it up.
 *
 * Split-tender: pass a list to {@see handleTenders()} — e.g.
 * ₦2,000 cash + ₦3,000 charge-to-room on the same sale. The single-method
 * {@see handle()} wrapper stays for callers that don't split.
 *
 * Idempotent: calling twice on a Completed sale is a no-op that returns
 * the same sale — protects against network-retry double-decrement.
 */
class CompletePosSale
{
    public function __construct(
        private readonly RecordStockMovement $movements,
        private readonly WalletService $wallet,
    ) {}

    /**
     * Single-tender complete — kept for backward compatibility. Delegates
     * to {@see handleTenders()} with a one-element list.
     *
     * @param  'cash'|'transfer'|'card'|'pos'|'room'|'wallet'|'other'  $method
     */
    public function handle(
        PosSale $sale,
        Operator $operator,
        string $method,
        ?Carbon $paidAt = null,
        ?string $referenceNote = null,
    ): PosSale {
        return $this->handleTenders(
            sale: $sale,
            operator: $operator,
            tenders: [[
                'method' => $method,
                'amount' => (int) $sale->total,
                'reference_note' => $referenceNote,
            ]],
            paidAt: $paidAt,
        );
    }

    /**
     * Multi-tender complete. Amounts sum to sale.total; only one "room"
     * tender is allowed per sale; a "room" tender requires booking_id
     * on the sale.
     *
     * @param  list<array{method: string, amount: int, reference_note?: ?string}>  $tenders
     */
    public function handleTenders(
        PosSale $sale,
        Operator $operator,
        array $tenders,
        ?Carbon $paidAt = null,
    ): PosSale {
        if ($sale->status === PosSaleStatus::Completed) {
            return $sale->fresh(['lines', 'payments']);
        }
        if ($sale->status === PosSaleStatus::Voided) {
            throw new RuntimeException("Sale {$sale->reference} is voided; cannot complete.");
        }

        // Complimentary path: skip tender validation, skip Payment /
        // BookingCharge creation, but still decrement stock so the
        // outlet's stock book stays honest (a comp'd drink still leaves
        // the fridge). CreatePosSaleDraft has already floored the sale
        // total to 0 and flagged every line as complimentary in this
        // path, so all the downstream money math naturally short-
        // circuits.
        if ($sale->is_complimentary) {
            $paidAt = $paidAt ?? Carbon::now();

            return DB::transaction(function () use ($sale, $operator, $paidAt): PosSale {
                $this->decrementStockForLines($sale, $operator, $paidAt);

                $sale->forceFill([
                    'status' => PosSaleStatus::Completed,
                    'sold_at' => $paidAt,
                ])->save();

                activity('pos_sale')
                    ->causedBy($operator)
                    ->performedOn($sale)
                    ->event('pos_sale_completed')
                    ->withProperties([
                        'reference' => $sale->reference,
                        'outlet' => $sale->outlet->name,
                        'is_complimentary' => true,
                        'complimentary_reason' => $sale->complimentary_reason,
                    ])
                    ->log("Operator {$operator->name} completed complimentary sale {$sale->reference} (reason: {$sale->complimentary_reason}).");

                return $sale->fresh(['lines', 'payments']);
            });
        }

        if ((int) $sale->total <= 0) {
            throw new InvalidArgumentException("Sale {$sale->reference} has no billable lines.");
        }
        if ($tenders === []) {
            throw new InvalidArgumentException('At least one tender is required to complete a sale.');
        }

        $sumTender = 0;
        $roomTender = 0;
        $walletTender = 0;
        $walletAmount = 0;
        foreach ($tenders as $t) {
            $amt = (int) ($t['amount'] ?? 0);
            if ($amt <= 0) {
                throw new InvalidArgumentException('Every tender must have a positive amount.');
            }
            $sumTender += $amt;
            if (($t['method'] ?? '') === 'room') {
                $roomTender++;
            }
            if (($t['method'] ?? '') === 'wallet') {
                $walletTender++;
                $walletAmount += $amt;
            }
        }
        if ($sumTender !== (int) $sale->total) {
            throw new InvalidArgumentException(
                "Tender total ₦{$sumTender} doesn't match sale total ₦{$sale->total}.",
            );
        }
        if ($roomTender > 1) {
            throw new InvalidArgumentException('Only one charge-to-room tender is allowed per sale.');
        }
        if ($roomTender === 1 && $sale->booking_id === null && $sale->credit_line_id === null) {
            throw new InvalidArgumentException(
                'Charge-to-room needs a booking OR a credit line on the sale.',
            );
        }
        // Belt-and-braces: the credit line has to accept new charges.
        if ($roomTender === 1 && $sale->credit_line_id !== null) {
            $line = \App\Models\CreditLine::query()->find($sale->credit_line_id);
            if ($line === null || ! $line->status->acceptsCharges()) {
                throw new InvalidArgumentException(sprintf(
                    'Credit line %s can\'t accept new charges (currently %s).',
                    $line?->name ?? '(missing)',
                    $line?->status->label() ?? 'unknown',
                ));
            }
        }
        if ($walletTender > 1) {
            throw new InvalidArgumentException('Only one wallet tender is allowed per sale.');
        }
        if ($walletTender === 1) {
            // Wallet tender needs to resolve to a Customer. Prefer the
            // sale's own customer_id, fall back to the linked booking's
            // customer (charge-to-room + wallet split for the same guest).
            $customer = $this->resolveCustomerForWallet($sale);
            if (! $customer instanceof Customer) {
                throw new InvalidArgumentException('Wallet tender needs a customer or a customer-linked booking on the sale.');
            }
            if ($customer->walletBalance() < $walletAmount) {
                throw new RuntimeException(sprintf(
                    'Wallet holds ₦%s but wallet tender is ₦%s.',
                    number_format($customer->walletBalance()),
                    number_format($walletAmount),
                ));
            }
        }

        $paidAt = $paidAt ?? Carbon::now();

        return DB::transaction(function () use ($sale, $operator, $tenders, $paidAt): PosSale {
            // 1. Decrement stock per line — composite → components,
            //    otherwise the product itself. Non-tracked skipped.
            $this->decrementStockForLines($sale, $operator, $paidAt);

            // 2. Ledger each tender. One Payment row apiece so cash-drawer
            //    reconciliation and Finance reporting stay granular.
            //    Wallet tenders additionally spend from the guest wallet
            //    inside the same transaction so the ledger stays consistent.
            foreach ($tenders as $t) {
                $method = (string) $t['method'];
                $amount = (int) $t['amount'];
                $refPrefix = $method === 'wallet' ? 'wallet-' : 'pos-';

                $payment = Payment::query()->create([
                    'tenant_id' => $sale->tenant_id,
                    'booking_id' => $sale->booking_id,
                    'invoice_id' => null,
                    'pos_sale_id' => $sale->id,
                    'paystack_reference' => $refPrefix.Str::lower(Str::ulid()->toBase32()),
                    'amount' => $amount,
                    'type' => PaymentType::PosSale,
                    'method' => $method,
                    'reference_note' => $t['reference_note'] ?? null,
                    'status' => PaymentStatus::Succeeded,
                    'paid_at' => $paidAt,
                ]);

                if ($method === 'wallet') {
                    $customer = $this->resolveCustomerForWallet($sale);
                    if (! $customer instanceof Customer) {
                        throw new InvalidArgumentException('Wallet tender lost its customer inside the transaction.');
                    }
                    $this->wallet->spend(
                        customer: $customer,
                        amount: $amount,
                        description: sprintf('POS purchase %s (₦%s)', $sale->reference, number_format($amount)),
                        meta: ['source' => 'pay_pos_sale', 'sale_reference' => $sale->reference],
                        links: [
                            'pos_sale_id' => $sale->id,
                            'booking_id' => $sale->booking_id,
                            'payment_id' => $payment->id,
                        ],
                        causer: $operator,
                    );
                }
            }

            // 3. Charge-to-room path — one charge row per room tender.
            //    Routes to a CreditLineCharge when the sale carries a
            //    credit_line_id (house-account tabs); falls back to
            //    the classic BookingCharge on the guest folio.
            foreach ($tenders as $t) {
                if (($t['method'] ?? '') !== 'room') {
                    continue;
                }
                $type = $sale->outlet->type->bookingChargeType();
                $description = "POS · {$sale->outlet->name} · {$sale->reference}";
                $amount = (int) $t['amount'];

                if ($sale->credit_line_id !== null) {
                    \App\Models\CreditLineCharge::query()->create([
                        'tenant_id' => $sale->tenant_id,
                        'credit_line_id' => $sale->credit_line_id,
                        'room_id' => null,   // POS sales aren't necessarily tied to a specific room
                        'added_by_operator_id' => $operator->id,
                        'type' => $type->value,
                        'description' => $description,
                        'amount' => $amount,
                    ]);
                } elseif ($sale->booking_id !== null) {
                    BookingCharge::query()->create([
                        'tenant_id' => $sale->tenant_id,
                        'booking_id' => $sale->booking_id,
                        'created_by_operator_id' => $operator->id,
                        'type' => $type,
                        'description' => $description,
                        'amount' => $amount,
                        'settled' => false,
                    ]);
                }
            }

            $sale->forceFill([
                'status' => PosSaleStatus::Completed,
                'sold_at' => $paidAt,
            ])->save();

            $methods = implode('+', array_map(fn ($t) => (string) $t['method'], $tenders));

            activity('pos_sale')
                ->causedBy($operator)
                ->performedOn($sale)
                ->event('pos_sale_completed')
                ->withProperties([
                    'reference' => $sale->reference,
                    'outlet' => $sale->outlet->name,
                    'total' => $sale->total,
                    'tenders' => $tenders,
                    'booking_id' => $sale->booking_id,
                ])
                ->log("Operator {$operator->name} completed sale {$sale->reference} via {$methods}.");

            return $sale->fresh(['lines', 'payments']);
        });
    }

    /**
     * Decrement stock for every line in the sale (composite → components,
     * simple → the product itself, non-tracked skipped). Broken out of
     * the tendered path so the complimentary path can call it without
     * copy-pasting the loop.
     */
    private function decrementStockForLines(PosSale $sale, Operator $operator, Carbon $paidAt): void
    {
        foreach ($sale->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }
            $product = Product::query()->with('recipeItems.component')->find($line->product_id);
            if ($product === null) {
                continue;
            }

            $recipe = $product->recipeItems;
            if ($recipe->isNotEmpty()) {
                foreach ($recipe as $item) {
                    $component = $item->component;
                    if ($component === null || ! $component->track_stock) {
                        continue;
                    }
                    $this->movements->handle(
                        product: $component,
                        delta: -1 * (int) $line->quantity * (int) $item->quantity,
                        reason: StockMovementReason::Sold,
                        operator: $operator,
                        source: $sale,
                        note: ($line->is_complimentary ? 'Comp · ' : '')
                            ."Recipe · {$product->name} · sold via {$sale->reference}",
                        occurredAt: $paidAt,
                    );
                }

                continue;
            }

            if (! $product->track_stock) {
                continue;
            }
            $this->movements->handle(
                product: $product,
                delta: -1 * (int) $line->quantity,
                reason: StockMovementReason::Sold,
                operator: $operator,
                source: $sale,
                note: ($line->is_complimentary ? 'Comp · ' : '')."Sold via {$sale->reference}",
                occurredAt: $paidAt,
            );
        }
    }

    /**
     * Wallet tender lookup: prefer the sale's own customer_id, fall
     * back to the booking's linked customer so a split of "half wallet
     * + half charge-to-room" for the same guest works.
     */
    private function resolveCustomerForWallet(PosSale $sale): ?Customer
    {
        if ($sale->customer_id !== null) {
            return $sale->customer;
        }
        if ($sale->booking_id !== null && $sale->booking?->customer instanceof Customer) {
            return $sale->booking->customer;
        }

        return null;
    }
}
