<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Controllers\Mobile\DeskController;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

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
 * @property string $platform 'ios' | 'android'
 * @property string $device_name 'Chidi's iPhone 15'
 * @property string $app_version '1.0.0+42'
 * @property string|null $push_token
 * @property string|null $app 'host' | 'staff' | 'desk' | null (legacy)
 * @property int|null $property_id desk only: the branch the desk sits in
 * @property array<string, string>|null $desk_unlocks desk only: {operator_id: ISO last unlock}
 * @property string|null $push_platform 'ios' | 'android'
 * @property array<string, bool>|null $push_prefs
 * @property Carbon|null $push_token_updated_at
 * @property string|null $paired_ip
 * @property Carbon $paired_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_operator_id
 * @property-read Operator $operator
 * @property-read Operator|null $revokedBy
 * @property-read Tenant $tenant
 * @property-read Property|null $property
 */
class MobileDevice extends Model
{
    use BelongsToTenant;

    public const APP_HOST = 'host';

    public const APP_STAFF = 'staff';

    /**
     * Keverna Desk: a shared reception PC (docs/desk-app/PHASE-1.md).
     * Its own `mobile-device-{id}` token can only list the desk's
     * operators and unlock them; every action runs on a per-operator
     * `desk-operator-{id}` token issued by {@see DeskController::unlock()}.
     */
    public const APP_DESK = 'desk';

    public const DESK_OPERATOR_TOKEN_PREFIX = 'desk-operator-';

    protected static function booted(): void
    {
        // Revoking a desk (web Staff > Devices, or logout) kills every
        // operator token unlocked on it. The middleware refuses them
        // anyway once the device is revoked; this removes the rows.
        static::saved(function (MobileDevice $device): void {
            if ($device->wasChanged('revoked_at') && $device->revoked_at !== null) {
                $device->revokeDeskOperatorTokens();
            }
        });
    }

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
            'push_prefs' => 'array',
            'push_token_updated_at' => 'datetime',
            'desk_unlocks' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function isDesk(): bool
    {
        return $this->app === self::APP_DESK;
    }

    public function deskOperatorTokenName(): string
    {
        return self::DESK_OPERATOR_TOKEN_PREFIX.$this->id;
    }

    /**
     * Deletes operator tokens issued on this desk: all of them, or
     * only the given operator's.
     */
    public function revokeDeskOperatorTokens(?int $operatorId = null): int
    {
        return PersonalAccessToken::query()
            ->where('name', $this->deskOperatorTokenName())
            ->where('tokenable_type', (new Operator)->getMorphClass())
            ->when($operatorId !== null, fn ($q) => $q->where('tokenable_id', $operatorId))
            ->delete();
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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
