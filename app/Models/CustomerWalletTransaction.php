<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerWalletTransactionType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_wallet_id
 * @property int $customer_id
 * @property CustomerWalletTransactionType $type
 * @property int $amount_naira
 * @property int $balance_after_naira
 * @property string|null $description
 * @property array<string,mixed>|null $meta
 * @property int|null $booking_id
 * @property int|null $pos_sale_id
 * @property int|null $booking_charge_id
 * @property int|null $payment_id
 * @property string|null $payment_method
 * @property string|null $reference_note
 * @property int|null $approved_by_operator_id
 * @property string|null $causer_type
 * @property int|null $causer_id
 * @property Carbon $created_at
 */
class CustomerWalletTransaction extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerWalletTransactionType::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CustomerWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CustomerWallet::class, 'customer_wallet_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<PosSale, $this>
     */
    public function posSale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /**
     * @return BelongsTo<BookingCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(BookingCharge::class, 'booking_charge_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'approved_by_operator_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Human-readable subtype pulled from meta.source (falls back to the
     * enum label). Wallet UI uses this for the row badge.
     */
    public function subtypeLabel(): string
    {
        $source = is_array($this->meta) ? ($this->meta['source'] ?? null) : null;

        return match ($source) {
            'cash_topup' => 'Cash top-up',
            'overpayment_at_checkin' => 'Overpayment credit',
            'overstay_auto' => 'Overstay auto-deduct',
            'balance_due_auto' => 'Balance-due auto-deduct',
            'pay_booking' => 'Booking payment',
            'pay_pos_sale' => 'POS purchase',
            'settle_charges' => 'Charge settlement',
            'cash_refund' => 'Cash refund',
            'admin_reconcile' => 'Admin reconciliation',
            default => $this->type->label(),
        };
    }
}
