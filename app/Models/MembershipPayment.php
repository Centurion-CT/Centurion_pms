<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property string $reference
 * @property int $amount
 * @property string $method
 * @property string $status
 * @property string $kind
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string|null $note
 * @property int|null $collected_by_operator_id
 * @property Carbon $paid_at
 */
class MembershipPayment extends Model
{
    use BelongsToTenant;

    public const string KIND_INITIAL = 'initial';

    public const string KIND_RENEWAL = 'renewal';

    public const string KIND_EXTENSION = 'extension';

    public const string KIND_OTHER = 'other';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_REFUNDED = 'refunded';

    protected $table = 'membership_payments';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount'       => 'int',
            'period_start' => 'date',
            'period_end'   => 'date',
            'paid_at'      => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MembershipSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MembershipSubscription::class, 'subscription_id');
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'collected_by_operator_id');
    }
}
