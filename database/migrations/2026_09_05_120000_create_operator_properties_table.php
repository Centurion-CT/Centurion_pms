<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff-to-branch access scoping.
 *
 * Convention: an operator with ZERO rows in this table has "all
 * branches" access — backwards-compat for orgs pre-multi-branch
 * where staff naturally saw everything in their (single) property.
 * Once an admin ticks any branch on the staff-permissions page,
 * the operator becomes explicitly restricted to that set.
 *
 * Managers always get all-branches access via role bypass in
 * {@see \App\Models\Operator::hasAccessToBranch()}, regardless of
 * pivot rows — so a rogue admin can't lock the manager out of a
 * branch they own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_properties', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operator_id');
            $table->unsignedBigInteger('property_id');
            $table->timestamps();

            $table->unique(['operator_id', 'property_id'], 'operator_properties_op_prop_unique');
            $table->index('operator_id');
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_properties');
    }
};
