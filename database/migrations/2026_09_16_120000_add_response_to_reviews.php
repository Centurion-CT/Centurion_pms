<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Reply as property" for reviews. Guests read reviews before booking, and
 * a thoughtful operator response turns a middling review into a trust
 * signal (Booking.com + Google Business use the same pattern).
 *
 *   response_body                 - the operator's reply
 *   responded_at                  - when the reply was published
 *   responded_by_operator_id      - who typed it (for the audit log +
 *                                    displaying the responder's name on
 *                                    the marketplace page)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->text('response_body')->nullable()->after('body');
            $table->timestamp('responded_at')->nullable()->after('response_body');
            $table->foreignId('responded_by_operator_id')->nullable()
                ->after('responded_at')
                ->constrained('operators')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responded_by_operator_id');
            $table->dropColumn(['response_body', 'responded_at']);
        });
    }
};
