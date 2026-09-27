<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $booking_id
 * @property int|null $customer_id
 * @property int|null $issued_by_operator_id
 * @property int|null $sequence
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property string|null $bank_name
 * @property string|null $bank_account_name
 * @property string|null $bank_account_number
 * @property string|null $notes
 * @property int $subtotal
 * @property int $amount_paid
 * @property int $total
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property-read Booking|null $booking
 * @property-read Customer|null $customer
 * @property-read Operator|null $issuedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InvoiceLine> $lines
 */
class Invoice extends Model
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
            'status'      => InvoiceStatus::class,
            'issued_at'   => 'datetime',
            'due_at'      => 'datetime',
            'paid_at'     => 'datetime',
            'voided_at'   => 'datetime',
            'subtotal'    => 'int',
            'amount_paid' => 'int',
            'total'       => 'int',
            'sequence'    => 'int',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Corporate, $this>
     */
    public function corporate(): BelongsTo
    {
        return $this->belongsTo(Corporate::class);
    }

    /**
     * @return BelongsTo<Operator, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'issued_by_operator_id');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
    }

    /**
     * Payments credited to this invoice — manual receipts front desk
     * booked via RecordInvoicePayment, plus any Paystack rows an ops
     * script later attributed here. Ordered newest first for the show
     * page's "Payments" panel.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('id');
    }

    /**
     * Balance the guest still owes on this invoice. Never negative —
     * an overpayment reads as zero here; the surplus is on the parent
     * booking's ledger, not the invoice.
     */
    public function balanceOwed(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }
}
