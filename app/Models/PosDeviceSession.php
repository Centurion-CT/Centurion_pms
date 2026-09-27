<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per paired POS phone. Bound to the operator who scanned the
 * QR on the desktop — the phone assumes their identity via the standard
 * `web` guard, and `EnsureLinkedDeviceActive` middleware verifies this
 * row on every request so a revoke takes effect within one hit.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $operator_id
 * @property string $token_hash
 * @property string $device_label
 * @property string|null $user_agent
 * @property string|null $paired_ip
 * @property Carbon $paired_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_operator_id
 * @property-read Operator $operator
 * @property-read Operator|null $revokedBy
 */
class PosDeviceSession extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'revoked_by_operator_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
