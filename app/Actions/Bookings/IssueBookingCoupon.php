<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\DiscountType;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Operator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues a single-use store-credit coupon against a booking's rate
 * refund — used when a room reassignment lands the guest in a cheaper
 * room and they'd rather hold credit toward a future stay than take
 * cash back today.
 *
 * Reuses the existing Discount model as-is: `type = Fixed`, `value = amount`,
 * `max_uses = 1`. Anyone holding the code can redeem once, so the
 * guest can share it if they want (a limitation we've accepted for
 * this MVP — see the design notes). The description captures the
 * originating booking + operator + reason so the audit trail is
 * legible from the Discounts index.
 *
 * The generated code is human-readable and short enough to write on a
 * paper receipt: `CREDIT-XXXXXX` where the suffix is six upper-case
 * alphanumerics.
 */
class IssueBookingCoupon
{
    public function handle(
        Booking $booking,
        Operator $operator,
        int $amount,
        ?string $reason = null,
    ): Discount {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Coupon amount must be positive.');
        }

        return DB::transaction(function () use ($booking, $operator, $amount, $reason): Discount {
            $code = $this->generateUniqueCode($booking->tenant_id);

            $discount = Discount::query()->create([
                'tenant_id' => $booking->tenant_id,
                'created_by_operator_id' => $operator->id,
                'name' => 'Store credit · '.$booking->booking_ref,
                'code' => $code,
                'type' => DiscountType::Fixed,
                'value' => $amount,
                'max_uses' => 1,
                'used_count' => 0,
                'active' => true,
                'description' => $this->buildDescription($booking, $operator, $reason),
            ]);

            activity('booking')
                ->causedBy($operator)
                ->performedOn($booking)
                ->event('coupon_issued')
                ->withProperties([
                    'booking_ref' => $booking->booking_ref,
                    'discount_id' => $discount->id,
                    'code' => $code,
                    'amount' => $amount,
                    'reason' => $reason,
                ])
                ->log("Operator {$operator->name} issued store-credit coupon {$code} worth ₦".number_format($amount)." against booking {$booking->booking_ref}.");

            return $discount->refresh();
        });
    }

    private function buildDescription(Booking $booking, Operator $operator, ?string $reason): string
    {
        $parts = [
            'Store credit issued against booking '.$booking->booking_ref,
            'Guest: '.$booking->guest_name,
            'Issued by: '.$operator->name,
        ];
        if ($reason !== null && $reason !== '') {
            $parts[] = 'Reason: '.$reason;
        }

        return implode(' · ', $parts);
    }

    /**
     * Generate a CREDIT-XXXXXX code that doesn't collide with an
     * existing discount in the same tenant. Six alphanumeric chars =
     * ~2 billion combinations; the loop is a defence-in-depth against
     * the rare collision.
     */
    private function generateUniqueCode(int $tenantId): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $suffix = mb_strtoupper(Str::random(6));
            $code = 'CREDIT-'.$suffix;
            $exists = Discount::query()
                ->where('tenant_id', $tenantId)
                ->whereRaw('UPPER(code) = ?', [$code])
                ->exists();
            if (! $exists) {
                return $code;
            }
        }

        // Extremely unlikely — five 2-billion-space collisions in a row.
        // Fall through with a longer suffix so we still return something.
        return 'CREDIT-'.mb_strtoupper(Str::random(10));
    }
}
