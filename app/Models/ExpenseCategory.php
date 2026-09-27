<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $color        6-hex label colour (e.g. '#D97706')
 * @property string|null $description
 */
class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
