<?php

declare(strict_types=1);

namespace App\Console\Commands\Domains;

use App\Actions\Domains\CheckOwnDomain;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Hourly live check of every verified own domain (CheckOwnDomain). Only
 * scheduled in `domains` mode: in `single` mode the request never reaches
 * the business by its own domain alone, so a miss would wrongly move its
 * links off the domain.
 */
class CheckOwnDomains extends Command
{
    /** @var string */
    protected $signature = 'domains:check-own';

    /** @var string */
    protected $description = "Check each business's own domain still answers for it over HTTPS";

    public function handle(CheckOwnDomain $check): int
    {
        $live = 0;
        $missed = 0;

        Tenant::query()
            ->whereNotNull('custom_domain')
            ->whereNotNull('domain_verified_at')
            ->where('status', '!=', TenantStatus::Archived->value)
            ->each(function (Tenant $tenant) use ($check, &$live, &$missed): void {
                $check->handle($tenant) ? $live++ : $missed++;
            });

        $this->info("Own domains: {$live} answering, {$missed} not answering.");

        return self::SUCCESS;
    }
}
