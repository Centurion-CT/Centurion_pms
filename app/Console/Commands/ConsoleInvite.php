<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConsoleRole;
use App\Models\ConsoleUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * Recovery for whoever runs the servers (docs/MULTITENANCY.md, step 7):
 * gives a console user a link to set a password, creating them first if
 * needed. For when every owner is locked out; day to day, owners invite
 * people from the console's Users page.
 */
class ConsoleInvite extends Command
{
    /** @var string */
    protected $signature = 'console:invite
                            {email : The console user\'s email}
                            {--name= : Their name, when creating them}
                            {--owner : Make them an owner}';

    /** @var string */
    protected $description = 'Print a link that lets a console user set a password (creates them if needed)';

    public function handle(): int
    {
        if (! Route::has('console.invite')) {
            $this->components->error('The console only runs with TENANCY_MODE=domains.');

            return self::FAILURE;
        }

        $email = ConsoleUser::normaliseEmail((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('That is not an email address.');

            return self::FAILURE;
        }

        $user = ConsoleUser::query()->firstOrCreate(['email' => $email], [
            'name' => (string) ($this->option('name') ?: strstr($email, '@', true)),
            'role' => ConsoleRole::Admin,
        ]);
        if ($this->option('owner')) {
            $user->forceFill(['role' => ConsoleRole::Owner])->save();
        }

        $link = route('console.invite', ['token' => $user->issueInvite()]);
        $this->components->info("{$user->name} ({$user->role->label()}): send them this link. It works for ".ConsoleUser::INVITE_DAYS.' days.');
        $this->line($link);

        return self::SUCCESS;
    }
}
