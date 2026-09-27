<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Flutter app install linked to an operator. See the migration for
 * the pairing flow — this model is the "who has the app installed and
 * what phone are they on" record. The Sanctum token itself is a
 * separate row (personal_access_tokens) tied to the operator; this
 * one carries the device metadata + push token + revocation state.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $operator_id
 * @property string $platform            'ios' | 'android'
 * @property string $device_name         'Chidi's iPhone 15'
 * @property string $app_version         '1.0.0+42'
 * @property string|null $push_token
 * @property string|null $paired_ip
 * @property Carbon $paired_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_operator_id
 * @property-read Operator $operator
 * @property-read Operator|null $revokedBy
 * @property-read Tenant $tenant
 */
class MobileDevice extends Model
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

    public function isActive(): bool
    {
        return $this->revoked_at === null;
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
}
