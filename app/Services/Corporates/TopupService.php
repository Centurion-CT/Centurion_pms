<?php

declare(strict_types=1);

namespace App\Services\Corporates;

use App\Models\Corporate;
use App\Models\CorporateMember;
use App\Models\CorporateWalletTopup;
use App\Models\Tenant;
use App\Services\Paystack\BusinessPaystack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single entry point for the corporate wallet top-up lifecycle.
 *
 *   - {@see initiate()} creates a pending {@see CorporateWalletTopup}
 *     row + asks Paystack for an authorization URL. The caller
 *     (portal Wallet livewire) redirects the admin to that URL.
 *
 *   - {@see finaliseFromWebhook()} runs when Paystack POSTs back —
 *     verifies the amount, marks the row Succeeded, credits the
 *     wallet via {@see WalletService::topup()}. Idempotent: replaying
 *     the same reference is a no-op once succeeded.
 *
 *   - {@see markFailed()} / {@see markCancelled()} surface non-success
 *     terminal states so the admin can see why a checkout died.
 *
 * Top-ups always use Keverna's master Paystack key (PRD §4.1, same as
 * subscription payments). Funds settle to FregateLab's account; the
 * platform later credits the operator per-booking.
 */
class TopupService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly CorporateNotifier $notifier,
    ) {}

    /**
     * @return array{topup: CorporateWalletTopup, authorization_url: string}
     */
    public function initiate(
        Corporate $corporate,
        CorporateMember $admin,
        int $amount,
        ?string $note = null,
    ): array {
        if ($amount < 1000) {
            throw new RuntimeException('Minimum top-up is ₦1,000.');
        }

        // Reference must be unique; prefix `KCT-` (Keverna Corporate
        // Top-up) so webhook log lines + Paystack dashboard searches
        // are unambiguous.
        $reference = 'KCT-'.strtoupper(Str::random(12));

        $topup = CorporateWalletTopup::query()->create([
            'corporate_id'        => $corporate->id,
            'corporate_member_id' => $admin->id,
            'reference'           => $reference,
            'amount_naira'        => $amount,
            'status'              => CorporateWalletTopup::STATUS_PENDING,
            'note'                => $note,
        ]);

        // Paid into the corporate's business's own Paystack account, the
        // same one its guests' bookings use (BusinessPaystack).
        $tenant = $this->businessOf($corporate);
        $client = BusinessPaystack::client($tenant);
        try {
            $init = $client->initializeTransaction([
                'email'        => $admin->email,
                'amount_ngn'   => $amount,
                'reference'    => $reference,
                'callback_url' => route('corporate.wallet.callback'),
                'metadata'     => [
                    'kind'                  => 'corporate_wallet_topup',
                    'corporate_id'          => $corporate->id,
                    'corporate_member_id'   => $admin->id,
                    'note'                  => $note,
                ],
                'subaccount'   => BusinessPaystack::subaccount($tenant),
            ]);
        } catch (\Throwable $e) {
            // Paystack didn't accept the init — leave the row as
            // failed so the admin can retry without a stuck "pending"
            // sitting in their list forever.
            $topup->update(['status' => CorporateWalletTopup::STATUS_FAILED]);
            throw new RuntimeException('Payment gateway unavailable: '.$e->getMessage());
        }

        $topup->update(['authorization_url' => $init['authorization_url']]);

        return [
            'topup' => $topup->refresh(),
            'authorization_url' => $init['authorization_url'],
        ];
    }

    /**
     * Called by the webhook + the callback URL. Verifies via the
     * Paystack verifyTransaction API as a second source of truth
     * (webhook payload + verify call must agree), then credits the
     * wallet. Idempotent — replaying does nothing once Succeeded.
     */
    public function finaliseFromReference(string $reference, ?Carbon $paidAt = null): bool
    {
        /** @var CorporateWalletTopup|null $topup */
        $topup = CorporateWalletTopup::query()
            ->where('reference', $reference)
            ->first();

        // The corporate is scoped to the current business, so another
        // business's top-up stops here, before anything is changed.
        $corporate = $topup?->corporate;
        if ($topup === null || $corporate === null) {
            return false;
        }

        if ($topup->isSucceeded()) {
            return false;
        }

        $verified = BusinessPaystack::client($this->businessOf($corporate))->verifyTransaction($reference);

        if (($verified['status'] ?? '') !== 'success') {
            $topup->update(['status' => CorporateWalletTopup::STATUS_FAILED]);

            return false;
        }

        if ((int) ($verified['amount_ngn'] ?? 0) !== (int) $topup->amount_naira) {
            // Amount tampering — fail loud rather than credit.
            $topup->update(['status' => CorporateWalletTopup::STATUS_FAILED]);

            return false;
        }

        $this->wallets->topup(
            corporate: $corporate,
            amount: (int) $topup->amount_naira,
            description: 'Paystack top-up · '.$topup->reference,
            paystackReference: $topup->reference,
            causer: $topup->member,
            member: $topup->member,
        );

        $topup->update([
            'status'  => CorporateWalletTopup::STATUS_SUCCEEDED,
            'paid_at' => $paidAt ?? Carbon::now(),
        ]);

        $this->notifier->topupSucceeded($topup->refresh());

        return true;
    }

    public function markFailed(CorporateWalletTopup $topup): void
    {
        if ($topup->isPending()) {
            $topup->update(['status' => CorporateWalletTopup::STATUS_FAILED]);
        }
    }

    public function markCancelled(CorporateWalletTopup $topup): void
    {
        if ($topup->isPending()) {
            $topup->update(['status' => CorporateWalletTopup::STATUS_CANCELLED]);
        }
    }

    private function businessOf(Corporate $corporate): Tenant
    {
        return Tenant::query()->findOrFail($corporate->tenant_id);
    }
}
