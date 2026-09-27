<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * End-to-end smoke test for an operator's Paystack secret key.
 *
 *   php artisan paystack:tenant-ping <tenant-slug>
 *
 * Hits Paystack's lightweight `/bank` endpoint (cheap, no money moved)
 * using the operator's stored key. Surfaces 401 / 4xx / network errors
 * clearly so support can tell key-misconfigured from API-down before a
 * guest hits the same failure mid-checkout.
 */
class PaystackTenantPingCommand extends Command
{
    /** @var string */
    protected $signature = 'paystack:tenant-ping {tenant : Tenant slug}';

    /** @var string */
    protected $description = 'Verify a tenant\'s Paystack secret key works';

    public function handle(): int
    {
        $slug = (string) $this->argument('tenant');

        $tenant = Tenant::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No tenant found with slug '{$slug}'.");

            return self::FAILURE;
        }

        $key = (string) ($tenant->paystack_secret_key ?? '');
        if ($key === '') {
            $this->error("Tenant '{$slug}' has no Paystack secret key configured.");
            $this->line('  Set one at /dashboard/settings/property → Payments — Paystack.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Paystack tenant ping');
        $this->table(['Setting', 'Value'], [
            ['tenant',  $slug],
            ['key',     $this->mask($key)],
            ['mode',    str_starts_with($key, 'sk_live_') ? 'LIVE' : (str_starts_with($key, 'sk_test_') ? 'TEST' : 'unknown')],
        ]);

        $this->line('');
        $this->line('→ Calling Paystack /bank …');

        try {
            $response = Http::baseUrl((string) config('services.paystack.base_url'))
                ->withToken($key)
                ->acceptJson()
                ->connectTimeout(20)
                ->timeout(30)
                ->get('/bank', ['country' => 'nigeria', 'perPage' => 1])
                ->throw();

            $body = $response->json();
            if (is_array($body) && ($body['status'] ?? false) === true) {
                $this->info('  ✓ Paystack accepted the key — checkout should work.');

                return self::SUCCESS;
            }

            $this->error('  ✗ Paystack returned status=false');
            $this->line('    body: '.json_encode($body, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error('  ✗ Could not reach Paystack: '.$e->getMessage());
            $this->line('    Check outbound network / DNS to api.paystack.co.');

            return self::FAILURE;
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $this->error("  ✗ Paystack rejected the request (HTTP {$status})");
            $this->line('    body: '.(string) $e->response?->body());

            if ($status === 401) {
                $this->line('');
                $this->warn("Paystack says the key is invalid. Re-copy it from");
                $this->line('  https://dashboard.paystack.com/#/settings/developer');
                $this->line('and paste it into the operator\'s Property settings form.');
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('  ✗ Unexpected failure: '.$e->getMessage());
            $this->line('    type: '.$e::class);

            return self::FAILURE;
        }
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 12) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 8).str_repeat('•', 14).substr($value, -4);
    }
}
