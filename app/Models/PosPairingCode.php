<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ephemeral bridge between the desktop QR and the phone's pairing
 * request. The plaintext nonce lives in the QR (and in the URL the
 * phone opens); only the sha256 is stored server-side. Rows are
 * single-use — once `claimed_at` is set the code is dead.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $operator_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property int|null $device_session_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property-read Operator $operator
 * @property-read PosDeviceSession|null $deviceSession
 */
class PosPairingCode extends Model
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
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
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
     * @return BelongsTo<PosDeviceSession, $this>
     */
    public function deviceSession(): BelongsTo
    {
        return $this->belongsTo(PosDeviceSession::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }
}
