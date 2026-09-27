<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie Activitylog table, with two Keverna-specific extras baked into the
 * initial schema (rather than tacked on as separate migrations):
 *
 *   1. `tenant_id` — every audit row is scoped to a tenant so the operator
 *      dashboard can only ever read its own actions.  Nullable so central
 *      jobs (e.g. ProvisionTenantSubdomain) can still log without forcing a
 *      tenant context.
 *   2. `event` + `batch_uuid` — both columns Spatie adds via separate stub
 *      migrations.  Folded in here so a fresh install gets the latest
 *      schema in one go.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('event')->nullable();
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index('log_name');
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->dropIfExists($table);
    }
};
