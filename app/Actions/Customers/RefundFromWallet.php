<?php

declare(strict_types=1);

namespace App\Actions\Customers;

use App\Enums\CustomerInteractionDirection;
use App\Enums\CustomerInteractionType;
use App\Enums\OperatorRole;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\CustomerWalletTransaction;
use App\Models\Operator;
use App\Services\Customers\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cash refund out of the guest's wallet. Only Manager-role operators
 * may run this — the UI hides the form otherwise, and this action is
 * the belt-and-braces server-side check.
 *
 * Requires a written reason so the CustomerInteraction timeline
 * captures why the refund was granted (dispute? goodwill? checkout
 * leftover?).
 */
class RefundFromWallet
{
    public function __construct(private readonly WalletService $wallet) {}

    /**
     * `$method` accepts one of: cash | transfer | pos | other. Callers
     * (Livewire form validation) enforce the range; this action guards
     * the manager-role gate + amount + reason.
     */
    public function handle(
        Customer $customer,
        Operator $operator,
        int $amount,
        string $method,
        string $reason,
    ): CustomerWalletTransaction {
        if ($operator->role !== OperatorRole::Manager) {
            throw new AuthorizationException('Only managers can refund wallet funds.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }
        if (! in_array($method, ['cash', 'transfer', 'pos', 'other'], true)) {
            throw new InvalidArgumentException("Unsupported refund method: {$method}");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Refund reason is required.');
        }

        return DB::transaction(function () use ($customer, $operator, $amount, $method, $reason): CustomerWalletTransaction {
            $tx = $this->wallet->refund(
                customer: $customer,
                amount: $amount,
                description: sprintf('Cash refund via %s — %s', $method, $reason),
                paymentMethod: $method,
                approvedByOperatorId: $operator->id,
                meta: ['source' => 'cash_refund', 'reason' => $reason],
                causer: $operator,
            );

            CustomerInteraction::query()->create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'created_by_operator_id' => $operator->id,
                'type' => CustomerInteractionType::System->value,
                'direction' => CustomerInteractionDirection::Outbound->value,
                'subject' => sprintf('Wallet refund ₦%s', number_format($amount)),
                'body' => sprintf('%s refund of ₦%s. Reason: %s', ucfirst($method), number_format($amount), $reason),
                'system_event' => 'wallet.refunded',
                'happened_at' => Carbon::now(),
            ]);

            activity('customer_wallet')
                ->causedBy($operator)
                ->performedOn($customer)
                ->event('wallet_refunded')
                ->withProperties([
                    'amount' => $amount,
                    'method' => $method,
                    'reason' => $reason,
                    'transaction_id' => $tx->id,
                ])
                ->log(sprintf(
                    'Manager %s refunded ₦%s from %s\'s wallet via %s.',
                    $operator->name,
                    number_format($amount),
                    $customer->name,
                    $method,
                ));

            return $tx;
        });
    }
}
