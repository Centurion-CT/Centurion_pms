<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use InvalidArgumentException;

/**
 * Validation failure from {@see RefundHeldBookingMoney}. Carries a
 * machine code (host API) and the toast title the web Refunds page
 * has always shown for that case.
 */
class HeldMoneyRefundException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $title,
        string $message,
    ) {
        parent::__construct($message);
    }
}
