<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Multitenancy\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $invoice_id
 * @property int|null $pos_sale_id
 * @property string $paystack_reference
 * @property int $amount
 * @property PaymentType $type
 * @property string|null $method
 * @property int|null $collected_by_operator_id
 * @property string|null $reference_note
 * @property PaymentStatus $status
 * @property Carbon|null $paid_at
 * @property-read Tenant $tenant
 * @property-read Booking $booking
 * @property-read Invoice|null $invoice
 */
class Payment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'membership_period_start' => 'date',
            'membership_period_end' => 'date',
        ];
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The invoice this payment settles (nullable — Paystack payments
     * pre-dating the invoice feature have no linked invoice).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The POS sale this payment settles (nullable — every non-POS
     * payment leaves it null).
     *
     * @return BelongsTo<PosSale, $this>
     */
    public function posSale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /**
     * The membership subscription this payment covers (nullable —
     * set for gym / spa / snooker / etc. receipts).
     *
     * @return BelongsTo<MembershipSubscription, $this>
     */
    public function membershipSubscription(): BelongsTo
    {
        return $this->belongsTo(MembershipSubscription::class, 'membership_subscription_id');
    }

    /**
     * Corporate the payment landed against (nullable — set for
     * CorporateAr type rows, mirroring a CorporatePayment).
     *
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * Canonical CorporatePayment source-of-truth this Payment mirrors.
     *
     * @return BelongsTo<CorporatePayment, $this>
     */
    public function corporatePayment(): BelongsTo
    {
        return $this->belongsTo(CorporatePayment::class);
    }

    /**
     * Front-desk operator who took the cash / swiped the card. Null
     * for Paystack payments (no human collector) and historical rows
     * that pre-date the collected_by_operator_id column.
     *
     * @return BelongsTo<Operator, $this>
     */
    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'collected_by_operator_id');
    }
}
