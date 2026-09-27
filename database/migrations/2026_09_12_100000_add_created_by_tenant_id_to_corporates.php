<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance column on the central Corporates table so we can tell
 * "this corp was created by a tenant's Manager from their dashboard"
 * apart from "this corp was onboarded by Keverna central admin". The
 * tenant-side Corporate management UI scopes its list to
 * created_by_tenant_id + corps that have done business here, which
 * keeps a Lagos hotel's Manager from editing an Abuja hotel's corp
 * roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->foreignId('created_by_tenant_id')->nullable()
                ->after('id')
                ->constrained('tenants')->nullOnDelete();
            $table->index('created_by_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->dropForeign(['created_by_tenant_id']);
            $table->dropIndex(['created_by_tenant_id']);
            $table->dropColumn('created_by_tenant_id');
        });
    }
};
