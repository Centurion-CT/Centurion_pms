<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $corporate_id
 * @property int|null $recorded_by_operator_id
 * @property int $amount_naira
 * @property string $method
 * @property Carbon $paid_at
 * @property string|null $reference
 * @property string|null $note
 * @property string|null $receipt_path
 */
class CorporatePayment extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_naira' => 'int',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'recorded_by_operator_id');
    }

    /**
     * @return HasMany<CorporatePaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CorporatePaymentAllocation::class);
    }
}
