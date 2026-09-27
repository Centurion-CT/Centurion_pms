<?php

declare(strict_types=1);

namespace App\Services\Corporates;

use App\Enums\CorporateMemberStatus;
use App\Models\Corporate;
use App\Models\CorporateDomain;
use App\Models\CorporateMember;

/**
 * Map a booker's email address to the corporate context (if any).
 *
 * Resolution order:
 *  1. Exact match against `corporate_members.email` (status == Active)
 *  2. Verified domain match against `corporate_domains.domain`
 *
 * Returns `null` when the email doesn't belong to any corporate — at
 * which point the booking proceeds as a normal walk-in flow.
 */
class CorporateResolver
{
    /**
     * @return array{corporate: Corporate, member: ?CorporateMember}|null
     */
    public function resolve(?string $email): ?array
    {
        if ($email === null) {
            return null;
        }

        $normalised = strtolower(trim($email));
        if ($normalised === '' || ! str_contains($normalised, '@')) {
            return null;
        }

        // 1. Explicit member match. Members beat domains so an offboarded
        //    person at an otherwise-corporate domain stays off.
        $member = CorporateMember::query()
            ->with('corporate')
            ->where('email', $normalised)
            ->where('status', CorporateMemberStatus::Active->value)
            ->first();

        if ($member !== null && $member->corporate !== null && $member->corporate->status->isLive()) {
            return ['corporate' => $member->corporate, 'member' => $member];
        }

        // 2. Domain fallback — only matches verified domains, and the
        //    corporate itself must be Active.
        $host = substr($normalised, strpos($normalised, '@') + 1);
        if ($host === '' || $host === false) {
            return null;
        }

        $domain = CorporateDomain::query()
            ->with('corporate')
            ->where('domain', $host)
            ->whereNotNull('verified_at')
            ->first();

        if ($domain !== null && $domain->corporate !== null && $domain->corporate->status->isLive()) {
            return ['corporate' => $domain->corporate, 'member' => null];
        }

        return null;
    }

    /**
     * Match a booker's phone to an Active corporate member. Front-desk
     * often takes a walk-in with only a phone number in hand; the corp
     * roster is trust-signal enough to link them without an email.
     *
     * We strip everything but digits on both sides so "+2348011111111",
     * "234 801 111 1111", and "08011111111" all collide. The compare
     * key is the last 9 digits (Nigerian mobile subscriber number sans
     * country/network prefix) — Nigeria dominates the current market
     * and this is the length that varies the least across formats.
     *
     * Returns null when the phone doesn't map to any Active member.
     *
     * @return array{corporate: Corporate, member: CorporateMember}|null
     */
    public function resolveByPhone(?string $phone): ?array
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === null || strlen($digits) < 6) {
            return null;
        }
        $tail = substr($digits, -9);   // subscriber-number tail
        $tailLen = strlen($tail);

        $member = CorporateMember::query()
            ->with('corporate')
            ->whereNotNull('phone')
            ->where('status', CorporateMemberStatus::Active->value)
            // Postgres + SQLite: RIGHT()/SUBSTR the stored digits and
            // compare. We build the digit-stripped comparison inside
            // the query so the DB does the work — but SQLite doesn't
            // have RIGHT(), so hydrate + filter in PHP for correctness
            // across both engines. Query still lands ≤N rows since
            // active members are typically small.
            ->get()
            ->first(function (CorporateMember $m) use ($tail, $tailLen): bool {
                $memberDigits = preg_replace('/\D+/', '', (string) $m->phone);
                if ($memberDigits === null || strlen($memberDigits) < $tailLen) {
                    return false;
                }
                return substr($memberDigits, -$tailLen) === $tail;
            });

        if ($member === null || $member->corporate === null || ! $member->corporate->status->isLive()) {
            return null;
        }
        return ['corporate' => $member->corporate, 'member' => $member];
    }
}
