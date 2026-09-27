<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * @property int $id
 * @property int $operator_id
 * @property VerificationChannel $channel
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $locked_until
 * @property Carbon|null $consumed_at
 * @property-read Operator $operator
 */
class VerificationCode extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => VerificationChannel::class,
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'locked_until' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function isExpired(): bool
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && Carbon::now()->lessThan($this->locked_until);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function matches(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }
}
