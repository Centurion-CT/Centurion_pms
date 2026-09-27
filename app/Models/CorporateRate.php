<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorporateRateScope;
use App\Enums\CorporateRateType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $corporate_id
 * @property CorporateRateScope $scope
 * @property int|null $scope_id
 * @property CorporateRateType $rate_type
 * @property int $rate_value
 * @property int|null $min_nights
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $notes
 */
class CorporateRate extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope'      => CorporateRateScope::class,
            'rate_type'  => CorporateRateType::class,
            'rate_value' => 'int',
            'min_nights' => 'int',
            'starts_at'  => 'datetime',
            'ends_at'    => 'datetime',
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
     * Whether this rate is currently active given an optional reference
     * timestamp.
     */
    public function isActive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<CorporateRate>  $query
     * @return Builder<CorporateRate>
     */
    public function scopeActiveAt(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query
            ->where(function ($q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }
}
