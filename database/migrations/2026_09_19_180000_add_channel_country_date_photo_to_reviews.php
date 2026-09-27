<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends reviews with the metadata operators actually paste from
 * cross-listing platforms.
 *
 *   channel        Where the review originally came from (Direct,
 *                  Google, Booking.com, Airbnb, TripAdvisor, Other).
 *                  Renders as a subtle badge next to the guest name.
 *   guest_country  Free-text country string (short ISO name is enough;
 *                  operators paste "Nigeria" / "United Kingdom" as-is).
 *   published_at   The date the operator wants the review shown as
 *                  published on the property page. Defaults to now on
 *                  creation, editable.
 *   photo_path     Relative path to an operator-uploaded thumbnail
 *                  (guest self-portrait, room photo shared in the
 *                  review, or a screenshot of the source review for
 *                  verification). Stored on the `public` disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->string('channel', 32)->default('direct')->after('rating');
            $table->string('guest_country', 80)->nullable()->after('guest_name');
            $table->timestamp('published_at')->nullable()->after('order_column');
            $table->string('photo_path', 255)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'guest_country', 'published_at', 'photo_path']);
        });
    }
};
