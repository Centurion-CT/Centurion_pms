<?php

declare(strict_types=1);

namespace App\Services\Corporates;

use App\Enums\WalletTransactionType;
use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\CorporateWallet;
use App\Models\CorporateWalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Notifier is resolved lazily inside mutate() to avoid a constructor
// dependency cycle (CorporateNotifier doesn't depend on WalletService
// but a future refactor might, and we'd like to keep this seam loose).

/**
 * Single entry point for every wallet mutation.
 *
 * Each operation is wrapped in a transaction that locks the wallet
 * row FOR UPDATE, applies the balance/held delta, and writes a
 * matching ledger entry. Callers should never write to
 * {@see CorporateWallet} directly — the ledger would diverge.
 *
 *   topup     → balance ↑
 *   hold      → held ↑   (balance unchanged; available ↓)
 *   release   → held ↓   (cancelled before capture; available ↑)
 *   spend     → held ↓ AND balance ↓ (hold converted to a captured spend)
 *   refund    → balance ↑ (post-capture cancellation)
 *   adjust    → balance ± (signed; for manual reconciliation)
 *
 * Throws on insufficient balance / held mismatches rather than letting
 * the ledger drift.
 */
class WalletService
{
    public function ensureWallet(Corporate $corporate): CorporateWallet
    {
        return CorporateWallet::query()->firstOrCreate(
            ['corporate_id' => $corporate->id],
            ['balance_naira' => 0, 'held_naira' => 0],
        );
    }

