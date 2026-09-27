<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => Booking::factory(),
            'paystack_reference' => 'pay_'.Str::lower(Str::random(20)),
            'amount' => 50_000,
            'type' => PaymentType::Full,
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);
    }

    public function deposit(int $amount = 30_000): self
    {
        return $this->state(fn () => [
            'type' => PaymentType::Deposit,
            'amount' => $amount,
        ]);
    }
}
