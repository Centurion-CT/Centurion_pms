<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff app (housekeepers and porters, docs/staff-app/PLAN.md).
 *
 * operators: a hashed PIN for phone + PIN sign-in on the staff app.
 * room_inspections: who is cleaning it and when they started, plus the
 * porter's "send back" (the room returns to Pending for the same
 * cleaner, with the reasons and a running count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->string('staff_pin_hash')->nullable()->after('password');
            $table->timestamp('staff_pin_set_at')->nullable()->after('staff_pin_hash');
        });

        Schema::table('room_inspections', function (Blueprint $table): void {
            $table->foreignId('assigned_operator_id')->nullable()->after('status')
                ->constrained('operators')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->after('assigned_operator_id');
            $table->timestamp('sent_back_at')->nullable()->after('supervisor_by_operator_id');
            $table->foreignId('sent_back_by_operator_id')->nullable()->after('sent_back_at')
                ->constrained('operators')->nullOnDelete();
            $table->json('sent_back_reasons')->nullable()->after('sent_back_by_operator_id');
            $table->text('sent_back_note')->nullable()->after('sent_back_reasons');
            $table->unsignedSmallInteger('send_back_count')->default(0)->after('sent_back_note');
        });
    }

    public function down(): void
    {
        Schema::table('room_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_operator_id');
            $table->dropConstrainedForeignId('sent_back_by_operator_id');
            $table->dropColumn(['started_at', 'sent_back_at', 'sent_back_reasons', 'sent_back_note', 'send_back_count']);
        });

        Schema::table('operators', function (Blueprint $table): void {
            $table->dropColumn(['staff_pin_hash', 'staff_pin_set_at']);
        });
    }
};
