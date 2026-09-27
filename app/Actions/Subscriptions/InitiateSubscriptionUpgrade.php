<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Operator;
use App\Models\Subscription;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pre-creates a Subscription row in Failed state, then calls Paystack to get
 * an authorization_url for the checkout. The webhook flips the row to Active
 * on charge.success (see SubscriptionWebhookController).
 *
 * Pre-creating the row makes the reference stable + locally addressable so a
 * later webhook can find the row by FK; also gives us a place to track
 * abandoned checkouts for reconciliation.
 */
class InitiateSubscriptionUpgrade
{
    public function handle(Operator $operator, SubscriptionPlan $plan = SubscriptionPlan::Annual): string
    {
        $reference = $this->generateReference($plan);
        $tenantId = $operator->tenant_id;
        $now = Carbon::now();

        // Subscription row created in a transaction so any abandoned Paystack
        // call leaves us with a clean DB state.
        $subscription = DB::transaction(function () use ($tenantId, $reference, $plan, $now): Subscription {
            return Subscription::query()->create([
                'tenant_id' => $tenantId,
                'plan' => $plan,
                'amount' => $plan->amountNgn(),
                'status' => SubscriptionStatus::Failed, // flips to Active on webhook success
                'paystack_reference' => $reference,
                'starts_at' => $now,
                'expires_at' => $now->copy()->addDays($plan->periodDays()),
            ]);
        });

        $result = PaystackClient::forSubscriptions()->initializeTransaction([
            'email' => $operator->email,
            'amount_ngn' => $plan->amountNgn(),
            'reference' => $reference,
            'callback_url' => (string) config('services.paystack.callback_url'),
            'metadata' => [
                'subscription_id' => $subscription->id,
                'tenant_id' => $tenantId,
                'operator_id' => $operator->id,
            ],
        ]);

        return $result['authorization_url'];
    }

    private function generateReference(SubscriptionPlan $plan): string
    {
        return 'kev_sub_'.$plan->value.'_'.Str::lower(Str::random(20));
    }
}