    public function topup(
        Corporate $corporate,
        int $amount,
        ?string $description = null,
        ?string $paystackReference = null,
        ?Model $causer = null,
        ?CorporateMember $member = null,
    ): CorporateWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $corporate,
            $member,
            WalletTransactionType::Topup,
            +$amount,
            balanceDelta: +$amount,
            heldDelta: 0,
            description: $description,
            paystackReference: $paystackReference,
            causer: $causer,
        );
    }

    public function hold(
        Corporate $corporate,
        int $amount,
        ?string $description = null,
        array $meta = [],
        ?Model $causer = null,
        ?CorporateMember $member = null,
    ): CorporateWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $corporate,
            $member,
            WalletTransactionType::Hold,
            -$amount,
            balanceDelta: 0,
            heldDelta: +$amount,
            description: $description,
            meta: $meta,
            causer: $causer,
            assertAvailable: $amount,
        );
    }

    public function release(
        Corporate $corporate,
        int $amount,
        ?string $description = null,
        array $meta = [],
        ?Model $causer = null,
        ?CorporateMember $member = null,
    ): CorporateWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $corporate,
            $member,
            WalletTransactionType::Release,
            +$amount,
            balanceDelta: 0,
            heldDelta: -$amount,
            description: $description,
            meta: $meta,
            causer: $causer,
            assertHeld: $amount,
        );
    }

    public function spend(
        Corporate $corporate,
        int $amount,
        ?string $description = null,
        array $meta = [],
        ?Model $causer = null,
        ?CorporateMember $member = null,
    ): CorporateWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $corporate,
            $member,
            WalletTransactionType::Spend,
            -$amount,
            balanceDelta: -$amount,
            heldDelta: -$amount,
            description: $description,
            meta: $meta,
            causer: $causer,
            assertHeld: $amount,
        );
    }

    public function refund(
        Corporate $corporate,
        int $amount,
        ?string $description = null,
        array $meta = [],
        ?Model $causer = null,
        ?CorporateMember $member = null,
    ): CorporateWalletTransaction {
        $this->assertPositive($amount);

        return $this->mutate(
            $corporate,
            $member,
            WalletTransactionType::Refund,
            +$amount,
            balanceDelta: +$amount,
            heldDelta: 0,
            description: $description,
            meta: $meta,
            causer: $causer,
        );
    }

    /**
     * Signed adjustment — admin enters a positive or negative number
     * to reconcile (e.g. cover an off-platform refund).
     */
    public function adjust(
        Corporate $corporate,
        int $signedAmount,
        string $description,
        ?Model $causer = null,
    ): CorporateWalletTransaction {
        if ($signedAmount === 0) {
            throw new RuntimeException('Adjustment must be non-zero.');
        }

        return $this->mutate(
            $corporate,
            null,
            WalletTransactionType::Adjustment,
            $signedAmount,
            balanceDelta: $signedAmount,
            heldDelta: 0,
            description: $description,
            causer: $causer,
            assertBalanceAfterNonNegative: true,
        );
    }

    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be positive.');
        }
    }

    private function mutate(
        Corporate $corporate,
        ?CorporateMember $member,
        WalletTransactionType $type,
        int $signedAmount,
        int $balanceDelta,
        int $heldDelta,
        ?string $description = null,
        array $meta = [],
        ?string $paystackReference = null,
        ?Model $causer = null,
        ?int $assertAvailable = null,
        ?int $assertHeld = null,
        bool $assertBalanceAfterNonNegative = false,
    ): CorporateWalletTransaction {
        return DB::transaction(function () use (
            $corporate, $member, $type, $signedAmount, $balanceDelta, $heldDelta,
            $description, $meta, $paystackReference, $causer,
            $assertAvailable, $assertHeld, $assertBalanceAfterNonNegative,
        ): CorporateWalletTransaction {
            /** @var CorporateWallet $wallet */
            $wallet = CorporateWallet::query()
                ->where('corporate_id', $corporate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assertAvailable !== null && $wallet->available() < $assertAvailable) {
                throw new RuntimeException("Insufficient available balance — ₦{$assertAvailable} requested, ₦".$wallet->available().' available.');
            }
            if ($assertHeld !== null && $wallet->held_naira < $assertHeld) {
                throw new RuntimeException("Insufficient held balance for this operation.");
            }

            $newBalance = (int) $wallet->balance_naira + $balanceDelta;
            $newHeld    = (int) $wallet->held_naira    + $heldDelta;

            if ($assertBalanceAfterNonNegative && $newBalance < 0) {
                throw new RuntimeException('Adjustment would leave the wallet in deficit.');
            }
            if ($newHeld < 0) {
                throw new RuntimeException('Wallet held balance cannot go negative.');
            }
            if ($newBalance < 0) {
                throw new RuntimeException('Wallet balance cannot go negative.');
            }

            // Snapshot the pre-mutation available balance so we can
            // detect a low-balance crossing once the mutation lands.
            $availableBefore = max(0, (int) $wallet->balance_naira - (int) $wallet->held_naira);

            $wallet->balance_naira = $newBalance;
            $wallet->held_naira    = $newHeld;
            $wallet->save();

            $tx = CorporateWalletTransaction::query()->create([
                'corporate_wallet_id'  => $wallet->id,
                'corporate_id'         => $corporate->id,
                'corporate_member_id'  => $member?->id,
                'type'                 => $type->value,
                'amount_naira'         => $signedAmount,
                'balance_after_naira'  => $newBalance,
                'held_after_naira'     => $newHeld,
                'description'          => $description,
                'meta'                 => $meta ?: null,
                'paystack_reference'   => $paystackReference,
                'causer_type'          => $causer !== null ? $causer::class : null,
                'causer_id'            => $causer?->getKey(),
            ]);

            // Low-balance crossing alert — fires once, only when the
            // mutation actually moved us across the line. We don't
            // alert on every Hold/Spend after the line was already
            // crossed, so corporate admins don't get spammed.
            $threshold = (int) ($wallet->low_balance_threshold_naira ?? 0);
            $availableAfter = max(0, $newBalance - $newHeld);
            if ($threshold > 0 && $availableBefore >= $threshold && $availableAfter < $threshold) {
                app(CorporateNotifier::class)->lowBalance($corporate, $wallet->refresh());
            }

            return $tx;
        });
    }
}
