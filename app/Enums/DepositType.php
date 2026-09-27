<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a Room's "Place on Hold" deposit (PRD §6.4.3) is calculated:
 *  - None:    no hold deposit allowed; guest must pay in full
 *  - Fixed:   `deposit_value` is an NGN amount applied to every booking
 *  - Percent: `deposit_value` (0–100) is a % of the booking subtotal
 */
enum DepositType: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percent = 'percent';
}
