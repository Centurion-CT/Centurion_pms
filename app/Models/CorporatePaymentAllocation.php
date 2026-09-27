<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $corporate_payment_id
 * @property int $invoice_id
 * @property int $amount_naira
 */
class CorporatePaymentAllocation extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_naira' => 'int',
        ];
    }

    /**
     * @return BelongsTo<CorporatePayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CorporatePayment::class, 'corporate_payment_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
