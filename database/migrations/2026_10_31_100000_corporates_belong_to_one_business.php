<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Corporates came from Keverna's marketplace, where one company could book
 * at any property, so they were shared across the install. In Centurion
 * each business keeps its own corporate clients (docs/MULTITENANCY.md,
 * step 2): a corporate, its staff and its email domains belong to the
 * business that added them.
 *
 * Existing rows go to the business that created them, else the business
 * they have bookings with, else the first business (production has one).
 * The column stays nullable; BelongsToTenant fills it on create and its
 * scope never matches a null.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['corporates', 'corporate_members', 'corporate_domains'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('tenant_id')->nullable()->after('id')
                    ->constrained('tenants')->cascadeOnDelete();
                $table->index('tenant_id');
            });
        }

        DB::statement(<<<'SQL'
            UPDATE corporates SET tenant_id = COALESCE(
                created_by_tenant_id,
                (SELECT MIN(b.tenant_id) FROM bookings b WHERE b.corporate_id = corporates.id),
                (SELECT MIN(t.id) FROM tenants t)
            )
            WHERE tenant_id IS NULL
            SQL);

        foreach (['corporate_members', 'corporate_domains'] as $name) {
            DB::statement(<<<SQL
                UPDATE {$name} SET tenant_id =
                    (SELECT c.tenant_id FROM corporates c WHERE c.id = {$name}.corporate_id)
                WHERE tenant_id IS NULL
                SQL);
        }

        Schema::table('corporates', function (Blueprint $table): void {
            $table->dropUnique('corporates_slug_unique');
            $table->unique(['tenant_id', 'slug'], 'corporates_tenant_slug_unique');
        });

        Schema::table('corporate_domains', function (Blueprint $table): void {
            $table->dropUnique('corporate_domains_domain_unique');
            $table->unique(['tenant_id', 'domain'], 'corporate_domains_tenant_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('corporate_domains', function (Blueprint $table): void {
            $table->dropUnique('corporate_domains_tenant_domain_unique');
            $table->unique('domain', 'corporate_domains_domain_unique');
        });

        Schema::table('corporates', function (Blueprint $table): void {
            $table->dropUnique('corporates_tenant_slug_unique');
            $table->unique('slug', 'corporates_slug_unique');
        });

        foreach (['corporate_domains', 'corporate_members', 'corporates'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
