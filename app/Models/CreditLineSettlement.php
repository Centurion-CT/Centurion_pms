<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $credit_line_id
 * @property int $amount
 * @property string $method
 * @property string|null $reference_note
 * @property int|null $recorded_by_operator_id
 * @property \Illuminate\Support\Carbon $settled_at
 */
class CreditLineSettlement extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CreditLine, $this>
     */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(CreditLine::class);
    }
}
