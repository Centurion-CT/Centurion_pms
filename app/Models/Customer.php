<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerLifecycle;
use App\Multitenancy\BelongsToTenant;
use App\Services\Customers\WalletService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * CRM identity that survives across bookings.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string|null $notes
 * @property array<int, string>|null $tags
 * @property CustomerLifecycle $lifecycle
 * @property bool $is_vip
 * @property bool $marketing_opt_in
 * @property int $total_bookings
 * @property int $lifetime_value
 * @property Carbon|null $first_booking_at
 * @property Carbon|null $last_booking_at
 * @property-read Collection<int, Booking> $bookings
 * @property-read Collection<int, CustomerInteraction> $interactions
 */
class Customer extends Model
{
    use BelongsToTenant;
    use \App\Multitenancy\BelongsToProperty;
    use SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle' => CustomerLifecycle::class,
            'is_vip' => 'bool',
            'marketing_opt_in' => 'bool',
            'tags' => 'array',
            'first_booking_at' => 'datetime',
            'last_booking_at' => 'datetime',
            'total_bookings' => 'int',
            'lifetime_value' => 'int',
        ];
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->orderByDesc('checkin_date');
    }

    /**
     * @return HasMany<CustomerInteraction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(CustomerInteraction::class)->orderByDesc('happened_at');
    }

    /**
     * The guest's spendable wallet at this property. Created lazily by
     * {@see WalletService::ensureWallet()} — the
     * relation returns null for guests who have never had money on file.
     *
     * @return HasOne<CustomerWallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(CustomerWallet::class);
    }

    /**
     * @return HasMany<CustomerWalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(CustomerWalletTransaction::class)->orderByDesc('id');
    }

    /**
     * Live wallet balance — zero if no wallet row exists yet.
     */
    public function walletBalance(): int
    {
        return (int) ($this->wallet?->balance_naira ?? 0);
    }

    /**
     * True when this guest has already set a 4-digit PIN for the
     * given property — drives the QR portal's "set new PIN" vs
     * "enter existing PIN" branch.
     */
    public function hasPinFor(Property $property): bool
    {
        return \Illuminate\Support\Facades\DB::table('guest_room_pins')
            ->where('customer_id', $this->getKey())
            ->where('property_id', $property->getKey())
            ->exists();
    }

    /**
     * Bcrypt-hash + store a new 4-digit PIN for this guest at the
     * given property. Overwrites any prior PIN — a guest who forgot
     * theirs can reset via operator help (out of MVP scope).
     */
    public function setPinFor(Property $property, string $pin): void
    {
        \Illuminate\Support\Facades\DB::table('guest_room_pins')->updateOrInsert(
            ['customer_id' => $this->getKey(), 'property_id' => $property->getKey()],
            [
                'pin_hash' => \Illuminate\Support\Facades\Hash::make($pin),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Constant-time check of a candidate PIN against the stored
     * hash. Returns false when there's no stored PIN — the caller
     * must have already gated for that via {@see hasPinFor()}.
     */
    public function verifyPinFor(Property $property, string $pin): bool
    {
        $row = \Illuminate\Support\Facades\DB::table('guest_room_pins')
            ->where('customer_id', $this->getKey())
            ->where('property_id', $property->getKey())
            ->first();
        if ($row === null) {
            return false;
        }
        return \Illuminate\Support\Facades\Hash::check($pin, $row->pin_hash);
    }

    /**
     * Filter customers by lifecycle bucket.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeInLifecycle(Builder $query, CustomerLifecycle $lifecycle): Builder
    {
        return $query->where('lifecycle', $lifecycle->value);
    }

    /**
     * Filter customers carrying a particular tag (case-sensitive).
     * Works on MySQL 5.7+ and SQLite 3.38+.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Aggregate every distinct tag used by this tenant's customers.
     *
     * @return array<int, string>
     */
    public static function distinctTagsForTenant(int $tenantId): array
    {
        $rows = static::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('tags')
            ->pluck('tags');

        $all = [];
        foreach ($rows as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $all[$tag] = true;
                }
            }
        }
        $unique = array_keys($all);
        sort($unique);

        return $unique;
    }

    /**
     * Human-friendly identity line used in tables and timeline entries.
     */
    public function contactLine(): string
    {
        return collect([$this->email, $this->phone])->filter()->implode(' · ');
    }

    public function avatarInitials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = collect($parts)
            ->take(2)
            ->map(fn (string $p): string => mb_substr($p, 0, 1))
            ->implode('');

        return strtoupper($initials !== '' ? $initials : '?');
    }
}
