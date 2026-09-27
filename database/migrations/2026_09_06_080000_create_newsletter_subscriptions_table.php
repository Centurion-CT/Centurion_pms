<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing-site newsletter list. Central (no tenant scope), one
 * row per email address; a resubscribe after unsubscribing flips
 * the same row's status back to active rather than creating a
 * duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 180)->unique();
            $table->string('status', 16)->default('active'); // active | unsubscribed | bounced
            $table->string('source', 32)->default('landing'); // landing | footer | manual | api-access | demo
            $table->string('unsubscribe_token', 40)->unique();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 400)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('subscribed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscriptions');
    }
};
