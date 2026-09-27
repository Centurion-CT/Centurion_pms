<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Operator;
use App\Services\Customers\WalletService;
use InvalidArgumentException;

/**
 * Front-desk cash top-up. Every operator role that can reach the
 * customer wallet UI can record a top-up — no manager gate — because
 * the money is already on the counter and refusing to log it would
 * leave the ledger blind.
 */
class RecordWalletTopup
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * `$method` accepts one of: cash | transfer | card | pos | other.
     * Callers (Livewire form validation) enforce the range; this action
     * only guards the amount.
     */
    public function handle(
        Customer $customer,
        Operator $operator,
        int $amount,
        string $method = 'cash',
        ?string $referenceNote = null,
    ): CustomerWalletTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Top-up amount must be positive.');
        }
        if (! in_array($method, ['cash', 'transfer', 'card', 'pos', 'other'], true)) {
            throw new InvalidArgumentException("Unsupported top-up method: {$method}");
        }

        $tx = $this->wallet->topup(
            customer: $customer,
            amount: $amount,
            description: sprintf('%s top-up recorded by %s', ucfirst($method), $operator->name),
            paymentMethod: $method,
            referenceNote: $referenceNote,
            meta: ['source' => 'cash_topup'],
            causer: $operator,
        );

        activity('customer_wallet')
            ->causedBy($operator)
            ->performedOn($customer)
            ->event('wallet_topped_up')
            ->withProperties([
                'amount' => $amount,
                'method' => $method,
                'transaction_id' => $tx->id,
            ])
            ->log(sprintf(
                'Operator %s topped up %s\'s wallet by ₦%s via %s.',
                $operator->name,
                $customer->name,
                number_format($amount),
                $method,
            ));

        return $tx;
    }
}
