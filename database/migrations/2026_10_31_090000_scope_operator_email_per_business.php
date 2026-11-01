<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A staff email is unique within a business, not across the install, so
 * one person can work at several businesses (docs/MULTITENANCY.md). The
 * original operators migration planned this as "(tenant_id, email)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropUnique('operators_email_unique');
            $table->unique(['tenant_id', 'email'], 'operators_tenant_email_unique');
            $table->index('email', 'operators_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table): void {
            $table->dropIndex('operators_email_index');
            $table->dropUnique('operators_tenant_email_unique');
            $table->unique('email', 'operators_email_unique');
        });
    }
};
