<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshapes housekeeping_checklists from "one per room category" into
 * "reusable bundle a manager can attach to many room categories."
 *
 * Before: checklist row carried room_category_id (1:1 via a unique
 *         index). Every category had at most one checklist; sharing
 *         items across categories meant duplicating them.
 *
 * After:  checklists have a name of their own + attach to categories
 *         through a pivot. A "Standard cleaning" bundle can serve
 *         every room type; a "Suite deep-clean" bundle can hang off
 *         just the suites.
 *
 * Backfill preserves current behaviour: every existing checklist
 * becomes a bundle named after its category, with a single pivot row
 * linking it to that category. Managers can rename and re-attach
 * from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add `name` (nullable — filled in by the backfill below,
        //    then locked to NOT NULL after every row has a value).
        Schema::table('housekeeping_checklists', function (Blueprint $table): void {
            $table->string('name', 120)->nullable()->after('tenant_id');
        });

        // 2. Backfill name from the linked category. Correlated
        //    subquery for portability — SQLite doesn't accept an
        //    UPDATE...JOIN shape, but SET ... = (SELECT ...) works
        //    on every driver we target.
        DB::statement(<<<'SQL'
            UPDATE housekeeping_checklists
            SET name = (
                SELECT name FROM room_categories
                WHERE room_categories.id = housekeeping_checklists.room_category_id
            )
            WHERE room_category_id IS NOT NULL
        SQL);

        // Also cover any rare rows without a category (shouldn't
        // exist, but be defensive against dirty prod data).
        DB::table('housekeeping_checklists')
            ->whereNull('name')
            ->update(['name' => 'Unnamed checklist']);

        // 3. Create the pivot BEFORE dropping room_category_id so we
        //    can backfill from the soon-to-be-dropped column. No
        //    tenant_id on the pivot itself — both sides (the
        //    checklist + the category) already carry one, and
        //    cross-tenant rows are impossible via the FK cascades.
        Schema::create('housekeeping_checklist_room_category', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('housekeeping_checklist_id')
                ->constrained('housekeeping_checklists')->cascadeOnDelete();
            $table->foreignId('room_category_id')
                ->constrained('room_categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['housekeeping_checklist_id', 'room_category_id'],
                'hkc_room_category_unique',
            );
        });

        // 4. Backfill the pivot from the existing 1:1 mapping.
        DB::statement(<<<'SQL'
            INSERT INTO housekeeping_checklist_room_category
                (housekeeping_checklist_id, room_category_id, created_at, updated_at)
            SELECT
                id,
                room_category_id,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM housekeeping_checklists
            WHERE room_category_id IS NOT NULL
        SQL);

        // 5. Now drop the old unique index + foreign key + column.
        //    Portable-ish: check driver for the FK drop shape.
        Schema::table('housekeeping_checklists', function (Blueprint $table): void {
            $table->dropUnique('hkc_tenant_category_unique');
        });
        Schema::table('housekeeping_checklists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('room_category_id');
        });

        // 6. Lock `name` in — every row has one now.
        Schema::table('housekeeping_checklists', function (Blueprint $table): void {
            $table->string('name', 120)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Best-effort rollback: put room_category_id back, backfill
        // from the first pivot row, drop the pivot. Loses information
        // when a checklist was attached to multiple categories.
        Schema::table('housekeeping_checklists', function (Blueprint $table): void {
            $table->foreignId('room_category_id')->nullable()->after('name')
                ->constrained()->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE housekeeping_checklists h
            SET room_category_id = (
                SELECT p.room_category_id
                FROM housekeeping_checklist_room_category p
                WHERE p.housekeeping_checklist_id = h.id
                ORDER BY p.id ASC
                LIMIT 1
            )
        SQL);

        Schema::table('housekeeping_checklists', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'room_category_id'], 'hkc_tenant_category_unique');
            $table->dropColumn('name');
        });

        Schema::dropIfExists('housekeeping_checklist_room_category');
    }
};
