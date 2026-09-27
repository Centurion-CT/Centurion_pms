<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * End-to-end smoke test for the BunnyCDN storage disk.
 *
 *   php artisan bunny:ping
 *
 * Steps:
 *   1. Echo back the resolved config so misconfiguration is visible at a glance.
 *   2. Upload a small test file to the configured zone.
 *   3. Read it back through the same disk.
 *   4. Surface the Pull Zone URL so the operator can verify in a browser.
 *   5. Clean up.
 *
 * Failure at any step prints the underlying exception message and a hint
 * about which env var is most likely the culprit.
 */
class BunnyPingCommand extends Command
{
    /** @var string */
    protected $signature = 'bunny:ping
                            {--keep : Skip the cleanup step so the test file stays uploaded}';

    /** @var string */
    protected $description = 'Smoke-test the configured BunnyCDN storage disk';

    public function handle(): int
    {
        $config = config('filesystems.disks.bunny');

        $this->line('');
        $this->info('Bunny storage config');
        $this->table(['Setting', 'Value'], [
            ['storage_zone', $this->mask($config['storage_zone'] ?? null)],
            ['api_key',      $this->mask($config['api_key'] ?? null, 4)],
            ['region',       $config['region'] ?? '(empty / Falkenstein)'],
            ['pull_zone_url',$config['pull_zone_url'] ?? '(unset)'],
        ]);

        if (empty($config['storage_zone']) || empty($config['api_key'])) {
            $this->error('BUNNY_STORAGE_ZONE and/or BUNNY_STORAGE_API_KEY are blank.');
            $this->line('Set them in your .env and run `php artisan config:clear`, then try again.');

            return self::FAILURE;
        }

        $path = 'health/'.now()->format('Y-m-d').'/bunny-ping-'.Str::random(8).'.txt';
        $payload = "Keverna bunny:ping · ".now()->toIso8601String();

        try {
            $this->line('');
            $this->line('→ Uploading test file to '.$path);
            Storage::disk('bunny')->put($path, $payload);
            $this->info('  ✓ Upload OK');
        } catch (Throwable $e) {
            $this->error('  ✗ Upload failed: '.$e->getMessage());
            $this->hint();

            return self::FAILURE;
        }

        try {
            $this->line('→ Reading it back');
            $readBack = Storage::disk('bunny')->get($path);
            if ($readBack !== $payload) {
                $this->error('  ✗ Content mismatch.');
                $this->line('    Expected: '.$payload);
                $this->line('    Received: '.(string) $readBack);
                $this->hint();

                return self::FAILURE;
            }
            $this->info('  ✓ Read OK (content matches)');
        } catch (Throwable $e) {
            $this->error('  ✗ Read failed: '.$e->getMessage());
            $this->hint();

            return self::FAILURE;
        }

        try {
            $publicUrl = Storage::disk('bunny')->url($path);
            $this->line('');
            $this->info('Public URL via Pull Zone:');
            $this->line('  '.$publicUrl);
            $this->line('  (open in a browser to confirm the CDN edge serves it)');
        } catch (Throwable $e) {
            $this->warn('  ⚠ url() threw: '.$e->getMessage());
            $this->warn('  This usually means BUNNY_PULL_ZONE_URL isn\'t set — uploads still worked.');
        }

        if ($this->option('keep')) {
            $this->line('');
            $this->line('--keep set — leaving '.$path.' on the zone.');

            return self::SUCCESS;
        }

        try {
            $this->line('');
            $this->line('→ Cleaning up');
            Storage::disk('bunny')->delete($path);
            $this->info('  ✓ Delete OK');
        } catch (Throwable $e) {
            $this->warn('  ⚠ Delete failed: '.$e->getMessage());
            $this->warn('  The upload + read both worked, so the integration is fine — you can delete '.$path.' manually.');
        }

        $this->line('');
        $this->info('Bunny is wired up correctly.');

        return self::SUCCESS;
    }

    private function mask(?string $value, int $tailLength = 6): string
    {
        if ($value === null || $value === '') {
            return '(unset)';
        }
        if (strlen($value) <= $tailLength) {
            return str_repeat('•', strlen($value));
        }

        return str_repeat('•', max(0, strlen($value) - $tailLength)).substr($value, -$tailLength);
    }

    private function hint(): void
    {
        $this->line('');
        $this->line('Common causes:');
        $this->line('  • BUNNY_STORAGE_ZONE is wrong (case-sensitive, no slashes)');
        $this->line('  • BUNNY_STORAGE_API_KEY is the account key instead of the storage-zone key');
        $this->line('  • BUNNY_REGION mismatches the zone you created (see https://docs.bunny.net/reference/edge-storage-api-limits-headers)');
    }
}
