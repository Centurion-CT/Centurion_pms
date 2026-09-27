<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan' => SubscriptionPlan::Annual,
            'amount' => SubscriptionPlan::Annual->amountNgn(),
            'status' => SubscriptionStatus::Active,
            'paystack_reference' => 'sub_'.Str::lower(Str::random(16)),
            'starts_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addYear(),
            'paid_at' => Carbon::now(),
        ];
    }

    public function trial(): self
    {
        return $this->state(fn () => [
            'plan' => SubscriptionPlan::Trial,
            'amount' => 0,
            'status' => SubscriptionStatus::Trial,
            'paystack_reference' => null,
            'paid_at' => null,
            'expires_at' => Carbon::now()->addDays(SubscriptionPlan::Trial->periodDays()),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'expires_at' => Carbon::now()->subDays(5),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Failed,
            'paid_at' => null,
        ]);
    }
}
