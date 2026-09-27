<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Operator>
 */
class OperatorFactory extends Factory
{
    protected $model = Operator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Tenant is normally resolved by the BelongsToTenant trait via the
            // currently-active tenant. Tests that don't bind one ahead of time
            // get a fresh tenant here so the factory still works standalone.
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->e164PhoneNumber(),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'activated_at' => now(),
            'password' => Hash::make('password'),
        ];
    }

    public function unverified(): self
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
            'phone_verified_at' => null,
        ]);
    }

    /**
     * A pending-invite operator — created by an invite but the invitee
     * hasn't set a password yet. Tenant + central login refuse this
     * state; the only path in is /invite/activate via the magic link.
     */
    public function pendingActivation(): self
    {
        return $this->state(fn () => [
            'activated_at' => null,
        ]);
    }
}
