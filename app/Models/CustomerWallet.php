<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use App\Services\Customers\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-tenant guest wallet. `balance_naira` is the truth; every
 * mutation goes through {@see WalletService}
 * so the ledger in {@see CustomerWalletTransaction} reconstructs it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $balance_naira
 * @property-read Customer $customer
 */
class CustomerWallet extends Model
{
    use BelongsToTenant;
    use \App\Multitenancy\BelongsToProperty;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<CustomerWalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerWalletTransaction::class)->orderByDesc('id');
    }
}
