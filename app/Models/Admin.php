<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Platform admin (PRD §8). Central — does NOT belong to a tenant. Authenticated
 * via the `admin` guard against the `admin_password_reset_tokens` broker.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class Admin extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory;

    use Notifiable;

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
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            // Two-factor secrets + recovery codes are encrypted at
            // rest so a leaked DB dump alone doesn't compromise the
            // TOTP seed. Recovery codes stored as a JSON array of
            // one-time strings; consumed codes are pulled from the
            // array on use.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * True when the admin has finished the 2FA enrolment flow (secret
     * generated + a first code confirmed). Used by the login middleware
     * to gate the TOTP challenge step + by the dangerous-action pages
     * (like tenant delete) to require an active 2FA account.
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    protected static function newFactory(): AdminFactory
    {
        return AdminFactory::new();
    }
}
