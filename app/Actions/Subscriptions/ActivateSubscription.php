<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Events\SubscriptionActivated;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Activates a Subscription row in response to a verified Paystack success
 * event (PRD §3 / §4.2). Idempotent: a second call for the already-active
 * row is a no-op so webhook replays don't double-fire downstream effects.
 */
class ActivateSubscription
{
    public function handle(Subscription $subscription, ?Carbon $paidAt = null): bool
    {
        // Idempotency guard — webhook may retry. If we've already flipped the
        // row to Active we return false so the caller knows nothing happened
        // and SubscriptionActivated isn't re-dispatched.
        if ($subscription->status === SubscriptionStatus::Active) {
            return false;
        }

        $now = $paidAt ?? Carbon::now();
        $period = $subscription->plan->periodDays();

        // Resolve the tenant directly. Tenant itself is central — TenantScope
        // doesn't apply to it — so no need to lift any scope here.
        $tenant = Tenant::query()->find($subscription->tenant_id);

        DB::transaction(function () use ($subscription, $now, $period, $tenant): void {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'paid_at' => $now,
                'starts_at' => $now,
                'expires_at' => $now->copy()->addDays($period),
            ])->save();

            // Expire older live row(s) for the same tenant. Webhook context
            // has no tenant in scope -> lift it explicitly.
            Subscription::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $subscription->tenant_id)
                ->where('id', '!=', $subscription->id)
                ->whereIn('status', [SubscriptionStatus::Trial->value, SubscriptionStatus::Active->value])
                ->update([
                    'status' => SubscriptionStatus::Expired->value,
                    'updated_at' => $now,
                ]);

            if ($tenant !== null) {
                $tenant->forceFill([
                    'status' => $subscription->plan === SubscriptionPlan::Annual
                        ? TenantStatus::Active
                        : TenantStatus::Trial,
                    'plan_id' => $subscription->plan->value,
                    'subscription_expires_at' => $subscription->expires_at,
                ])->save();
            }
        });

        SubscriptionActivated::dispatch($subscription->refresh());

        return true;
    }
}
