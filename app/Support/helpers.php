<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Models\Property;
use App\Support\Money;

if (! function_exists('money')) {
    /**
     * Format an integer amount in the resolved currency. Templates
     * that used to hard-code ₦ now call `{{ money(43_000) }}` and
     * pick up whatever currency the operator's picked branch runs in.
     * Pass `$currency` to pin the render (e.g. an invoice PDF that
     * needs to lock the currency the invoice was raised in).
     */
    function money(int|float|null $amount, Currency|Property|null $currency = null, bool $suffix = false): string
    {
        return Money::format($amount, $currency, $suffix);
    }
}

if (! function_exists('currency_symbol')) {
    /**
     * The currently-resolved symbol on its own — for placeholders,
     * labels, and cells where the amount is rendered separately.
     */
    function currency_symbol(Currency|Property|null $currency = null): string
    {
        return Money::resolve($currency)->symbol();
    }
}
