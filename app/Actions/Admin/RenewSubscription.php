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
 * Renew an existing tenant subscription for a fresh N-month term.
 * Creates a NEW Subscription row (starts today OR right after the
 * current one expires, whichever is later) so the historical trail
 * is preserved. Marks the previous Active row Expired only after
 * its own expiry — this lets a renewal be recorded ahead of time
 * without flipping the tenant offline.
 *
 * Same "contract vs received" split + three-mail queue as
 * ActivateSubscription.
 */
class RenewSubscription
{
    public function handle(
        Tenant $tenant,
        Admin $admin,
        int $contractAmount,
        int $amountReceived,
        int $durationMonths = 12,
        ?string $notes = null,
        ?SubscriptionPlan $plan = null,
    ): Subscription {
        if ($durationMonths < 1 || $durationMonths > 36) {
            throw new \InvalidArgumentException('duration_months must be between 1 and 36.');
        }

        // Anchor on the current live sub — start the new term the
        // day the old one expires so time paid doesn't get lost.
        // If nothing live, anchor on today.
        $current = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trial->value])
            ->orderByDesc('expires_at')
            ->first();

        $startsAt = ($current && $current->expires_at->isFuture())
            ? $current->expires_at->copy()
            : Carbon::now();
        $expiresAt = $startsAt->copy()->addMonths($durationMonths);
        $outstanding = max(0, $contractAmount - $amountReceived);

        // If a plan is passed in explicitly (form choice), honour it.
        // Otherwise inherit from the current sub, and if the current
        // is trial (or nothing live), upgrade to Annual by default.
        $planValue = $plan?->value ?? (
            $current?->plan?->value === 'trial' || $current === null
                ? SubscriptionPlan::Annual->value
                : $current->plan->value
        );

        $sub = DB::transaction(function () use (
            $tenant, $admin, $planValue, $contractAmount, $amountReceived,
            $durationMonths, $startsAt, $expiresAt, $notes,
        ): Subscription {
            $sub = Subscription::query()->create([
                'tenant_id'         => $tenant->getKey(),
                'plan'              => $planValue,
                'amount'            => $amountReceived,
                'contract_amount'   => $contractAmount,
                'amount_received'   => $amountReceived,
                'duration_months'   => $durationMonths,
                'agreement_notes'   => $notes,
                'status'            => SubscriptionStatus::Active->value,
                'paystack_reference'=> 'admin-renew-'.Str::ulid()->toBase32(),
                'starts_at'         => $startsAt,
                'expires_at'        => $expiresAt,
                'paid_at'           => $amountReceived > 0 ? Carbon::now() : null,
            ]);

            $tenant->forceFill([
                'status'                  => TenantStatus::Active->value,
                'subscription_expires_at' => $expiresAt,
            ])->save();

            activity('subscription')
                ->causedBy($admin)
                ->performedOn($sub)
                ->event('renewed')
                ->withProperties([
                    'plan'            => $planValue,
                    'contract_amount' => $contractAmount,
                    'amount_received' => $amountReceived,
                    'duration_months' => $durationMonths,
                    'outstanding'     => max(0, $contractAmount - $amountReceived),
                ])
                ->log("Renewed sub for {$tenant->slug} · contract ₦".number_format($contractAmount).' · received ₦'.number_format($amountReceived));

            return $sub;
        });

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
