<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which operators are allowed to run the POS terminal for which
 * outlets. Manager role bypasses this — they see every outlet by
 * default. PosStaff role must have at least one row here to see
 * anything (the terminal's outlet-picker uses this as its allow-list).
 *
 * No composite PK — we treat (outlet_id, operator_id) as a unique
 * pair via the index. Timestamps let us audit who granted access
 * and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_operators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['outlet_id', 'operator_id']);
            $table->index('operator_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_operators');
    }
};
