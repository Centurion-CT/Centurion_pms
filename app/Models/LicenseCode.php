<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property string|null $issued_to_email
 * @property string|null $issued_to_name
 * @property int|null $demo_request_id
 * @property string $status         unused | used | revoked | expired
 * @property int|null $used_by_operator_id
 * @property int|null $used_by_tenant_id
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $notes
 * @property int|null $issued_by_admin_id
 */
class LicenseCode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function demoRequest(): BelongsTo
    {
        return $this->belongsTo(DemoRequest::class);
    }

    /**
     * Fresh redeemable code. `KV-XXXX-XXXX-XXXX` shape - long enough
     * to be un-guessable, short enough to type from a WhatsApp message
     * without transcription pain.
     */
    public static function generateCode(): string
    {
        do {
            $code = 'KV-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4));
        } while (static::query()->where('code', $code)->exists());
        return $code;
    }

    /**
     * True when the code can be redeemed right now. Encapsulates every
     * reason a code might not work (already redeemed, revoked by an
     * admin, or past its expiry) so the register form has one call to
     * make.
     */
    public function isRedeemable(): bool
    {
        if ($this->status !== 'unused') return false;
        if ($this->used_at !== null)    return false;
        if ($this->expires_at !== null && $this->expires_at->isPast()) return false;
        return true;
    }
}
