<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $from_slug
 * @property int $to_tenant_id
 * @property Carbon $expires_at
 * @property-read Tenant $toTenant
 */
class SlugRedirect extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function toTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'to_tenant_id');
    }

    public function isExpired(): bool
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }
}
