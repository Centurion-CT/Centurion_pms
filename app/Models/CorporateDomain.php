<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Email domain auto-link for a corporate. Lowercased on save.
 *
 * @property int $id
 * @property int $corporate_id
 * @property string $domain
 * @property Carbon|null $verified_at
 */
class CorporateDomain extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->domain = strtolower(trim($row->domain));
        });
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }
}
