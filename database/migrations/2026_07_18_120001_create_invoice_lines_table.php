<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single line on a guest invoice. Snapshot columns (description,
 * unit_price, line_total) capture what was printed on the invoice —
 * a later BookingCharge edit / Room rate change must not rewrite
 * issued invoices.
 *
 * `source_type` + `source_id` softly reference the row this line was
 * synthesised from (BookingCharge, BookingPackage) so the UI can
 * disable duplicate-add and staff can trace back. Custom lines have
 * both NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            // night / charge / package / custom (App\Enums\InvoiceLineType).
            $table->string('line_type', 16);

            // Soft reference back to the source row (BookingCharge etc.).
            // NOT a FK — the source can be deleted without cascading to
            // the (immutable) invoice line.
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description', 240);
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('line_total');

            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
