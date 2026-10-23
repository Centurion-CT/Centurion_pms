<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Single source for the ways a host takes money at the desk, shared by
 * the web dashboard and the host app (docs/host-app/PHASE-2.md).
 *
 *  - Cash, Transfer, Card, Pos, Other: the desk methods, always offered.
 *  - Wallet: only offered when the guest's customer wallet holds a
 *    balance (see {@see self::takeOptions()}).
 *  - Paystack: a REFUND method only. Never offered for taking money.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Card = 'card';
    case Pos = 'pos';
    case Other = 'other';
    case Wallet = 'wallet';
    case Paystack = 'paystack';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Transfer => 'Bank transfer',
            self::Card => 'Card',
            self::Pos => 'POS terminal',
            self::Other => 'Other',
            self::Wallet => 'Wallet',
            self::Paystack => 'Paystack',
        };
    }

    /**
     * The desk methods (no wallet, no paystack).
     *
     * @return list<self>
     */
    public static function desk(): array
    {
        return [self::Cash, self::Transfer, self::Card, self::Pos, self::Other];
    }

    /**
     * @return list<string>
     */
    public static function deskValues(): array
    {
        return array_map(fn (self $m) => $m->value, self::desk());
    }

    /**
     * Values accepted when taking money: the desk methods plus wallet.
     *
     * @return list<string>
     */
    public static function takeValues(): array
    {
        return [...self::deskValues(), self::Wallet->value];
    }

    /**
     * Options for a "take payment" picker. Wallet is appended only when
     * the guest has a positive wallet balance.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function takeOptions(int $walletBalance = 0): array
    {
        $methods = self::desk();
        if ($walletBalance > 0) {
            $methods[] = self::Wallet;
        }

        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], $methods);
    }

    /**
     * Methods a refund can go out through (web Refunds page + host app
     * held-money decisions). Paystack is refund-only.
     *
     * @return list<self>
     */
    public static function refund(): array
    {
        return [self::Cash, self::Transfer, self::Card, self::Paystack, self::Other];
    }

    /**
     * @return list<string>
     */
    public static function refundValues(): array
    {
        return array_map(fn (self $m) => $m->value, self::refund());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function refundOptions(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::refund());
    }

    public static function labelFor(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::tryFrom($value)?->label() ?? ucfirst(str_replace('_', ' ', $value));
    }
}
