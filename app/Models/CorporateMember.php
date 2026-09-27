<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CorporateMemberRole;
use App\Enums\CorporateMemberStatus;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $corporate_id
 * @property int|null $customer_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $employee_id
 * @property CorporateMemberRole $role
 * @property CorporateMemberStatus $status
 * @property int|null $daily_cap_naira
 * @property int|null $monthly_cap_naira
 * @property Carbon|null $invited_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $offboarded_at
 * @property string|null $notes
 */
class CorporateMember extends Authenticatable implements CanResetPasswordContract
{
    use Notifiable;
    use SoftDeletes;

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
            'role'           => CorporateMemberRole::class,
            'status'         => CorporateMemberStatus::class,
            'invited_at'     => 'datetime',
            'accepted_at'    => 'datetime',
            'offboarded_at'  => 'datetime',
            'last_login_at'  => 'datetime',
            'password'       => 'hashed',
        ];
    }

    public static function booted(): void
    {
        static::saving(function (self $row): void {
            $row->email = strtolower(trim($row->email));
        });
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @param  Builder<CorporateMember>  $query
     * @return Builder<CorporateMember>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CorporateMemberStatus::Active->value);
    }
}
