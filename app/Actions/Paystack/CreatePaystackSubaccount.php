<?php

declare(strict_types=1);

namespace App\Actions\Paystack;

use App\Models\Tenant;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orchestrates the operator → Paystack Subaccount handshake.
 *
 *   1. /bank/resolve         — confirm the NUBAN belongs to the named
 *                              account holder (defence against typos
 *                              that would otherwise pay strangers).
 *   2. /subaccount POST / PUT — register (or update) the subaccount
 *                              against FregateLab's master Paystack
 *                              account with `percentage_charge` set
 *                              to Keverna's platform cut.
 *   3. Persist                — write subaccount_code + the bank
 *                              metadata back onto the tenant so the
 *                              booking flow can route to it.
 *
 * Idempotent: rerunning with the same details is a no-op on Paystack
 * (PUT). Re-running with different bank details swaps the payout
 * destination without breaking existing pending bookings — Paystack
 * routes future transactions to the new bank.
 */
class CreatePaystackSubaccount
{
    /**
     * @return array{subaccount_code: string, account_name: string, bank_name: string}
     */
    public function handle(
        Tenant $tenant,
        string $bankCode,
        string $accountNumber,
        ?string $bankName = null,
    ): array {
        $bankCode = trim($bankCode);
        $accountNumber = trim($accountNumber);

        if ($bankCode === '' || $accountNumber === '') {
            throw new RuntimeException('Bank and account number are required to set up payouts.');
        }
        if (! preg_match('/^\d{10}$/', $accountNumber)) {
            throw new RuntimeException('Nigerian account numbers must be exactly 10 digits.');
        }

        $client = PaystackClient::forPlatform();

        // If the caller didn't pass a display name, look it up from the
        // cached bank list so the operator sees "GTBank" on their
        // dashboard, not "058".
        if ($bankName === null || $bankName === '') {
            $bankName = $this->lookupBankName($client, $bankCode);
        }

        $resolved = $client->resolveAccount($accountNumber, $bankCode);
        $accountName = $resolved['account_name'];
        if ($accountName === '') {
            throw new RuntimeException(
                'Paystack could not verify that account number with the selected bank. '
                .'Double-check the number and try again.'
            );
        }

        $percentageCharge = (float) config('services.paystack.platform_percent_charge', 2.0);
        $businessName = (string) $tenant->name;

        if (($existing = (string) $tenant->paystack_subaccount_code) !== '') {
            $result = $client->updateSubaccount(
                subaccountCode: $existing,
                businessName: $businessName,
                bankCode: $bankCode,
                accountNumber: $accountNumber,
                percentageCharge: $percentageCharge,
            );
            $subaccountCode = $result['subaccount_code'];
        } else {
            $result = $client->createSubaccount(
                businessName: $businessName,
                bankCode: $bankCode,
                accountNumber: $accountNumber,
                percentageCharge: $percentageCharge,
                primaryContactEmail: $tenant->email ?: null,
                primaryContactPhone: $tenant->phone ?: null,
                description: config('brand.name')." business · {$tenant->slug}",
            );
            $subaccountCode = $result['subaccount_code'];
        }

        if ($subaccountCode === '') {
            throw new RuntimeException('Paystack returned an empty subaccount_code.');
        }

        DB::transaction(function () use (
            $tenant,
            $subaccountCode,
            $bankCode,
            $accountNumber,
            $accountName,
            $bankName,
        ): void {
            $tenant->forceFill([
                'paystack_subaccount_code' => $subaccountCode,
                'paystack_bank_code' => $bankCode,
                'paystack_account_number' => $accountNumber,
                'paystack_account_name' => $accountName,
                'paystack_bank_name' => $bankName,
            ])->save();
        });

        Log::info('Paystack subaccount registered', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'subaccount_code' => $subaccountCode,
            'bank' => $bankName.' ('.$bankCode.')',
        ]);

        return [
            'subaccount_code' => $subaccountCode,
            'account_name' => $accountName,
            'bank_name' => $bankName ?? '',
        ];
    }

    private function lookupBankName(PaystackClient $client, string $bankCode): string
    {
        foreach ($client->listBanks() as $bank) {
            if ($bank['code'] === $bankCode) {
                return $bank['name'];
            }
        }

        return '';
    }
}
