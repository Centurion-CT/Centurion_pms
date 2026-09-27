<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A partial-refund event against a completed POS sale — one or more
 * lines returned by the guest, stock put back, money refunded.
 *
 * Void is for reversing the whole sale (something went catastrophically
 * wrong); this is for "he sent one of the four drinks back". Multiple
 * returns can happen against the same sale as long as the outstanding
 * quantity on each line remains non-negative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->unsignedInteger('refund_amount');
            $table->string('refund_method', 20);    // cash | transfer | card | room | store_credit
            $table->string('reason', 240);
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['pos_sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_returns');
    }
};
