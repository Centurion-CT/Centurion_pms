<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the operator role column. Existing rows default to 'manager'
 * so the single-founder case keeps full access. New invitees pick
 * from manager / reservation_officer / frontdesk / house_cleaner via
 * the Staff invite form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('role', 32)->default('manager')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
