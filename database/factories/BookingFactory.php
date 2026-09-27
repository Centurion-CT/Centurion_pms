<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\Bookings\BookingReferenceGenerator;
use DateTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkin = $this->faker->dateTimeBetween('+1 days', '+30 days');
        $checkout = (clone $checkin)->modify('+'.$this->faker->numberBetween(2, 5).' days');
        $nights = (int) (new DateTime($checkin->format('Y-m-d')))
            ->diff(new DateTime($checkout->format('Y-m-d')))->days;
        $roomTotal = $nights * 30_000;

        return [
            'tenant_id' => Tenant::factory(),
            'room_id' => Room::factory(),
            'booking_ref' => app(BookingReferenceGenerator::class)->generate(),
            'guest_name' => $this->faker->name(),
            'guest_email' => $this->faker->safeEmail(),
            'guest_phone' => $this->faker->e164PhoneNumber(),
            'adults' => 2,
            'children' => 0,
            'special_requests' => null,
            'checkin_date' => $checkin->format('Y-m-d'),
            'checkout_date' => $checkout->format('Y-m-d'),
            'nights' => $nights,
            'room_total' => $roomTotal,
            'paystack_fee' => 250,
            'keverna_fee' => 150,
            'total_amount' => $roomTotal + 400,
            'deposit_amount' => 0,
            'balance_due' => 0,
            'booking_status' => BookingStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'source' => BookingSource::Online,
        ];
    }

    public function confirmed(): self
    {
        return $this->state(fn () => [
            'booking_status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Succeeded,
        ]);
    }

    public function onHold(int $depositAmount = 30_000): self
    {
        return $this->state(fn (array $attrs) => [
            'booking_status' => BookingStatus::OnHold,
            'payment_status' => PaymentStatus::Succeeded,
            'deposit_amount' => $depositAmount,
            'balance_due' => ($attrs['total_amount'] ?? 0) - $depositAmount,
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn () => ['booking_status' => BookingStatus::Cancelled]);
    }
}
