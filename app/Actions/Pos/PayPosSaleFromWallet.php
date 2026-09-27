<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\PosSaleStatus;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\PosSale;
use App\Services\Customers\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Settle a POS sale by spending from the guest's wallet. Only valid
 * when the sale has a linked customer AND the wallet holds enough for
 * the full total (partial POS payments would need a split-tender UI
 * that doesn't exist yet).
 */
class PayPosSaleFromWallet
{
    public function __construct(private readonly WalletService $wallet) {}

    public function handle(PosSale $sale, Operator $operator): Payment
    {
        $customer = $sale->customer;
        if (! $customer instanceof Customer) {
            throw new InvalidArgumentException('POS sale has no linked customer — cannot charge to wallet.');
        }

        $total = (int) $sale->total;
        if ($total <= 0) {
            throw new InvalidArgumentException('Cannot pay a zero-total sale from wallet.');
        }

        if ($customer->walletBalance() < $total) {
            throw new RuntimeException(sprintf(
                'Wallet holds ₦%s but sale total is ₦%s.',
                number_format($customer->walletBalance()),
                number_format($total),
            ));
        }

        return DB::transaction(function () use ($sale, $customer, $operator, $total): Payment {
            $payment = Payment::query()->create([
                'tenant_id' => $sale->tenant_id,
                'booking_id' => $sale->booking_id,
                'invoice_id' => null,
                'pos_sale_id' => $sale->id,
                'paystack_reference' => 'wallet-'.Str::ulid()->toBase32(),
                'amount' => $total,
                'type' => PaymentType::PosSale,
                'method' => 'wallet',
                'reference_note' => sprintf('Wallet tender on sale %s', $sale->reference),
                'status' => PaymentStatus::Succeeded,
                'paid_at' => Carbon::now(),
            ]);

            $this->wallet->spend(
                customer: $customer,
                amount: $total,
                description: sprintf('POS purchase %s (₦%s)', $sale->reference, number_format($total)),
                meta: ['source' => 'pay_pos_sale', 'sale_reference' => $sale->reference],
                links: ['pos_sale_id' => $sale->id, 'booking_id' => $sale->booking_id, 'payment_id' => $payment->id],
                causer: $operator,
            );

            // Complete the sale if it was still in Draft — mirrors what
            // the regular cash-tender flow does.
            if ($sale->status === PosSaleStatus::Draft) {
                $sale->forceFill([
                    'status' => PosSaleStatus::Completed->value,
                    'sold_at' => Carbon::now(),
                ])->save();
            }

            activity('pos_sale')
                ->causedBy($operator)
                ->performedOn($sale)
                ->event('wallet_payment_recorded')
                ->withProperties(['sale_reference' => $sale->reference, 'amount' => $total])
                ->log(sprintf(
                    'Operator %s settled sale %s with %s\'s wallet (₦%s).',
                    $operator->name,
                    $sale->reference,
                    $customer->name,
                    number_format($total),
                ));

            return $payment->refresh();
        });
    }
}
