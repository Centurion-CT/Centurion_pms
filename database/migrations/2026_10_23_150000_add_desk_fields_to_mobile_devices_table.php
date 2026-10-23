<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keverna Desk (docs/desk-app/PHASE-1.md): a shared reception PC is a
 * mobile_devices row with app = 'desk'.
 *  - property_id: the branch this desk sits in. Operator tokens issued
 *    on the desk are pinned to it. Null until the person who set the
 *    desk up picks a branch (auto-picked when they only have one).
 *  - desk_unlocks: {operator_id: ISO time} of the last PIN unlock per
 *    operator, for the "who's at the desk" list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table): void {
            $table->foreignId('property_id')->nullable()->after('operator_id')
                ->constrained('properties')->nullOnDelete();
            $table->json('desk_unlocks')->nullable()->after('push_token_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('property_id');
            $table->dropColumn('desk_unlocks');
        });
    }
};
