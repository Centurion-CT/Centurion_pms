<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old → new slug redirects (PRD §6.6.1). When an operator changes their slug,
 * the old `{from_slug}.keverna.com` should 301 to the new one for 90 days so
 * shared links don't break.
 *
 * `from_slug` is unique to enforce one redirect per old slug; a tenant changing
 * back to a previously-used slug within the window will get the existing
 * row replaced via updateOrCreate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('from_slug')->unique();
            $table->foreignId('to_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
    }
};
