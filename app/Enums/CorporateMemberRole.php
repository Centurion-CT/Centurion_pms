<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * In-corporate role for a member. Admins manage their own roster +
 * top up the wallet from the self-service portal; members just book.
 */
enum CorporateMemberRole: string
{
    case Admin  = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Admin  => 'Corporate admin',
            self::Member => 'Member',
        };
    }
}
