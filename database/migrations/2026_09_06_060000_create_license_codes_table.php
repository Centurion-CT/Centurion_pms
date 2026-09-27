<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration is now behind an issued license code. Sales generates
 * one per prospect (typically after a demo call), the operator uses
 * the code to unlock /register. One code = one tenant. Codes can
 * expire so a lead that goes cold doesn't sit indefinitely.
 *
 * `used_by_operator_id` is intentionally nullable so a code that's
 * been generated but not redeemed yet still holds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('issued_to_email', 180)->nullable();
            $table->string('issued_to_name', 120)->nullable();
            $table->unsignedBigInteger('demo_request_id')->nullable();
            $table->string('status', 16)->default('unused'); // unused | used | revoked | expired
            $table->unsignedBigInteger('used_by_operator_id')->nullable();
            $table->unsignedBigInteger('used_by_tenant_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('issued_by_admin_id')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('demo_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_codes');
    }
};
