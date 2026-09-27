<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-tier stock model — a Product now belongs to EITHER an outlet
 * (regular POS item) OR the property's central store (store-side SKU
 * that will later be issued to outlets). Exactly one of outlet_id /
 * store_id is populated; the writer paths enforce it at the app layer.
 *
 * `outlet_id` is loosened from NOT NULL to nullable so store products
 * can exist. All existing rows keep outlet_id populated and stay
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Loosening the FK requires dropping and re-adding on
            // MySQL — safe on SQLite too (rebuild happens implicitly).
            $table->dropForeign(['outlet_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('outlet_id')->nullable()->change();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();

            $table->foreignId('store_id')->nullable()->after('outlet_id')
                ->constrained('stores')->cascadeOnDelete();

            $table->index(['store_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['store_id', 'active']);
            $table->dropConstrainedForeignId('store_id');
            $table->dropForeign(['outlet_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('outlet_id')->nullable(false)->change();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }
};
