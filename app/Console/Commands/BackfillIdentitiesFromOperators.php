<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IdentityAccount;
use App\Models\IdentityOperatorLink;
use App\Models\Operator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill for Phase 1 of the unified-login spec
 * (docs/unified-login.md §3). Creates an IdentityAccount for every
 * Operator and links them via identity_operator_links.
 *
 * Idempotent: re-running skips operators whose link already exists.
 *
 * Collision handling: today `operators.email` is globally unique, so
 * cross-tenant email collisions cannot occur — but the guard is here
 * for the case where two Operator rows on different tenants somehow
 * share an email (e.g. legacy import). Colliding rows go to
 * identity_backfill_conflicts for manual resolution and are NOT
 * auto-linked.
 *
 * Usage:
 *   php artisan identity:backfill-operators
 *   php artisan identity:backfill-operators --tenant=42
 *   php artisan identity:backfill-operators --dry-run
 */
class BackfillIdentitiesFromOperators extends Command
{
    protected $signature = 'identity:backfill-operators
                            {--tenant= : limit to a single tenant id}
                            {--dry-run : plan only, no writes}';

    protected $description = 'Create central identity_accounts for every Operator and link them per tenant.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantFilter = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        // Bypass BelongsToTenant so we can walk every Operator regardless
        // of the active tenant context.
        $query = Operator::query()->withoutGlobalScopes()->orderBy('id');
        if ($tenantFilter !== null) {
            $query->where('tenant_id', $tenantFilter);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No operators to backfill.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Processing {$total} operator(s)…");

        $stats = [
            'identities_created' => 0,
            'links_created' => 0,
            'skipped_already_linked' => 0,
            'conflicts_logged' => 0,
        ];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(200, function ($operators) use (&$stats, $dryRun, $bar): void {
            foreach ($operators as $operator) {
                $this->processOperator($operator, $stats, $dryRun);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->line('Result:');
        foreach ($stats as $key => $count) {
            $this->line(sprintf('  %-24s %d', str_replace('_', ' ', $key), $count));
        }

        if ($stats['conflicts_logged'] > 0) {
            $this->warn('Conflicts written to identity_backfill_conflicts — resolve before flipping the central login on.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function processOperator(Operator $operator, array &$stats, bool $dryRun): void
    {
        $email = IdentityAccount::normaliseEmail((string) $operator->email);

        DB::transaction(function () use ($operator, $email, &$stats, $dryRun): void {
            $identity = IdentityAccount::query()->where('email', $email)->first();

            // Existing link short-circuits: this operator is already
            // represented. Re-runs land here for every already-processed row.
            $existingLink = $identity !== null
                ? IdentityOperatorLink::query()
                    ->where('identity_account_id', $identity->id)
                    ->where('tenant_id', $operator->tenant_id)
                    ->first()
                : null;

            if ($existingLink !== null && (int) $existingLink->operator_id === (int) $operator->id) {
                $stats['skipped_already_linked']++;

                return;
            }

            // Cross-tenant email collision: an identity exists but is
            // linked to a *different* operator on a *different* tenant.
            // We do NOT auto-link, because we can't know whether both
            // seats belong to the same human. Log and continue.
            if (
                $identity !== null
                && $identity->operatorLinks()->where('tenant_id', '!=', $operator->tenant_id)->exists()
                && $existingLink === null
            ) {
                $this->logConflict($operator, $identity, $dryRun);
                $stats['conflicts_logged']++;

                return;
            }

            if ($dryRun) {
                if ($identity === null) {
                    $stats['identities_created']++;
                }
                $stats['links_created']++;

                return;
            }

            if ($identity === null) {
                $identity = new IdentityAccount;
                $identity->email = $email;
                // Copy the bcrypt hash verbatim — the cast is 'hashed',
                // which no-ops when the value already looks hashed, so
                // there's no double-encoding.
                $identity->password = $operator->password;
                $identity->email_verified_at = $operator->email_verified_at;
                $identity->name = $operator->name;
                $identity->phone = $operator->phone;
                $identity->last_login_at = $operator->last_login_at;
                $identity->save();
                $stats['identities_created']++;
            }

            IdentityOperatorLink::create([
                'identity_account_id' => $identity->id,
                'tenant_id' => $operator->tenant_id,
                'operator_id' => $operator->id,
                'last_login_at' => $operator->last_login_at,
            ]);
            $stats['links_created']++;
        });
    }

    private function logConflict(Operator $operator, IdentityAccount $identity, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        DB::table('identity_backfill_conflicts')->insert([
            'email' => $identity->email,
            'conflicting_source_ids' => json_encode([
                'existing_identity_id' => $identity->id,
                'unlinked_operator_id' => $operator->id,
                'unlinked_tenant_id' => $operator->tenant_id,
            ]),
            'source_kind' => 'operator',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
