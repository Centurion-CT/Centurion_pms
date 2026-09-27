<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Mail\Subscriptions\SubscriptionContractMail;
use App\Mail\Subscriptions\SubscriptionInvoiceMail;
use App\Mail\Subscriptions\SubscriptionReceiptMail;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Super-admin path to activate a subscription for a tenant. Splits
 * "what we agreed" (contract_amount) from "what came in"
 * (amount_received) so an admin can accept partial cash / transfer
 * upfront and still register the contract.
 *
 * Wraps every side-effect in a DB transaction and queues three
 * transactional mails at commit:
 *   * Contract terms — start / expiry / plan / duration / amount
 *   * Receipt — for whatever amount_received was recorded
 *   * Invoice — ONLY when contract_amount > amount_received (an
 *     outstanding remains). Skipped when paid in full.
 */
class ActivateSubscription
{
    /**
     * @param  int  $contractAmount   NGN
     * @param  int  $amountReceived   NGN (0 for pure trial activations)
     * @param  int  $durationMonths   1..36
     */
    public function handle(
        Tenant $tenant,
        Admin $admin,
        SubscriptionPlan $plan,
        int $contractAmount,
        int $amountReceived,
        int $durationMonths = 12,
        ?Carbon $startsAt = null,
        ?string $notes = null,
    ): Subscription {
        if ($durationMonths < 1 || $durationMonths > 36) {
            throw new \InvalidArgumentException('duration_months must be between 1 and 36.');
        }
        if ($contractAmount < 0 || $amountReceived < 0) {
            throw new \InvalidArgumentException('amounts must be non-negative.');
        }

        $startsAt ??= Carbon::now();
        $expiresAt = $startsAt->copy()->addMonths($durationMonths);
        $outstanding = max(0, $contractAmount - $amountReceived);

        $sub = DB::transaction(function () use (
            $tenant, $admin, $plan, $contractAmount, $amountReceived,
            $durationMonths, $startsAt, $expiresAt, $notes,
        ): Subscription {
            // Activate replaces whatever was here before (trial rows,
            // stale Active rows on abandoned upgrades, etc.). Mark
            // them Expired so the admin list only shows the fresh row
            // as Active. Renew doesn't do this because it anchors on
            // the current expiry to preserve time already paid.
            Subscription::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('status', [
                    SubscriptionStatus::Trial->value,
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Failed->value,
                ])
                ->update(['status' => SubscriptionStatus::Expired->value]);

            $sub = Subscription::query()->create([
                'tenant_id'         => $tenant->getKey(),
                'plan'              => $plan->value,
                // Keep legacy `amount` in sync with amount_received so
                // downstream reports that read `amount` behave the same.
                'amount'            => $amountReceived,
                'contract_amount'   => $contractAmount,
                'amount_received'   => $amountReceived,
                'duration_months'   => $durationMonths,
                'agreement_notes'   => $notes,
                'status'            => SubscriptionStatus::Active->value,
                'paystack_reference'=> 'admin-'.Str::ulid()->toBase32(),
                'starts_at'         => $startsAt,
                'expires_at'        => $expiresAt,
                'paid_at'           => $amountReceived > 0 ? Carbon::now() : null,
            ]);

            // Reflect on the tenant so downstream isLive() / banner
            // checks stop showing "expired" the moment the sub lands.
            $tenant->forceFill([
                'status'                  => TenantStatus::Active->value,
                'subscription_expires_at' => $expiresAt,
            ])->save();

            activity('subscription')
                ->causedBy($admin)
                ->performedOn($sub)
                ->event('activated')
                ->withProperties([
                    'plan'            => $plan->value,
                    'contract_amount' => $contractAmount,
                    'amount_received' => $amountReceived,
                    'duration_months' => $durationMonths,
                    'outstanding'     => max(0, $contractAmount - $amountReceived),
                ])
                ->log("Activated {$plan->value} sub for {$tenant->slug} · contract ₦".number_format($contractAmount).' · received ₦'.number_format($amountReceived));

            return $sub;
        });

        // Three transactional mails, queued at DB commit so a
        // transaction rollback never sends "you paid" on a booking
        // that didn't persist.
        Mail::to($tenant->email)->queue(new SubscriptionContractMail($sub->refresh()));
        if ($amountReceived > 0) {
            Mail::to($tenant->email)->queue(new SubscriptionReceiptMail($sub->refresh(), $amountReceived));
        }
        if ($outstanding > 0) {
            Mail::to($tenant->email)->queue(new SubscriptionInvoiceMail($sub->refresh(), $outstanding));
        }

        return $sub;
    }
}
