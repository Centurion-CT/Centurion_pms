<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Actions\Customers\RefundFromWallet;
use App\Enums\CustomerWalletTransactionType;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\CustomerWalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single entry point for every guest-wallet mutation. Each operation
 * locks the wallet row FOR UPDATE, applies the balance delta, and
 * writes a ledger row in the same transaction — so the balance always
 * equals the sum of `amount_naira` in {@see CustomerWalletTransaction}.
 *
 *   topup   → balance ↑
 *   spend   → balance ↓ (throws if insufficient)
 *   refund  → balance ↓ (cash paid back; throws if insufficient)
 *   adjust  → balance ± (signed; refuses to go negative)
 *
 * Callers should never write to {@see CustomerWallet} directly; the
 * ledger would drift.
 */
class WalletService
{
    public function ensureWallet(Customer $customer): CustomerWallet
    {
        /** @var CustomerWallet $wallet */
        $wallet = CustomerWallet::query()->firstOrCreate(
            ['customer_id' => $customer->id],
            ['tenant_id' => $customer->tenant_id, 'balance_naira' => 0],
        );

        return $wallet;
    }

    /**
     * @param  array<string,mixed>  $meta
     * @param  array<string,int|null>  $links  Optional pointers: booking_id, pos_sale_id, booking_charge_id, payment_id
     */
    public function topup(
        Customer $customer,
        int $amount,
        string $description,
        ?string $paymentMethod = null,
        ?string $referenceNote = null,
        array $meta = [],
        array $links = [],
        ?Model $causer = null,
    ): CustomerWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $customer,
            CustomerWalletTransactionType::Topup,
            balanceDelta: +$amount,
            signedAmount: +$amount,
            description: $description,
            paymentMethod: $paymentMethod,
            referenceNote: $referenceNote,
            meta: $meta,
            links: $links,
            causer: $causer,
        );
    }

    /**
     * @param  array<string,mixed>  $meta
     * @param  array<string,int|null>  $links
     */
    public function spend(
        Customer $customer,
        int $amount,
        string $description,
        array $meta = [],
        array $links = [],
        ?Model $causer = null,
    ): CustomerWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $customer,
            CustomerWalletTransactionType::Spend,
            balanceDelta: -$amount,
            signedAmount: -$amount,
            description: $description,
            meta: $meta,
            links: $links,
            causer: $causer,
            assertAvailable: $amount,
        );
    }

    /**
     * Attempt to spend up to `$amount` — returns null if the wallet is
     * empty, otherwise records a partial spend for whatever is
     * available. Used by the overstay / balance-due cron and the
     * checkout charge sweep, where "spend as much as you can" beats
     * throwing on insufficient funds.
     *
     * @param  array<string,mixed>  $meta
     * @param  array<string,int|null>  $links
     */
    public function spendUpTo(
        Customer $customer,
        int $amount,
        string $description,
        array $meta = [],
        array $links = [],
        ?Model $causer = null,
    ): ?CustomerWalletTransaction {
        $this->assertPositive($amount);
        $wallet = $this->ensureWallet($customer);
        $available = min($amount, (int) $wallet->balance_naira);
        if ($available <= 0) {
            return null;
        }

        return $this->spend($customer, $available, $description, $meta, $links, $causer);
    }

    /**
     * Cash refund — funds leave the wallet as physical money handed
     * back. Requires manager approval upstream (enforced in
     * {@see RefundFromWallet}); this service
     * only knows about the ledger row.
     *
     * @param  array<string,mixed>  $meta
     */
    public function refund(
        Customer $customer,
        int $amount,
        string $description,
        ?string $paymentMethod = null,
        ?int $approvedByOperatorId = null,
        array $meta = [],
        ?Model $causer = null,
    ): CustomerWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $customer,
            CustomerWalletTransactionType::Refund,
            balanceDelta: -$amount,
            signedAmount: -$amount,
            description: $description,
            paymentMethod: $paymentMethod,
            meta: $meta,
            causer: $causer,
            approvedByOperatorId: $approvedByOperatorId,
            assertAvailable: $amount,
        );
    }

    /**
     * Signed reconciliation — admin only. Positive amount credits,
     * negative debits. Refuses to leave the wallet in deficit.
     */
    public function adjust(
        Customer $customer,
        int $signedAmount,
        string $description,
        ?Model $causer = null,
    ): CustomerWalletTransaction {
        if ($signedAmount === 0) {
            throw new RuntimeException('Adjustment must be non-zero.');
        }

        return $this->mutate(
            $customer,
            CustomerWalletTransactionType::Adjustment,
            balanceDelta: $signedAmount,
            signedAmount: $signedAmount,
            description: $description,
            meta: ['source' => 'admin_reconcile'],
            causer: $causer,
        );
    }

    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be positive.');
        }
    }

    /**
     * @param  array<string,mixed>  $meta
     * @param  array<string,int|null>  $links
     */
    private function mutate(
        Customer $customer,
        CustomerWalletTransactionType $type,
        int $balanceDelta,
        int $signedAmount,
        string $description,
        ?string $paymentMethod = null,
        ?string $referenceNote = null,
        array $meta = [],
        array $links = [],
        ?Model $causer = null,
        ?int $approvedByOperatorId = null,
        ?int $assertAvailable = null,
    ): CustomerWalletTransaction {
        return DB::transaction(function () use (
            $customer, $type, $balanceDelta, $signedAmount, $description,
            $paymentMethod, $referenceNote, $meta, $links, $causer,
            $approvedByOperatorId, $assertAvailable,
        ): CustomerWalletTransaction {
            $this->ensureWallet($customer);

            /** @var CustomerWallet $wallet */
            $wallet = CustomerWallet::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assertAvailable !== null && (int) $wallet->balance_naira < $assertAvailable) {
                throw new RuntimeException(sprintf(
                    'Insufficient wallet balance — ₦%s requested, ₦%s available.',
                    number_format($assertAvailable),
                    number_format((int) $wallet->balance_naira),
                ));
            }

            $newBalance = (int) $wallet->balance_naira + $balanceDelta;
            if ($newBalance < 0) {
                throw new RuntimeException('Wallet balance cannot go negative.');
            }

            $wallet->balance_naira = $newBalance;
            $wallet->save();

            return CustomerWalletTransaction::query()->create([
                'tenant_id' => $customer->tenant_id,
                'customer_wallet_id' => $wallet->id,
                'customer_id' => $customer->id,
                'type' => $type->value,
                'amount_naira' => $signedAmount,
                'balance_after_naira' => $newBalance,
                'description' => $description,
                'meta' => $meta ?: null,
                'booking_id' => $links['booking_id'] ?? null,
                'pos_sale_id' => $links['pos_sale_id'] ?? null,
                'booking_charge_id' => $links['booking_charge_id'] ?? null,
                'payment_id' => $links['payment_id'] ?? null,
                'payment_method' => $paymentMethod,
                'reference_note' => $referenceNote,
                'approved_by_operator_id' => $approvedByOperatorId,
                'causer_type' => $causer !== null ? $causer::class : null,
                'causer_id' => $causer?->getKey(),
            ]);
        });
    }
}
