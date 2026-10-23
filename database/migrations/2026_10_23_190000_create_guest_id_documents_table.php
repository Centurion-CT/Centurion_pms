<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keverna Desk phase 2: the guest's ID document seen at check-in, and
 * the key cards handed over / returned.
 *
 * Personal data under the Nigeria Data Protection Act: only the fields
 * below are kept, the number and date of birth are encrypted at rest,
 * and everything except "an ID of this type was seen" is wiped after
 * `retain_until` (check-out + config keverna.guest_id_retention_months)
 * by `guests:purge-id-documents`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_id_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24);
            // Encrypted (Laravel `encrypted` cast), so text not varchar.
            $table->text('number')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->text('date_of_birth')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('image_disk', 40)->nullable();
            $table->string('image_path', 255)->nullable();
            $table->foreignId('captured_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->date('retain_until');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'booking_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['purged_at', 'retain_until']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('keys_given')->nullable();
            $table->unsignedTinyInteger('keys_returned')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['keys_given', 'keys_returned']);
        });
        Schema::dropIfExists('guest_id_documents');
    }
};
