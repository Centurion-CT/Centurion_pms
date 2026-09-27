<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Adds `client_key` to pos_sales so the offline-queue flush endpoint can
 * dedupe retries. If the phone POSTs a sale, the server writes it, but
 * the response never reaches the phone (kill-switch mid-flight), the
 * phone will retry the same queued payload after reconnect. Without
 * this column that produces a duplicate sale. With it, the flush
 * controller looks up the row on client_key and returns the existing
 * sale — exactly-once from the operator's perspective.
 *
 * Nullable so legacy sales + desktop kiosk sales (which don't use the
 * queue) stay valid. Unique per tenant so a client_key collision across
 * different tenants can't happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->string('client_key', 64)->nullable()->after('reference');
            $table->unique(['tenant_id', 'client_key'], 'pos_sales_tenant_client_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropUnique('pos_sales_tenant_client_key_unique');
            $table->dropColumn('client_key');
        });
    }
};
