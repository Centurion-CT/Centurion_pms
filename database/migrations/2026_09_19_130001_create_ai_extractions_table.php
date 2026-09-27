<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit trail for every external content-extraction call.
 *
 * One row per API call (Google Places, Claude Sonnet 4.6 on Booking or
 * Airbnb HTML). Records tokens in / out and the resulting cost in kobo
 * so the super admin has a live view of spend at any moment. Never
 * updated after insert; monthly caps are enforced by summing this
 * table.
 *
 * `cost_kobo` stores cost in 100ths of a naira. Google Places calls
 * translate the USD price into kobo at a fixed rate configured in
 * config/services.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_extractions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->nullable()->constrained('property_content_imports')->nullOnDelete();
            $table->string('provider', 32);            // 'google_places' | 'anthropic_claude'
            $table->string('model', 64)->nullable();   // e.g. 'claude-sonnet-4-6'
            $table->string('source_url', 512)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cost_kobo')->default(0);
            $table->boolean('succeeded')->default(true);
            $table->text('error')->nullable();
            $table->timestamp('ran_at')->useCurrent();
            $table->timestamps();

            $table->index(['provider', 'ran_at']);
            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_extractions');
    }
};
