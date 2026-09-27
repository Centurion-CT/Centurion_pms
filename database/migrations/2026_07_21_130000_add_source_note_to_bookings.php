<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text tag paired with BookingSource — e.g. the operator picks
 * `Other` and types "Facebook Marketplace", or picks `BookingCom` and
 * notes "second account". Populated by the frontdesk source picker so
 * the reports/payments channel breakdown can surface the operator's
 * own vocabulary alongside the canonical enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('source_note', 120)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('source_note');
        });
    }
};
