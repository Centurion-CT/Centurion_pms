<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic rate overrides for the /stays online booking flow (NOT the
 * front-desk booking modal — see StaysAvailabilityBrowser + the
 * category `from` price on marketplace/stays/show).
 *
 * Each row overrides the category's base rate for a date range when
 * every enabled condition matches the guest's request context:
 *
 *   - devices    : which device forms count (mobile / desktop / any)
 *   - referrers  : which acquisition sources count (google / direct
 *                   / instagram / whatsapp / any). Anything else
 *                   falls through to the base rate.
 *   - time window: intra-day booking window ("morning discount" ends
 *                   at 11am, "evening surge" starts at 6pm, etc.)
 *
 * `priority` breaks ties when two rules overlap: highest wins.
 * `room_category_id` null means the rule applies to every category
 * on the property (property-wide override).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->nullable()->constrained('room_categories')->cascadeOnDelete();

            $table->string('label', 120)->nullable();
            $table->integer('rate_ngn')->unsigned();

            $table->date('starts_on');
            $table->date('ends_on');

            // JSON arrays — empty / null means "no constraint" (matches any).
            $table->json('devices')->nullable();
            $table->json('referrers')->nullable();

            // Intra-day booking window. Both null = whole day.
            $table->time('time_start')->nullable();
            $table->time('time_end')->nullable();

            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'property_id', 'room_category_id']);
            $table->index(['property_id', 'starts_on', 'ends_on']);
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_rules');
    }
};
