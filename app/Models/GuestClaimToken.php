<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ledger row for a single magic-link claim invite, from Keverna's
 * cross-property guest accounts. Centurion no longer sends the invite
 * (its /claim page was never routed here), so nothing writes these now.
 *
 * Not scoped to a tenant — the token is issued on the central domain
 * and its purpose is precisely to cross tenants when it resolves.
 *
 * @property int $id
 * @property string $nonce
 * @property string $email
 * @property int|null $origin_tenant_id
 * @property int|null $booking_id
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $consumed_ip
 * @property int|null $resolved_identity_account_id
 */
class GuestClaimToken extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
