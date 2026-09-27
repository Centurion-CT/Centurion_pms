<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract-terms columns so a super-admin can activate / renew a
 * subscription with an agreement value that MAY differ from what
 * the tenant paid up-front. Existing `amount` was overloaded as
 * both "what was billed" and "what came in"; this splits them so
 * outstanding balances are first-class.
 *
 *   contract_amount — total agreed for the period (NGN)
 *   amount_received — what has actually landed so far (NGN)
 *   duration_months — the negotiated term in months (usually 12);
 *                     kept as-is on the row so future renewals
 *                     default to the same length without guessing
 *
 * Outstanding = contract_amount - amount_received.
 *
 * Legacy `amount` stays populated (mirrors amount_received for
 * pre-migration rows via the backfill below) so any downstream
 * queries still read the same billing figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $t): void {
            $t->unsignedInteger('contract_amount')->default(0)->after('amount');
            $t->unsignedInteger('amount_received')->default(0)->after('contract_amount');
            $t->unsignedSmallInteger('duration_months')->default(12)->after('amount_received');
            $t->text('agreement_notes')->nullable()->after('duration_months');
        });

        // Backfill: existing rows treat `amount` as both agreed +
        // received (that's what pre-migration code assumed).
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE subscriptions SET contract_amount = amount, amount_received = amount WHERE contract_amount = 0'
        );
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t): void {
            $t->dropColumn(['contract_amount', 'amount_received', 'duration_months', 'agreement_notes']);
        });
    }
};
