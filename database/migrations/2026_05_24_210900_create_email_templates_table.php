<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant overrides for the five system emails. A missing row =
 * "use the default subject + body from EmailTemplateKey"; presence
 * means the operator has customised either the subject, the body,
 * or both.
 *
 * `active` lets the operator pause a template without losing their
 * edits — when inactive, the renderer falls back to the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('subject', 240);
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by_operator_id')->nullable()
                ->constrained('operators')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
