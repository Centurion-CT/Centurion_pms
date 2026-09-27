<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $corporate_wallet_id
 * @property int $corporate_id
 * @property int|null $corporate_member_id
 * @property WalletTransactionType $type
 * @property int $amount_naira
 * @property int $balance_after_naira
 * @property int $held_after_naira
 * @property string|null $description
 * @property array<string,mixed>|null $meta
 * @property string|null $paystack_reference
 * @property string|null $causer_type
 * @property int|null $causer_id
 */
class CorporateWalletTransaction extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CorporateWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CorporateWallet::class, 'corporate_wallet_id');
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<CorporateMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(CorporateMember::class, 'corporate_member_id');
    }

    /**
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }
}
