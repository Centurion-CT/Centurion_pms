<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-corporate wallet. Reads of `balance_naira` and `held_naira` give
 * the truth; available balance is derived (`balance - held`).
 *
 * All mutations go through {@see \App\Services\Corporates\WalletService}
 * to guarantee atomic balance + ledger updates.
 *
 * @property int $id
 * @property int $corporate_id
 * @property int $balance_naira
 * @property int $held_naira
 * @property int|null $low_balance_threshold_naira
 */
class CorporateWallet extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return HasMany<CorporateWalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CorporateWalletTransaction::class);
    }

    public function available(): int
    {
        return max(0, (int) $this->balance_naira - (int) $this->held_naira);
    }
}
