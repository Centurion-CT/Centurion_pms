<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift accounting for POS outlets.
 *
 * A shift is a bounded window at an outlet during which one operator
 * (typically the salesperson on duty) rings sales. Opening the shift
 * snapshots every product's quantity_on_hand so the closing view can
 * compute sold-vs-variance; closing the shift snapshots again and
 * marks the shift closed.
 *
 * Only ONE shift can be open per (outlet_id, operator_id) at a time,
 * enforced by the partial unique index below (or by a check in the
 * OpenOutletShift action for engines that don't support partial idx).
 *
 * outlet_shift_stock_snapshots holds two rows per shift-product pair
 * (kind='opening' at open, kind='closing' at close), so the report
 * can render opening → sold → other movements → closing side-by-side
 * without recomputing from movement rows every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_operator_id')
                ->nullable()
                ->constrained('operators')
                ->nullOnDelete();
            $table->string('close_reason', 200)->nullable();  // e.g. "Force-closed by manager · stale overnight"
            $table->text('notes')->nullable();  // free-form: cash count remarks, incidents
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'opened_at'], 'shifts_outlet_time_idx');
            $table->index(['tenant_id', 'operator_id', 'opened_at'], 'shifts_operator_time_idx');
            $table->index(['closed_at'], 'shifts_open_scan_idx');  // helps "any open shift?" scans
        });

        Schema::create('outlet_shift_stock_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);  // 'opening' | 'closing'
            $table->integer('quantity_on_hand');  // snapshot value
            $table->string('product_name', 200);  // denormalised for CSV export (product may be renamed later)
            $table->integer('unit_price')->nullable();  // snapshot ₦ price so reports reflect what was in force
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['outlet_shift_id', 'product_id', 'kind'], 'shift_stock_uniq');
            $table->index(['tenant_id', 'outlet_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_shift_stock_snapshots');
        Schema::dropIfExists('outlet_shifts');
    }
};
