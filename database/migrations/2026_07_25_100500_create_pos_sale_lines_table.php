<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Individual line items on a POS sale. `product_id` is nullable so
 * front-desk can add a freeform "Other — ₦N" line (a one-off charge,
 * damage, tip). `name` is snapshotted at commit time so a subsequent
 * product rename doesn't rewrite historical receipts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('subtotal');
            $table->timestamps();

            $table->index(['pos_sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_lines');
    }
};
