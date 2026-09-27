<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Line-level detail of a return event — which original sale line, how
 * many units came back, at what unit price. Snapshot-priced so refund
 * accounting stays honest even if the product's price later changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('subtotal');
            $table->timestamps();

            $table->index(['pos_sale_return_id']);
            $table->index(['pos_sale_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_return_lines');
    }
};
