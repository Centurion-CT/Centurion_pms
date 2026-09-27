<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'plan_id' => 'annual_300k',
            'status' => TenantStatus::Active,
            'paystack_secret_key' => 'sk_test_'.Str::random(40),
            'custom_domain' => null,
            'domain_verified_at' => null,
            'subscription_expires_at' => now()->addYear(),
        ];
    }

    public function trial(): self
    {
        return $this->state(fn () => [
            'status' => TenantStatus::Trial,
            'paystack_secret_key' => null,
            'subscription_expires_at' => now()->addDays((int) config('keverna.pricing.trial_days')),
        ]);
    }

    public function locked(): self
    {
        return $this->state(fn () => [
            'status' => TenantStatus::Locked,
            'subscription_expires_at' => now()->subDays(10),
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn () => [
            'status' => TenantStatus::Archived,
            'subscription_expires_at' => now()->subDays(40),
        ]);
    }

    public function withCustomDomain(string $domain): self
    {
        return $this->state(fn () => [
            'custom_domain' => $domain,
            'domain_verified_at' => now(),
        ]);
    }
}
