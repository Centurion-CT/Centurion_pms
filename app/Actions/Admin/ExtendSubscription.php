<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Admin;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Multitenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manually push a tenant's current subscription out by N days (PRD §18).
 *
 * Use cases: comping a customer after an outage, settling a chargeback
 * dispute, extending while we wait for a bank reconciliation.
 *
 * If the tenant is Locked / in Grace / Archived, the action additionally
 * flips them back to Active — extending an expired subscription would
 * otherwise leave them locked.
 *
 * The write is wrapped in a transaction + audit-logged with the admin as
 * the causer so dispute reviews can trace who comped whom.
 */
class ExtendSubscription
{
    public function handle(Tenant $tenant, Admin $admin, int $days): Subscription
    {
        if ($days < 1) {
            throw new RuntimeException('Must extend by at least 1 day.');
        }

        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->latest('expires_at')
            ->first();

        if ($subscription === null) {
            throw new RuntimeException("Tenant {$tenant->slug} has no subscription to extend.");
        }

        DB::transaction(function () use ($subscription, $tenant, $days): void {
            // If the current expiry is past, anchor the new expiry to today
            // (don't extend from the stale past date — that'd compound debt).
            $anchor = $subscription->expires_at->isPast()
                ? Carbon::now()
                : $subscription->expires_at;

            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'expires_at' => $anchor->copy()->addDays($days),
            ])->save();

            $tenant->forceFill([
                'status' => TenantStatus::Active,
                'subscription_expires_at' => $subscription->expires_at,
            ])->save();
        });

        $fresh = $subscription->refresh();

        activity('admin')
            ->causedBy($admin)
            ->performedOn($tenant)
            ->event('subscription_extended')
            ->withProperties([
                'days' => $days,
                'new_expires_at' => $fresh->expires_at->toIso8601String(),
            ])
            ->log("Admin {$admin->name} extended {$tenant->slug} subscription by {$days} days.");

        return $fresh;
    }
}
