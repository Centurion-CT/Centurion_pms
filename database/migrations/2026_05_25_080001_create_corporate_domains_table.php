<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email domains that auto-link to a corporate. e.g. an "acme.com"
 * entry means anyone with an @acme.com email is recognised as part
 * of Acme without needing to be invited individually.
 *
 * A booker can still be a corporate member by explicit invite even
 * when their email doesn't match any verified domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 120); // stored lowercased
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->unique('domain'); // a domain can only belong to one corporate
            $table->index('corporate_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_domains');
    }
};
