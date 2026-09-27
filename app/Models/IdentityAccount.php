<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Central credential + routing hub for the unified-login spec
 * (docs/unified-login.md). Lives on the central domain and does NOT
 * use BelongsToTenant — an identity spans tenants via link tables.
 *
 * @property int $id
 * @property string $email
 * @property string|null $password
 * @property Carbon|null $email_verified_at
 * @property string $name
 * @property string|null $phone
 * @property Carbon|null $last_login_at
 * @property int|null $preferred_link_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IdentityOperatorLink> $operatorLinks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IdentityGuestLink> $guestLinks
 * @property-read IdentityOperatorLink|null $preferredLink
 */
class IdentityAccount extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, Notifiable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Normalise email at write time so unique lookups are collision-safe
     * regardless of source-row casing. Read-side lookups should do the
     * same (see IdentityAccount::findForLogin).
     */
    public static function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public static function findForLogin(string $email): ?self
    {
        return static::query()->where('email', self::normaliseEmail($email))->first();
    }

    /**
     * @return HasMany<IdentityOperatorLink, $this>
     */
    public function operatorLinks(): HasMany
    {
        return $this->hasMany(IdentityOperatorLink::class);
    }

    /**
     * @return HasMany<IdentityGuestLink, $this>
     */
    public function guestLinks(): HasMany
    {
        return $this->hasMany(IdentityGuestLink::class);
    }

    /**
     * @return BelongsTo<IdentityOperatorLink, $this>
     */
    public function preferredLink(): BelongsTo
    {
        return $this->belongsTo(IdentityOperatorLink::class, 'preferred_link_id');
    }
}
