<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SlugGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Tenant + Operator pair atomically (PRD §2 / §4.2 / §6.1.1).
 *
 * The tenant is born in `trial` status for the free-trial window. The
 * *intended* post-trial tier (Starter / Growth / Pro) is captured in
 * two places:
 *  - `tenants.plan_id`: the string plan identifier, used by the
 *    subscription-upgrade flow to quote the right price.
 *  - `tenants.expected_units`: the number the operator gave at signup,
 *    the source of truth for tier derivation over the trial.
 *
 * The Subscription row itself is created with `plan = Trial` because it
 * describes what is currently active. On successful Paystack conversion
 * the Subscription's plan flips to the tenant's `plan_id`.
 */
class RegisterTenantOperator
{
    public function __construct(private SlugGenerator $slugs) {}

    /**
     * @param  array{name: string, email: string, password: string, phone: ?string, property_name: string, expected_units?: int, plan?: SubscriptionPlan}  $data
     */
    public function handle(array $data): RegistrationResult
    {
        return DB::transaction(function () use ($data): RegistrationResult {
            $slug = $this->slugs->fromPropertyName($data['property_name']);
            $trialDays = (int) config('keverna.pricing.trial_days');
            $now = Carbon::now();
            $trialExpiry = $now->copy()->addDays($trialDays);

            // Intended tier: caller-provided (e.g. Register livewire) or
            // derived from the expected_units if only the number was
            // passed. Falls back to Starter for callers that supply
            // neither (e.g. internal admin flows) — the safest default
            // because it's the smallest commitment on conversion.
            $intendedPlan = $data['plan']
                ?? (isset($data['expected_units'])
                    ? SubscriptionPlan::fromUnitCount((int) $data['expected_units'])
                    : SubscriptionPlan::Starter);

            $tenant = Tenant::query()->create([
                'name' => $data['property_name'],
                'slug' => $slug,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'plan_id' => $intendedPlan->value,
                'expected_units' => $data['expected_units'] ?? null,
                'status' => TenantStatus::Trial,
                'subscription_expires_at' => $trialExpiry,
            ]);

            // Central context — bind the freshly created tenant so the
            // BelongsToTenant trait can wire up tenant_id on operator + sub.
            [$operator] = $tenant->execute(function () use ($tenant, $data, $now, $trialExpiry): array {
                $op = Operator::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => $data['password'],
                ]);

                Subscription::query()->create([
                    'tenant_id' => $tenant->id,
                    'plan' => SubscriptionPlan::Trial,
                    'amount' => 0,
                    'status' => SubscriptionStatus::Trial,
                    'starts_at' => $now,
                    'expires_at' => $trialExpiry,
                ]);

                return [$op];
            });

            // Central identity for the unified-login spec (docs/unified-login.md).
            // Without this, keverna.com/login can't authenticate the freshly
            // registered operator — the /login form checks identity_accounts,
            // and the one-shot backfill command only handles pre-existing rows.
            //
            // An identity row may already exist (Phase 2 guest magic-link created
            // one). Reuse it in that case and just attach the password/name;
            // otherwise create fresh. Either way, add the operator link.
            $identityEmail = IdentityAccount::normaliseEmail($data['email']);
            $identity = IdentityAccount::query()->where('email', $identityEmail)->first();

            if ($identity === null) {
                $identity = new IdentityAccount;
                $identity->email = $identityEmail;
            }
            $identity->password = $data['password'];
            $identity->name = $data['name'];
            $identity->phone = $data['phone'] ?? $identity->phone;
            $identity->save();

            IdentityOperatorLink::create([
                'identity_account_id' => $identity->id,
                'tenant_id' => $tenant->id,
                'operator_id' => $operator->id,
            ]);

            return new RegistrationResult($tenant, $operator);
        });
    }
}
