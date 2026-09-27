<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recipient of the periodic Summary PDF reports.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property bool $weekly
 * @property bool $monthly
 * @property int|null $created_by_operator_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SummaryReportSubscriber extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'email', 'weekly', 'monthly', 'created_by_operator_id',
    ];

    protected function casts(): array
    {
        return [
            'weekly' => 'bool',
            'monthly' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'created_by_operator_id');
    }
}
