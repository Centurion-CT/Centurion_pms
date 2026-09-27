<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties membership receipts into the tenant Payment ledger so the
 * front desk sees gym / spa / snooker cash on the same Payments
 * page they use for room revenue. Mirrors the existing pos_sale_id
 * pattern — nullable, indexed for lookup + admin filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('membership_subscription_id')->nullable()
                ->after('pos_sale_id')
                ->constrained('membership_subscriptions')->nullOnDelete();
            // Membership-specific metadata: kind (initial/renewal/extension/
            // other) + which period of coverage this money bought. Nullable
            // for all non-membership rows.
            $table->string('membership_kind', 20)->nullable()
                ->after('membership_subscription_id');
            $table->date('membership_period_start')->nullable()
                ->after('membership_kind');
            $table->date('membership_period_end')->nullable()
                ->after('membership_period_start');
            $table->index(['tenant_id', 'membership_subscription_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'membership_subscription_id']);
            $table->dropForeign(['membership_subscription_id']);
            $table->dropColumn([
                'membership_subscription_id',
                'membership_kind',
                'membership_period_start',
                'membership_period_end',
            ]);
        });
    }
};
