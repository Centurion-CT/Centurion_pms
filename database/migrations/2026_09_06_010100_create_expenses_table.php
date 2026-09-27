<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single money-out line — utilities bill, F&B supplies, repair
 * invoice, etc. Nullable property_id lets an operator log
 * chain-level ("HQ") expenses alongside per-branch ones; the
 * expenses list scopes to the currently-selected branch via
 * PropertyContext + shows null-property rows as "All branches"
 * so nothing hides from the reports.
 *
 * `amount` is naira (not kobo) to match every other money field
 * in Keverna. Positive integer only — refunds/credits are a
 * separate concern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('expense_category_id');
            $table->unsignedInteger('amount');
            $table->string('description', 240);
            $table->text('notes')->nullable();
            $table->date('incurred_on');
            $table->string('paid_via', 20)->default('cash'); // cash | transfer | card | pos | other
            $table->string('vendor', 120)->nullable();
            $table->string('reference', 60)->nullable(); // invoice / receipt number
            $table->unsignedBigInteger('created_by_operator_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'property_id']);
            $table->index(['tenant_id', 'incurred_on']);
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
