<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag every bookable row (booking / desk_booking / membership /
 * lease) with the corporate it was booked under, so:
 *   - operators see "Corporate · Acme" on their reservations list
 *   - reporting can split corporate vs walk-in GMV
 *   - cancellations know which wallet to refund
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookings', 'desk_bookings', 'memberships', 'leases'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignId('corporate_id')->nullable()->after('tenant_id')
                    ->constrained()->nullOnDelete();
                $t->foreignId('corporate_member_id')->nullable()->after('corporate_id')
                    ->constrained('corporate_members')->nullOnDelete();
                $t->unsignedInteger('corporate_discount_naira')->default(0)->after('corporate_member_id');
            });
        }

        // Property opt-in: properties that don't toggle this flag are
        // skipped by corporate rate resolution + members can't book
        // them with the wallet. Keeps revenue-sensitive operators in
        // control.
        Schema::table('properties', function (Blueprint $t): void {
            $t->boolean('accepts_corporates')->default(false)->after('whatsapp_button_prefill');
        });
    }

    public function down(): void
    {
        foreach (['bookings', 'desk_bookings', 'memberships', 'leases'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table): void {
                $t->dropForeign([$table.'_corporate_id_foreign']);
                $t->dropForeign([$table.'_corporate_member_id_foreign']);
                $t->dropColumn(['corporate_id', 'corporate_member_id', 'corporate_discount_naira']);
            });
        }
        Schema::table('properties', function (Blueprint $t): void {
            $t->dropColumn('accepts_corporates');
        });
    }
};
