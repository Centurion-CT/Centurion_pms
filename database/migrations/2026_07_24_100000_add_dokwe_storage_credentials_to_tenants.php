<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Per-tenant Dokwe Storage credentials. When these are populated the
 * `ConfigureTenantMediaDisk` middleware swaps Spatie MediaLibrary's disk
 * to the tenant's own bucket for the duration of the request, so every
 * new upload lands in storage the tenant owns — not the shared platform
 * space.
 *
 * The two secrets are cast as `encrypted` on the Tenant model (mirrors
 * the paystack_secret_key + paystack_account_number pattern from
 * 2026_05_26_120000_add_paystack_subaccount_to_tenants). Everything
 * else is public metadata safe to store in plaintext — bucket/endpoint
 * are half-guessable anyway from the tenant's Dokwe dashboard URL.
 *
 * Nullable across the board: the vast majority of tenants use the
 * platform default disk and never touch this integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->text('dokwe_storage_access_key')->nullable()->after('paystack_account_name');
            $table->text('dokwe_storage_secret_key')->nullable()->after('dokwe_storage_access_key');
            $table->string('dokwe_storage_bucket', 120)->nullable()->after('dokwe_storage_secret_key');
            $table->string('dokwe_storage_endpoint', 240)->nullable()->after('dokwe_storage_bucket');
            $table->string('dokwe_storage_public_url', 240)->nullable()->after('dokwe_storage_endpoint');
            $table->string('dokwe_storage_region', 24)->default('us-east-1')->after('dokwe_storage_public_url');
            $table->timestamp('dokwe_storage_connected_at')->nullable()->after('dokwe_storage_region');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'dokwe_storage_access_key',
                'dokwe_storage_secret_key',
                'dokwe_storage_bucket',
                'dokwe_storage_endpoint',
                'dokwe_storage_public_url',
                'dokwe_storage_region',
                'dokwe_storage_connected_at',
            ]);
        });
    }
};
