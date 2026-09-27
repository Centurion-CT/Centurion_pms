<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the property runs its inventory.
 *
 *   StoreAndRequisition — Central Store holds the master catalog +
 *     stock. Outlets request from the store; store issues; outlet
 *     consumes. Two ledgers, one source of truth. Fits properties
 *     where a stock keeper owns receiving + fulfils department pulls.
 *
 *   PerDepartment — No central store. Each outlet / department
 *     (bar, kitchen, front desk, etc) owns and receives its own
 *     inventory directly. Fits smaller / less centralised setups
 *     where the department that consumes also does the ordering.
 */
enum StockModel: string
{
    case StoreAndRequisition = 'store_and_requisition';
    case PerDepartment = 'per_department';

    public function label(): string
    {
        return match ($this) {
            self::StoreAndRequisition => 'Central store + requisition',
            self::PerDepartment => 'Per-department inventory',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::StoreAndRequisition => 'A central store holds the master catalog. Departments request; the store fulfils.',
            self::PerDepartment => 'Each department (bar, kitchen, front desk…) owns and receives its own inventory directly. No central store.',
        };
    }

    /**
     * When true, the Store module is exposed in the sidebar / gated
     * behind role checks. Per-department mode drops it.
     */
    public function usesCentralStore(): bool
    {
        return $this === self::StoreAndRequisition;
    }
}
