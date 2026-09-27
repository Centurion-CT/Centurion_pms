<?php

declare(strict_types=1);

namespace App\Services\Discounts;

use App\Models\Discount;

/**
 * Outcome of a {@see DiscountEngine} lookup. Three shapes:
 *
 *   - applies=true                 → discount + amountOff are ready to use
 *   - applies=false, discount=set  → code matched something but a rule
 *                                    failed (date window, room target,
 *                                    usage cap, etc.) — surface `reason`
 *                                    to the operator/guest
 *   - applies=false, discount=null → no record found for the typed code
 *                                    (return value of resolveByCode when
 *                                    the code literally doesn't exist)
 */
final readonly class DiscountResolution
{
    public function __construct(
        public bool $applies,
        public ?Discount $discount,
        public int $amountOff,
        public ?string $code,
        public bool $autoApplied,
        public ?string $reason,
    ) {}

    public static function accepted(
        Discount $discount,
        int $amountOff,
        ?string $code,
        bool $autoApplied,
    ): self {
        return new self(
            applies: true,
            discount: $discount,
            amountOff: $amountOff,
            code: $code,
            autoApplied: $autoApplied,
            reason: null,
        );
    }

    public static function rejected(string $code, string $reason, ?Discount $discount = null): self
    {
        return new self(
            applies: false,
            discount: $discount,
            amountOff: 0,
            code: $code,
            autoApplied: false,
            reason: $reason,
        );
    }
}
