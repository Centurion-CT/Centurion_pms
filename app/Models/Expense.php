<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $property_id     null = chain-level ("HQ") expense
 * @property int $expense_category_id
 * @property int $amount               naira, positive
 * @property string $description
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $incurred_on
 * @property string $paid_via          cash | transfer | card | pos | other
 * @property string|null $vendor
 * @property string|null $reference    invoice / receipt number
 * @property int|null $created_by_operator_id
 */
class Expense extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'incurred_on' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'created_by_operator_id');
    }
}
