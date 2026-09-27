<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Barcodes for products — UPC, EAN, Code-128, whatever the supplier
 * puts on the bottle. Nullable because plenty of hospitality inventory
 * (jollof rice, laundry service) never gets a barcode. Unique per
 * tenant so scanning the same barcode always resolves to the same
 * SKU.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('barcode', 64)->nullable()->after('sku');
            $table->unique(['tenant_id', 'barcode'], 'products_tenant_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_tenant_barcode_unique');
            $table->dropColumn('barcode');
        });
    }
};
