<?php

declare(strict_types=1);

namespace App\Mail\Inventory;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Daily digest to managers listing every stock-tracked product whose
 * on-hand quantity has fallen to or below its low_stock_threshold.
 *
 * Fires only when there IS something to report — no "everything's fine"
 * emails.
 *
 * @property list<array{outlet: string, name: string, sku: ?string, on_hand: int, threshold: int}> $items
 */
class LowStockDigestMail extends Mailable implements NotTenantAware, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{outlet: string, name: string, sku: ?string, on_hand: int, threshold: int}>  $items
     */
    public function __construct(
        public Tenant $tenant,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        $count = count($this->items);

        return new Envelope(
            subject: 'Low stock: '.$count.' '.($count === 1 ? 'product' : 'products').' need attention',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.inventory.low-stock-digest',
            with: [
                'tenant' => $this->tenant,
                'items'  => $this->items,
            ],
        );
    }
}
