<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot: which rooms a discount targets. Empty rows for a given
 * discount_id = "applies to every active room on this tenant".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();

            $table->unique(['discount_id', 'room_id']);
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rooms');
    }
};
