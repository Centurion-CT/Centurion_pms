<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable audit row for one external content-extraction call.
 *
 * Written by content-import services after every Google Places or
 * Anthropic API call. Never updated after insert. The monthly cap
 * enforced by ImportBudget sums this table on `succeeded = true`.
 *
 * @property int $id
 * @property int|null $import_id
 * @property string $provider
 * @property string|null $model
 * @property string|null $source_url
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cache_read_tokens
 * @property int $cost_kobo
 * @property bool $succeeded
 * @property string|null $error
 * @property Carbon $ran_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiExtraction extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tokens_in'         => 'int',
            'tokens_out'        => 'int',
            'cache_read_tokens' => 'int',
            'cost_kobo'         => 'int',
            'succeeded'         => 'bool',
            'ran_at'            => 'datetime',
        ];
    }

    /** @return BelongsTo<PropertyContentImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(PropertyContentImport::class, 'import_id');
    }
}
