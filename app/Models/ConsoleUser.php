<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsoleRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Someone who may use the platform owner's console. Not a business's
 * staff member and not scoped to any business.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property ConsoleRole $role
 * @property int|null $invited_by_id
 * @property string|null $invite_token_hash
 * @property Carbon|null $invite_expires_at
 * @property Carbon|null $last_signed_in_at
 */
class ConsoleUser extends Model
{
    public const INVITE_DAYS = 3;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['password', 'invite_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => ConsoleRole::class,
            'invite_expires_at' => 'datetime',
            'last_signed_in_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ConsoleUser, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_id');
    }

    public function isOwner(): bool
    {
        return $this->role === ConsoleRole::Owner;
    }

    /** True once the user has a password and so can sign in. */
    public function isActive(): bool
    {
        return $this->password !== null;
    }

    /**
     * Start a fresh invite (or password reset): clears any password and
     * returns the plain token for the link. The token is stored hashed.
     */
    public function issueInvite(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'password' => null,
            'invite_token_hash' => hash('sha256', $token),
            'invite_expires_at' => Carbon::now()->addDays(self::INVITE_DAYS),
        ])->save();

        return $token;
    }

    public static function forInvite(string $token): ?self
    {
        return self::query()
            ->where('invite_token_hash', hash('sha256', $token))
            ->where('invite_expires_at', '>', Carbon::now())
            ->first();
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
