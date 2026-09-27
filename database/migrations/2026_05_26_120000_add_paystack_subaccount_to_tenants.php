<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot Keverna's per-tenant payment model from "operator pastes their
 * own Paystack secret key" to Paystack Subaccounts.
 *
 * Why: with operator-owned keys, Keverna never sees the money and has
 * no automated way to take its 2% platform fee. With Subaccounts, every
 * booking is initialised on FregateLab's master Paystack account but
 * tagged with the operator's `subaccount_code` — Paystack settles 98%
 * directly to the operator's bank and credits the 2% to FregateLab's
 * balance, with no manual reconciliation.
 *
 * `paystack_secret_key` is retained for backwards compatibility (an
 * advanced operator can still use their own Paystack account) but no
 * longer the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Returned by Paystack on subaccount creation, e.g. ACCT_xxx.
            // Presence of this code is what flips the booking flow into
            // subaccount mode.
            $table->string('paystack_subaccount_code', 60)->nullable()->after('paystack_secret_key');

            // CBN bank code from /bank list (e.g. "058" for GTBank).
            $table->string('paystack_bank_code', 12)->nullable()->after('paystack_subaccount_code');

            // Operator's NUBAN. Stored encrypted because losing it lets
            // an attacker re-point payouts.
            $table->string('paystack_account_number', 32)->nullable()->after('paystack_bank_code');

            // Account name as confirmed by /bank/resolve. We display
            // this back to the operator so they can sanity-check the
            // payout destination before going live.
            $table->string('paystack_account_name', 120)->nullable()->after('paystack_account_number');

            // Human-readable bank name (snapshot at time of subaccount
            // creation) so the dashboard can show "GTBank · ••••1234"
            // without round-tripping the bank list.
            $table->string('paystack_bank_name', 120)->nullable()->after('paystack_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'paystack_subaccount_code',
                'paystack_bank_code',
                'paystack_account_number',
                'paystack_account_name',
                'paystack_bank_name',
            ]);
        });
    }
};
