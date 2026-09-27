<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot linking one central IdentityAccount to one per-tenant Operator.
 * Rows are unique on (identity_account_id, tenant_id) — an identity has
 * at most one operator seat per tenant.
 *
 * `last_login_at` is per-link so the account chooser can rank cards by
 * "which tenant this identity opened most recently" rather than the
 * identity's overall last login.
 *
 * @property int $id
 * @property int $identity_account_id
 * @property int $tenant_id
 * @property int $operator_id
 * @property Carbon|null $last_login_at
 * @property-read IdentityAccount $identity
 * @property-read Tenant $tenant
 * @property-read Operator $operator
 */
class IdentityOperatorLink extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IdentityAccount, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(IdentityAccount::class, 'identity_account_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The link carries its own tenant_id, so we intentionally bypass
     * TenantScope when resolving the linked Operator. Central-domain
     * flows (login, chooser) have no current tenant and must still be
     * able to read the operator side of the link.
     *
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        $relation = $this->belongsTo(Operator::class);
        $relation->getQuery()->withoutGlobalScope(TenantScope::class);

        return $relation;
    }
}
