<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Damage lines on a room inspection now carry an explicit resolution
 * marker. When damage_note is set + resolved_at is null, the item is
 * "still broken" — it drives the property-wide Needs replacement list.
 * Once a manager fixes / swaps the item, they mark it resolved and it
 * drops off the outstanding queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_inspection_items', function (Blueprint $table): void {
            $table->timestamp('resolved_at')->nullable()->after('damage_note');
            $table->foreignId('resolved_by_operator_id')->nullable()
                ->after('resolved_at')
                ->constrained('operators')->nullOnDelete();

            $table->index(['tenant_id', 'resolved_at'], 'rii_tenant_resolved_idx');
        });
    }

    public function down(): void
    {
        Schema::table('room_inspection_items', function (Blueprint $table): void {
            $table->dropForeign(['resolved_by_operator_id']);
            $table->dropIndex('rii_tenant_resolved_idx');
            $table->dropColumn(['resolved_by_operator_id', 'resolved_at']);
        });
    }
};
