<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the housekeeping state to Rooms. Defaults to `clean` so
 * existing inventory is immediately bookable; the MarkCheckedOut
 * action flips it to `dirty` going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('housekeeping_status', 24)->default('clean')->after('status');
            $table->timestamp('housekeeping_updated_at')->nullable()->after('housekeeping_status');
            $table->foreignId('housekeeping_updated_by_operator_id')->nullable()
                ->after('housekeeping_updated_at')
                ->constrained('operators')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('housekeeping_updated_by_operator_id');
            $table->dropColumn(['housekeeping_status', 'housekeeping_updated_at']);
        });
    }
};
