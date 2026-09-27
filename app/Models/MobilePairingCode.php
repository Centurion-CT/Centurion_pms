<?php

declare(strict_types=1);

namespace App\Models;

use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Short-lived one-shot code used to bootstrap a Flutter app install.
 * Generated on the web dashboard while the operator is signed in, so
 * we know exactly who is claiming the phone. Displayed to the operator
 * as a QR (JSON payload: {base_url, code}) — mobile scans, POSTs, we
 * consume the code and hand back a Sanctum token.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $operator_id
 * @property string $code
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property int|null $consumed_by_mobile_device_id
 * @property-read Operator $operator
 * @property-read MobileDevice|null $device
 */
class MobilePairingCode extends Model
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
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Format: 8 chars, upper + digits, avoids look-alikes (0/O, 1/I).
     * Short enough for a QR to stay dense, long enough that a leaked
     * one-shot code isn't guessable within the 5-minute TTL.
     */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public function isSpent(): bool
    {
        return $this->consumed_at !== null
            || $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * @return BelongsTo<MobileDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'consumed_by_mobile_device_id');
    }
}
