<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One physical-count row per product per day — the "balance" column
 * of the daily stock sheet. Also stores the remark (auto-classified
 * variance flag: "balanced" / "1 flat" / "2 over" / free-text).
 *
 * Kept minimal: physical_qty + remark + optional note + who counted.
 * Everything else on the sheet (opening, supply_in, consumption,
 * total_naira) is computed on read from stock_movements + products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('count_date');
            $table->unsignedInteger('physical_qty');
            $table->string('remark', 40)->nullable();       // 'balanced', '1 flat', '2 over', or free-text
            $table->text('note')->nullable();
            $table->foreignId('operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'product_id', 'count_date']);
            $table->index(['tenant_id', 'count_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_counts');
    }
};
