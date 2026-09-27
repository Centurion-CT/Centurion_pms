<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceLineType;
use App\Multitenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $invoice_id
 * @property InvoiceLineType $line_type
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price
 * @property int $line_total
 * @property-read Invoice $invoice
 */
class InvoiceLine extends Model
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
            'line_type'  => InvoiceLineType::class,
            'quantity'   => 'int',
            'unit_price' => 'int',
            'line_total' => 'int',
            'source_id'  => 'int',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
