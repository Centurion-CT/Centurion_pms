<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A queued portal action that couldn't be executed straight through
 * because the guest's captured device coords scored below the
 * property's geo-fence threshold (or they denied the browser
 * permission, which is treated as score = 0). Front desk reviews,
 * calls the guest to verify identity, then approves (executes the
 * underlying action) or rejects (with a note back to the guest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_action_approvals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('room_id');
            $table->string('kind', 32); // extend | order_room | order_card | reserve | checkout | request:<GuestRequestKind>
            $table->json('payload')->nullable();

            $table->unsignedTinyInteger('coord_score')->default(0);
            $table->decimal('guest_latitude', 10, 7)->nullable();
            $table->decimal('guest_longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();

            $table->string('status', 16)->default('pending'); // pending | approved | rejected | expired
            $table->unsignedBigInteger('approved_by_operator_id')->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_action_approvals');
    }
};
