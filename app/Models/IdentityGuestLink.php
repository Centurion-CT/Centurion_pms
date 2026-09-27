<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pivot linking one central IdentityAccount to one per-tenant Customer.
 * Same shape as IdentityOperatorLink; the two live side-by-side because
 * the guest and operator sides of an identity have independent lifecycles
 * (a guest can claim a link years after their booking; an operator link
 * is created at backfill or invitation time).
 *
 * @property int $id
 * @property int $identity_account_id
 * @property int $tenant_id
 * @property int $customer_id
 * @property Carbon $claimed_at
 * @property-read IdentityAccount $identity
 * @property-read Tenant $tenant
 * @property-read Customer $customer
 */
class IdentityGuestLink extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
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
     * TenantScope when resolving the linked Customer. The central guest
     * dashboard has no current tenant and must still be able to read
     * the customer side of the link — same reasoning as the operator
     * link (see IdentityOperatorLink::operator).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        $relation = $this->belongsTo(Customer::class);
        $relation->getQuery()->withoutGlobalScope(TenantScope::class);

        return $relation;
    }
}
