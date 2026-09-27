<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Single-use handoff nonce. Written by IdentityHandoffController on the
 * central domain, consumed by TenantSsoConsumeController on the tenant
 * subdomain. `consumed_at` flips from null to now() on consume; a
 * second attempt on the same nonce is rejected as replay.
 *
 * Does NOT use BelongsToTenant — the row is created before we know the
 * consumer's tenant context (we know only the *target* tenant, stored
 * on the row itself).
 *
 * @property int $id
 * @property string $nonce
 * @property int $identity_account_id
 * @property int $tenant_id
 * @property int $operator_id
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $consumed_ip
 */
class SsoHandoff extends Model
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

    /**
     * @return BelongsTo<IdentityAccount, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(IdentityAccount::class, 'identity_account_id');
    }
}
