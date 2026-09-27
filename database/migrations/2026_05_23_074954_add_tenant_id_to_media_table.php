<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie's media table is morphic on (model_type, model_id). Adding tenant_id
 * gives us:
 *  - The TenantPathGenerator can stamp `tenant_{id}/` into R2 paths without
 *    re-resolving the morphed parent on every URL build.
 *  - A direct index for "delete all media for tenant X" cleanup jobs.
 *  - A nullable FK so deleting the tenant cascades any stray media rows
 *    whose parent was already removed.
 *
 * Auto-populated by App\Models\Media on creating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->index(['tenant_id', 'collection_name'], 'media_tenant_collection_idx');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_tenant_collection_idx');
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
