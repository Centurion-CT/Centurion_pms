<?php

declare(strict_types=1);

namespace App\Services\Paystack;

use App\Models\Tenant;

/**
 * Which Paystack account takes a business's online payments, the same
 * order InitiateBookingPayment has always used:
 *
 *   1. A subaccount: the platform key, split into the business's
 *      subaccount.
 *   2. The business's own secret key.
 *   3. Neither: the platform key with no split, paid out to the business
 *      by hand. `isFallback()` says so.
 *
 * Starting a payment and checking it must use the same account, so both
 * sides ask here.
 */
final class BusinessPaystack
{
    public static function client(Tenant $tenant): PaystackClient
    {
        $key = (string) ($tenant->paystack_secret_key ?? '');

        return self::subaccount($tenant) === null && $key !== ''
            ? PaystackClient::forTenantKey($key)
            : PaystackClient::forPlatform();
    }

    public static function subaccount(Tenant $tenant): ?string
    {
        $code = (string) ($tenant->paystack_subaccount_code ?? '');

        return $code === '' ? null : $code;
    }

    public static function isFallback(Tenant $tenant): bool
    {
        return self::subaccount($tenant) === null && (string) ($tenant->paystack_secret_key ?? '') === '';
    }
}
