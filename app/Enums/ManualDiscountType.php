<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the front-desk operator entered the ad-hoc discount at
 * manual-reservation time. Two shapes:
 *
 *   - {@see self::Percent}: 0-100 with fractional support (e.g. 7.5).
 *     CreateBooking resolves it into ₦ off = percent × post-auto-discount total.
 *   - {@see self::Fixed}:   ₦ amount off, taken verbatim and capped at total.
 *
 * The Booking table denormalises both `manual_discount_percent` and
 * `manual_discount_amount` regardless of type — reports never need to
 * branch on the type field to sum the ₦ off.
 */
enum ManualDiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
