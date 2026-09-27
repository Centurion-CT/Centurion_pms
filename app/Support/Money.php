<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Currency;
use App\Models\Property;
use App\Services\Properties\PropertyContext;

/**
 * Money display helper. All amounts in the PMS are stored as integer
 * whole units of the property's currency (naira, cedi, shilling…).
 * This class turns them into the right rendered string with the right
 * symbol without the caller having to plumb $currency through every
 * template.
 *
 * Resolution order for the currency:
 *   1. Explicit $currency arg (highest priority)
 *   2. Property arg's currency
 *   3. PropertyContext::current()->currency (the operator's picked branch)
 *   4. Default NGN (matches the historical pre-currency behavior)
 *
 * Never does FX conversion. Amounts always render in the currency
 * they were entered in.
 */
class Money
{
    /**
     * Format an integer amount in the resolved currency. Prefix by
     * default (₦1,000); some currencies read more naturally with a
     * trailing code (1,000 XOF) — pass `suffix: true`.
     */
    public static function format(
        int|float|null $amount,
        Currency|Property|null $currency = null,
        bool $suffix = false,
    ): string {
        $cur = self::resolve($currency);
        // Every amount in the codebase is stored as a whole unit of
        // its currency (whole naira, whole shillings, whole dollars —
        // never minor units like cents/kobo/pesewas). The decimals()
        // hint here is purely a display convention: 2 for currencies
        // that read naturally as "1,000.00" and 0 for ones that read
        // as "1,000" flat.
        $val = (float) ($amount ?? 0);
        $rendered = number_format($val, $cur->decimals());
        return $suffix
            ? $rendered.' '.$cur->value
            : $cur->symbol().$rendered;
    }

    /**
     * Currency of a property (or the current operator's branch when
     * no property is passed). Falls back to NGN when nothing else
     * resolves — matches the pre-currency codebase's implicit default.
     */
    public static function resolve(Currency|Property|null $hint = null): Currency
    {
        if ($hint instanceof Currency) return $hint;
        if ($hint instanceof Property) {
            return self::propertyCurrency($hint);
        }
        $current = app(PropertyContext::class)->current();
        if ($current instanceof Property) {
            return self::propertyCurrency($current);
        }
        return Currency::NGN;
    }

    private static function propertyCurrency(Property $property): Currency
    {
        $code = $property->currency_code ?? null;
        return Currency::tryFromCode(is_string($code) ? $code : null)
            ?? Currency::NGN;
    }
}
