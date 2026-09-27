<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A tenant's POS outlets — the bar, restaurant, laundry, spa, etc.
 * Every product and sale belongs to exactly one outlet, so operators
 * can spin up or archive whichever ones their property actually runs.
 * `slug` uniqueness is scoped to (tenant_id, slug) so two tenants can
 * both have a "bar" without collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 60);
            $table->string('type', 32);            // OutletType enum value
            $table->boolean('active')->default(true);
            $table->decimal('tax_rate', 5, 2)->default(0);  // percent, e.g. 7.5 for 7.5%
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
