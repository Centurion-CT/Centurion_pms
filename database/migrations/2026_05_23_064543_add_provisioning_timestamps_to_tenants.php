<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle markers added by §5:
 *  - subdomain_provisioned_at: stamped by ProvisionTenantSubdomain on success
 *    (Cloudflare CNAME created or already-exists 81057 detected). Drives the
 *    "Your booking URL" display on the dashboard.
 *  - slug_changed_at: enforces the PRD §6.6.1 "slug changeable once per year"
 *    constraint. NULL until the operator changes the slug for the first time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('subdomain_provisioned_at')->nullable()->after('custom_domain');
            $table->timestamp('slug_changed_at')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['subdomain_provisioned_at', 'slug_changed_at']);
        });
    }
};
