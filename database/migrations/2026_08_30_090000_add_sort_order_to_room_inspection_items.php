<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `room_inspection_items` was created without a sort_order column even
 * though the parent `housekeeping_checklist_items` has one and the
 * housekeeping UI (Livewire\Dashboard\Housekeeping\Index) sorts by it.
 * That was a schema-vs-code drift shipped in the original create
 * migration; on Postgres it 500s the /dashboard/housekeeping page.
 *
 * Adds the column, backfills existing rows from the linked template
 * item, and drops it cleanly on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_inspection_items', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('label');
            $table->index(['room_inspection_id', 'sort_order'], 'rii_inspection_order_idx');
        });

        // Backfill: for every existing snapshot row, copy sort_order
        // from the template item it was snapshotted from. Falls back
        // to the row's own id (natural creation order) when the
        // template item is gone. Portable SQL — no CTE / driver-
        // specific joins in UPDATE.
        DB::table('room_inspection_items')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $templateOrder = null;
                if ($row->housekeeping_checklist_item_id !== null) {
                    $templateOrder = DB::table('housekeeping_checklist_items')
                        ->where('id', $row->housekeeping_checklist_item_id)
                        ->value('sort_order');
                }
                DB::table('room_inspection_items')
                    ->where('id', $row->id)
                    ->update(['sort_order' => (int) ($templateOrder ?? $row->id)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('room_inspection_items', function (Blueprint $table): void {
            $table->dropIndex('rii_inspection_order_idx');
            $table->dropColumn('sort_order');
        });
    }
};
