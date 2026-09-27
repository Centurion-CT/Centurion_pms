<?php

declare(strict_types=1);

namespace App\Actions\Corporates;

use App\Enums\MembershipStatus;
use App\Enums\WalletTransactionType;
use App\Models\CorporateWalletTransaction;
use App\Models\Membership;
use App\Services\Corporates\CorporateNotifier;
use App\Services\Corporates\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs one renewal cycle for a corporate membership.
 *
 * "Renewal" = hold + immediately capture one period's price + advance
 * `next_renewal_at` by the membership's interval. No checkout step —
 * the corporate signed up for ongoing service, so auto-renew is the
 * default. If the wallet doesn't have the funds, we don't half-bill;
 * we pause the membership and surface that on the corporate's portal
 * so an admin can top up + resume.
 *
 * Skipped completely for memberships without a `corporate_id` — those
 * are individual subscriptions paid via Paystack's recurring billing,
 * not the wallet.
 *
 * Idempotent under racing: the action wraps in a transaction and locks
 * the membership row FOR UPDATE so two scheduler ticks can't double-
 * charge a single period.
 */
class RenewCorporateMembership
{
    public const RESULT_RENEWED      = 'renewed';
    public const RESULT_NO_CORPORATE = 'no_corporate';
    public const RESULT_NOT_DUE      = 'not_due';
    public const RESULT_NOT_ACTIVE   = 'not_active';
    public const RESULT_PAUSED_LOW   = 'paused_insufficient_balance';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly CorporateNotifier $notifier,
    ) {}

    public function handle(Membership $membership, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        return DB::transaction(function () use ($membership, $now): string {
            /** @var Membership $fresh */
            $fresh = Membership::query()
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->corporate_id === null) {
                return self::RESULT_NO_CORPORATE;
            }
            if ($fresh->status !== MembershipStatus::Active) {
                return self::RESULT_NOT_ACTIVE;
            }
            if ($fresh->next_renewal_at === null || $fresh->next_renewal_at->greaterThan($now)) {
                return self::RESULT_NOT_DUE;
            }

            $corporate = $fresh->corporate;
            if ($corporate === null || ! $corporate->status->isLive()) {
                return self::RESULT_NOT_ACTIVE;
            }

            $amount = (int) $fresh->amount_naira;
            if ($amount <= 0) {
                // Trial / comped period — just push the date forward and exit.
                $fresh->next_renewal_at = $this->advance($fresh->next_renewal_at, $fresh->billing_interval->value);
                $fresh->save();

                return self::RESULT_RENEWED;
            }

            // Wallet check. If the corporate ran dry, pause rather than
            // try to half-bill. The action-level audit log surfaces this
            // and the corporate admin's dashboard banner will too.
            $wallet = $this->wallets->ensureWallet($corporate);
            if ($wallet->available() < $amount) {
                $fresh->status = MembershipStatus::Paused;
                $fresh->save();

                $shortfall = $amount - $wallet->available();
                activity('membership')
                    ->performedOn($fresh)
                    ->event('paused_insufficient_balance')
                    ->withProperties([
                        'amount'    => $amount,
                        'available' => $wallet->available(),
                        'shortfall' => $shortfall,
                    ])
                    ->log("Membership {$fresh->id} paused: wallet has ₦{$wallet->available()}, needed ₦{$amount}.");

                $this->notifier->membershipPaused($fresh, $corporate, $shortfall);

                return self::RESULT_PAUSED_LOW;
            }

            // Hold + capture in the same tick — same ledger pattern as
            // a brand-new corporate booking, but skips the per-member
            // cap check (the corporate already committed to ongoing
            // service when they signed up).
            $meta = [
                'subject_type' => Membership::class,
                'subject_id'   => $fresh->id,
                'ref'          => 'membership-'.$fresh->id.'-renewal',
                'period_start' => $fresh->next_renewal_at->toIso8601String(),
            ];

            $this->wallets->hold(
                corporate: $corporate,
                amount: $amount,
                description: "Renewal hold · membership {$fresh->id}",
                meta: $meta,
                member: $fresh->corporateMember,
            );
            $this->wallets->spend(
                corporate: $corporate,
                amount: $amount,
                description: "Renewal spend · membership {$fresh->id}",
                meta: $meta,
                member: $fresh->corporateMember,
            );

            $fresh->next_renewal_at = $this->advance($fresh->next_renewal_at, $fresh->billing_interval->value);
            $fresh->save();

            activity('membership')
                ->performedOn($fresh)
                ->event('renewed')
                ->withProperties([
                    'amount'              => $amount,
                    'next_renewal_at'     => $fresh->next_renewal_at?->toIso8601String(),
                ])
                ->log("Membership {$fresh->id} renewed for ₦{$amount}.");

            return self::RESULT_RENEWED;
        });
    }

    /**
     * Push a renewal date forward by one billing interval. Hour/Day/
     * Week intervals don't typically run as auto-renewals (those are
     * one-off DeskBookings in v1) but we handle them for safety.
     */
    private function advance(Carbon $from, string $interval): Carbon
    {
        return match ($interval) {
            'hour'  => $from->copy()->addHour(),
            'day'   => $from->copy()->addDay(),
            'week'  => $from->copy()->addWeek(),
            'year'  => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }

    /**
     * @return array{
     *   processed: int,
     *   renewed: int,
     *   paused: int,
     *   skipped: int,
     * }
     */
    public function batch(int $limit = 500): array
    {
        $renewed = 0;
        $paused  = 0;
        $skipped = 0;

        $candidates = Membership::query()
            ->whereNotNull('corporate_id')
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '<=', Carbon::now())
            ->orderBy('next_renewal_at')
            ->limit($limit)
            ->get();

        foreach ($candidates as $membership) {
            $result = $this->handle($membership);
            match ($result) {
                self::RESULT_RENEWED      => $renewed++,
                self::RESULT_PAUSED_LOW   => $paused++,
                default                   => $skipped++,
            };
        }

        return [
            'processed' => $candidates->count(),
            'renewed'   => $renewed,
            'paused'    => $paused,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Helper: identify whether a recent ledger entry exists for the
     * same period start, so callers can avoid double-actioning across
     * the scheduled command + a manual operator click.
     */
    public function hasRecentRenewalSpend(Membership $membership, Carbon $periodStart): bool
    {
        return CorporateWalletTransaction::query()
            ->where('corporate_id', $membership->corporate_id)
            ->where('type', WalletTransactionType::Spend->value)
            ->whereJsonContains('meta->subject_id', $membership->id)
            ->whereJsonContains('meta->subject_type', Membership::class)
            ->whereJsonContains('meta->period_start', $periodStart->toIso8601String())
            ->exists();
    }
}
