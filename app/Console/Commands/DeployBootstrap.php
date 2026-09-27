<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * One-command post-deploy bootstrap for prod. Chains every idempotent
 * cross-tenant maintenance command we want to run after migrations
 * land, so the deploy script only has to remember one artisan call.
 *
 * Every child command listed here must be SAFE to run repeatedly
 * (no side effects if state already matches). We never seed fake
 * data or edit tenant-owned tables — that's what DatabaseSeeder is
 * for, and DatabaseSeeder should never touch prod.
 *
 * Deploy script contract:
 *
 *   php artisan migrate --force
 *   php artisan keverna:deploy-bootstrap
 *
 * That's it.
 */
class DeployBootstrap extends Command
{
    protected $signature = 'keverna:deploy-bootstrap
                            {--dry-run : print what would run, don\'t run it}';

    protected $description = 'Runs the idempotent post-deploy chain: identity backfill, platform-stats refresh, etc.';

    /**
     * List of child artisan commands to run. Each entry is
     * [signature, human label]. Order matters — later commands may
     * depend on state written by earlier ones.
     *
     * @var list<array{0: string, 1: string}>
     */
    private array $chain = [
        // Ensures every Operator has a matching central IdentityAccount
        // row + link. Skips operators already linked, so re-running is
        // free. Without it, central login (keverna.com/login) fails
        // silently for operators seeded before identity was a thing.
        ['identity:backfill-operators', 'Central identity backfill'],

        // Refreshes the platform_stats singleton that feeds the
        // marketing kobo counter. Two SUM aggregates over the
        // payments table with TenantScope bypassed; --quiet-if-fresh
        // is a no-op when the row was already touched in the last
        // 5 minutes.
        ['keverna:recompute-platform-stats --quiet-if-fresh', 'Platform stats refresh'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->line('Keverna deploy bootstrap');
        $this->line(str_repeat('─', 40));

        foreach ($this->chain as [$signature, $label]) {
            $this->line(sprintf(' %s  %s', $dry ? '·' : '→', $label));
            $this->line(sprintf('    %s', $signature));

            if ($dry) continue;

            // Split signature into [name, ...options] so we can pass
            // through --flags safely without shell parsing.
            $parts = preg_split('/\s+/', $signature) ?: [];
            $name = array_shift($parts) ?? '';
            $args = [];
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    $args[$part] = true;
                }
            }

            $status = $this->call($name, $args);
            if ($status !== self::SUCCESS) {
                $this->error(sprintf('    Command "%s" exited with status %d, aborting bootstrap.', $name, $status));
                return $status;
            }
        }

        $this->line(str_repeat('─', 40));
        $this->info($dry ? 'Dry run complete.' : 'Bootstrap complete.');
        return self::SUCCESS;
    }
}
