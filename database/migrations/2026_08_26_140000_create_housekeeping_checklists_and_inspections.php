<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping workflow — per-category checklist templates, one
 * inspection instance per checkout event, one item-result row per
 * checklist item on that inspection.
 *
 * Data shape:
 *   housekeeping_checklists            (1 per RoomCategory, active)
 *     housekeeping_checklist_items     (many, ordered)
 *
 *   room_inspections                   (1 per checkout event per room)
 *     room_inspection_items            (1 per checklist item, snapshotted)
 *
 * The item text is snapshotted onto room_inspection_items at open
 * time so editing the template later doesn't rewrite history on
 * a live inspection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained('room_categories')->cascadeOnDelete();
            // Owner can toggle a template off without deleting it so
            // historical inspections still reference the checklist row.
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'room_category_id'], 'hkc_tenant_category_unique');
        });

        Schema::create('housekeeping_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('housekeeping_checklist_id')
                ->constrained('housekeeping_checklists')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['housekeeping_checklist_id', 'sort_order'], 'hkci_list_order_idx');
        });

        Schema::create('room_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->nullable()
                ->constrained('room_categories')->nullOnDelete();
            $table->foreignId('housekeeping_checklist_id')->nullable()
                ->constrained('housekeeping_checklists')->nullOnDelete();
            // Which checkout kicked this inspection off (nullable so a
            // supervisor can open one manually later on).
            $table->foreignId('triggered_by_booking_id')->nullable()
                ->constrained('bookings')->nullOnDelete();

            // Lifecycle: pending → cleaning_done → porter_verified →
            // passed. `failed` is set when any signer rejects.
            $table->string('status', 32)->default('pending');

            // Per-role timestamps + causers, so the audit trail is
            // readable straight off the row without joining audit_logs.
            $table->timestamp('housekeeper_at')->nullable();
            $table->foreignId('housekeeper_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamp('porter_at')->nullable();
            $table->foreignId('porter_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamp('supervisor_at')->nullable();
            $table->foreignId('supervisor_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            // Derived at each stage. 100 = all checked + no damage;
            // subtracts for missed items + damage_note-bearing items.
            $table->unsignedTinyInteger('readiness_score')->default(0);

            // Free-text summary set by the last signer (or the rejecter).
            $table->text('summary_note')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'room_id', 'status'], 'ri_tenant_room_status_idx');
        });

        Schema::create('room_inspection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_inspection_id')
                ->constrained('room_inspections')->cascadeOnDelete();
            $table->foreignId('housekeeping_checklist_item_id')->nullable()
                ->constrained('housekeeping_checklist_items')->nullOnDelete();

            // Snapshot the label so editing the template later doesn't
            // rewrite a completed inspection's history.
            $table->string('label', 200);

            $table->boolean('checked')->default(false);
            // What's damaged / missing — null when the item's fine.
            $table->text('damage_note')->nullable();

            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();

            $table->timestamps();

            $table->index('room_inspection_id', 'rii_inspection_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_inspection_items');
        Schema::dropIfExists('room_inspections');
        Schema::dropIfExists('housekeeping_checklist_items');
        Schema::dropIfExists('housekeeping_checklists');
    }
};
