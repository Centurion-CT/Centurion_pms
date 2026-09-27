<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domain BYOD verification (PRD §6.6.2):
 *  - custom_domain_verification_token: opaque value the operator sets as a TXT
 *    record on their domain so we can prove ownership. Generated when the
 *    operator first attaches a custom_domain; rotated on each re-attach.
 *  - custom_domain_status: human-displayable state for the dashboard chip.
 *    pending → active (on verification) → error (after 48h of polling without
 *    a successful lookup).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('custom_domain_verification_token', 64)->nullable()->after('custom_domain');
            $table->string('custom_domain_status', 16)->nullable()->after('custom_domain_verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['custom_domain_verification_token', 'custom_domain_status']);
        });
    }
};
